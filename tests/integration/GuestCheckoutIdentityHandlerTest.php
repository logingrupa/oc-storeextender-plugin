<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Models\Settings as MetapixelSettings;
use Logingrupa\StoreExtender\Classes\Event\Cart\CartComponentHandler;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\GuestCheckoutIdentityHandler;
use Lovata\OrdersShopaholic\Components\Cart as CartComponent;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use RainLab\User\Models\User;

/**
 * The identity Metapixel's user_data.resolve hook receives for a guest whose
 * cart row holds the checkout fields, through the REAL plugin boot. Metapixel's
 * own account listener is not subscribed here, so an all-null result proves the
 * guest handler abstained. Also covers Cart.getSavedUserData() for the prefill.
 */
class GuestCheckoutIdentityHandlerTest extends StoreExtenderUserPluginTestCase
{
    const IDENTITY_KEYS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];

    const CHECKOUT_FIELDS = [
        'name'      => 'Anna',
        'last_name' => 'Bērziņa',
        'email'     => 'guest@nc.test',
        'phone'     => '26 111-222',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        Schema::create('lovata_orders_shopaholic_carts', function ($obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->text('user_data')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_orders_shopaholic_cart_positions', function ($obTable) {
            $obTable->increments('id');
            $obTable->integer('cart_id')->default(0);
            $obTable->integer('item_id')->default(0);
            $obTable->string('item_type')->default('Lovata\Shopaholic\Models\Offer');
            $obTable->integer('quantity')->default(0);
            $obTable->timestamps();
            $obTable->timestamp('deleted_at')->nullable();
        });

        MetapixelSettings::set(['account_identity_enabled' => true, 'account_phone_dial_code' => '371']);
    }

    public function tearDown(): void
    {
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        Schema::dropIfExists('lovata_orders_shopaholic_carts');
        Schema::dropIfExists('lovata_orders_shopaholic_cart_positions');

        parent::tearDown();
    }

    public function testGuestCartCheckoutDataSuppliesEmailNameAndPhone()
    {
        $this->insertCart(7, self::CHECKOUT_FIELDS);
        request()->cookies->set('shopaholic_cart_id', '7');

        $arUserData = $this->fireHook();

        $this->assertSame('guest@nc.test', $arUserData['em']);
        $this->assertSame('Anna', $arUserData['fn']);
        $this->assertSame('Bērziņa', $arUserData['ln']);
        $this->assertSame('37126111222', $arUserData['ph'], 'a national number gets the configured dial code');
        $this->assertNull($arUserData['external_id']);
        $this->assertNull($arUserData['country'], 'country is never supplied');
    }

    public function testGuestCartWithoutCheckoutDataSuppliesNothing()
    {
        $this->insertCart(8, null);
        request()->cookies->set('shopaholic_cart_id', '8');

        $this->assertSame($this->emptyUserData(), $this->fireHook());
    }

    public function testCookielessVisitorSuppliesNothing()
    {
        $this->assertSame($this->emptyUserData(), $this->fireHook());
    }

    public function testLoggedInAccountIsLeftToMetapixel()
    {
        $this->insertCart(7, self::CHECKOUT_FIELDS);
        request()->cookies->set('shopaholic_cart_id', '7');
        $this->loginUser();

        $this->assertSame($this->emptyUserData(), $this->fireHook());
    }

    public function testDisabledToggleSuppliesNothing()
    {
        MetapixelSettings::set(['account_identity_enabled' => false]);
        $this->insertCart(7, self::CHECKOUT_FIELDS);
        request()->cookies->set('shopaholic_cart_id', '7');

        $this->assertSame($this->emptyUserData(), $this->fireHook());
    }

    public function testValuesAlreadyPresentAreKept()
    {
        $this->insertCart(7, self::CHECKOUT_FIELDS);
        request()->cookies->set('shopaholic_cart_id', '7');

        $arUserData = $this->fireHook(['em' => 'order@nc.test']);

        $this->assertSame('order@nc.test', $arUserData['em']);
        $this->assertSame('Anna', $arUserData['fn']);
    }

    public function testSavedUserDataKeepsOnlyNonEmptyCheckoutStrings()
    {
        $arSaved = CartComponentHandler::savedUserData([
            'name'      => ' Anna ',
            'last_name' => '',
            'email'     => 'guest@nc.test',
            'phone'     => ['26111222'],
            'shipping'  => 'omniva',
        ]);

        $this->assertSame(['name' => 'Anna', 'email' => 'guest@nc.test'], $arSaved);
        $this->assertSame([], CartComponentHandler::savedUserData(null));
    }

    public function testCartComponentReturnsSavedUserDataFromTheCookieCart()
    {
        $this->insertCart(7, self::CHECKOUT_FIELDS);
        CartProcessor::$iTestCartID = 7;

        $arSaved = (new CartComponent())->getSavedUserData();

        $this->assertSame(self::CHECKOUT_FIELDS, $arSaved);
        $this->assertSame(1, DB::table('lovata_orders_shopaholic_carts')->count(), 'reads the cookie cart, mints none');
    }

    /**
     * @param int        $iCartId
     * @param array|null $arUserData
     * @return void
     */
    protected function insertCart(int $iCartId, ?array $arUserData)
    {
        DB::table('lovata_orders_shopaholic_carts')->insert([
            'id'        => $iCartId,
            'user_data' => $arUserData === null ? null : json_encode($arUserData),
        ]);
    }

    /**
     * @return array
     */
    protected function emptyUserData(): array
    {
        return array_fill_keys(self::IDENTITY_KEYS, null);
    }

    /**
     * @param array $arPreset keys an adapter or earlier listener already filled
     * @return array
     */
    protected function fireHook(array $arPreset = []): array
    {
        $arUserData = array_merge($this->emptyUserData(), $arPreset);
        $arContext = ['event_name' => 'ViewContent', 'subject_type' => 'shopaholic.product'];

        Event::fire(GuestCheckoutIdentityHandler::HOOK_USER_DATA_RESOLVE, [&$arUserData, $arContext]);

        return $arUserData;
    }

    /**
     * @return User
     */
    protected function loginUser(): User
    {
        $obUser = User::create([
            'email'                 => 'identity@nc.test',
            'first_name'            => 'Anna',
            'last_name'             => 'Bērziņa',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        Auth::login($obUser);

        return $obUser;
    }
}
