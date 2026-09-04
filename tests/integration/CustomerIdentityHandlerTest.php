<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\CustomerIdentityHandler;
use RainLab\User\Models\User;

/**
 * The identity Metapixel's user_data.resolve hook receives for this shop's
 * visitors, through the REAL plugin boot: a logged-in account, a guest with
 * checkout data on the cart, and nothing at all for an anonymous visitor.
 */
class CustomerIdentityHandlerTest extends StoreExtenderUserPluginTestCase
{
    const IDENTITY_KEYS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id'];

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
        config(['app.url' => 'https://nailscosmetics.lv']);

        Schema::create('lovata_orders_shopaholic_user_addresses', function ($obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id');
            $obTable->string('type')->default('shipping');
            $obTable->string('country')->nullable();
            $obTable->string('city')->nullable();
            $obTable->string('postcode')->nullable();
        });
        Schema::create('lovata_orders_shopaholic_carts', function ($obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->text('user_data')->nullable();
        });
    }

    public function tearDown(): void
    {
        Schema::dropIfExists('lovata_orders_shopaholic_user_addresses');
        Schema::dropIfExists('lovata_orders_shopaholic_carts');

        parent::tearDown();
    }

    public function testAnonymousVisitorSuppliesNothing()
    {
        $arUserData = $this->fireHook();

        $this->assertSame(array_fill_keys(self::IDENTITY_KEYS, null), $arUserData);
    }

    public function testLoggedInAccountSuppliesEmailPhoneNameAndUserId()
    {
        $obUser = $this->loginUser(['phone' => '26 111-222']);

        $arUserData = $this->fireHook();

        $this->assertSame('identity@nc.test', $arUserData['em']);
        $this->assertSame('37126111222', $arUserData['ph'], 'a national number gets the shop dial code');
        $this->assertSame('Anna', $arUserData['fn']);
        $this->assertSame('Bērziņa', $arUserData['ln']);
        $this->assertSame((string) $obUser->id, $arUserData['external_id']);
        $this->assertNull($arUserData['ct']);
        $this->assertNull($arUserData['zp']);
        $this->assertNull($arUserData['country'], 'the free-text address country is never sent');
    }

    public function testPhoneWithCountryCodeIsNotPrefixedTwice()
    {
        $this->loginUser(['phone' => '+371 26111222,+371 20000000']);

        $this->assertSame('37126111222', $this->fireHook()['ph']);
    }

    public function testNationalPhoneIsDroppedWhenTheShopDomainHasNoDialCode()
    {
        config(['app.url' => 'http://nc.test']);
        $this->loginUser(['phone' => '26111222']);

        $this->assertNull($this->fireHook()['ph']);
    }

    public function testLatestAddressWithCityOrPostcodeSuppliesCityAndPostcode()
    {
        $obUser = $this->loginUser();
        DB::table('lovata_orders_shopaholic_user_addresses')->insert([
            ['user_id' => $obUser->id, 'city' => 'Liepāja', 'postcode' => 'LV-3401'],
            ['user_id' => $obUser->id, 'city' => 'Rīga', 'postcode' => 'LV-1010'],
            ['user_id' => $obUser->id, 'city' => '', 'postcode' => null],
        ]);

        $arUserData = $this->fireHook();

        $this->assertSame('Rīga', $arUserData['ct']);
        $this->assertSame('LV-1010', $arUserData['zp']);
    }

    public function testValuesAlreadyPresentAreKept()
    {
        $this->loginUser();

        $arUserData = $this->fireHook(['em' => 'order@nc.test']);

        $this->assertSame('order@nc.test', $arUserData['em']);
        $this->assertSame('Anna', $arUserData['fn']);
    }

    public function testGuestCartCheckoutDataSuppliesEmailAndPhone()
    {
        DB::table('lovata_orders_shopaholic_carts')->insert([
            'id'        => 7,
            'user_data' => json_encode(['email' => 'guest@nc.test', 'phone' => '+371 20000000']),
        ]);
        request()->cookies->set('shopaholic_cart_id', '7');

        $arUserData = $this->fireHook();

        $this->assertSame('guest@nc.test', $arUserData['em']);
        $this->assertSame('37120000000', $arUserData['ph']);
        $this->assertNull($arUserData['external_id']);
    }

    public function testGuestCartWithoutCheckoutDataSuppliesNothing()
    {
        DB::table('lovata_orders_shopaholic_carts')->insert(['id' => 8, 'user_data' => null]);
        request()->cookies->set('shopaholic_cart_id', '8');

        $this->assertSame(array_fill_keys(self::IDENTITY_KEYS, null), $this->fireHook());
    }

    /**
     * @param array $arPreset keys an adapter or earlier listener already filled
     * @return array
     */
    protected function fireHook(array $arPreset = []): array
    {
        $arUserData = array_merge(array_fill_keys(self::IDENTITY_KEYS, null), $arPreset);
        $arContext = ['event_name' => 'ViewContent', 'subject_type' => 'shopaholic.product'];

        Event::fire(CustomerIdentityHandler::HOOK_USER_DATA_RESOLVE, [&$arUserData, $arContext]);

        return $arUserData;
    }

    /**
     * @param array $arOverride
     * @return User
     */
    protected function loginUser(array $arOverride = []): User
    {
        $obUser = User::create(array_merge([
            'email'                 => 'identity@nc.test',
            'first_name'            => 'Anna',
            'last_name'             => 'Bērziņa',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ], $arOverride));
        Auth::login($obUser);

        return $obUser;
    }
}
