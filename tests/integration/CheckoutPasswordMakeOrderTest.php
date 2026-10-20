<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/CheckoutStubTables.php';

use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use RainLab\User\Models\User;
use RainLab\User\Models\Setting;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Shopaholic\Classes\Helper\TaxHelper;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Components\MakeOrder;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;

/**
 * Checkout through MakeOrder::onCreate, the way the theme posts it. A guest without a
 * typed password always gets the account and the order; a typed password, an existing
 * account and a logged-in shopper keep MakeOrder's own behaviour. Pins the hook against
 * the Lovata component itself, so a renamed input key or a changed createUser shows up.
 */
class CheckoutPasswordMakeOrderTest extends StoreExtenderUserPluginTestCase
{
    const ORDERS_TABLE = 'lovata_orders_shopaholic_orders';

    const RUN_COUNT = 50;

    const KNOWN_PASSWORD = 'Probe12345';

    /** @var array eager loads of plugins whose relations the hermetic schema lacks */
    protected $arQueryWithList = [];

    /**
     * Checkout tables with one offer in stock, catalog eager loads off, account creation on.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        // settings instances are static and outlive the earlier tests' apps
        Settings::clearInternalCache();
        Setting::clearInternalCache();
        CheckoutStubTables::create();
        CheckoutStubTables::seed();
        $this->arQueryWithList = [OfferItem::$arQueryWith, ProductItem::$arQueryWith];
        OfferItem::$arQueryWith = [];
        ProductItem::$arQueryWith = [];
        Settings::set('create_new_user', '1');
        Result::setTrue();
    }

    /**
     * Restore the eager loads and drop the request singletons and static settings.
     * @return void
     */
    public function tearDown(): void
    {
        [OfferItem::$arQueryWith, ProductItem::$arQueryWith] = $this->arQueryWithList;
        $this->forgetCheckoutState();
        Settings::clearInternalCache();
        Setting::clearInternalCache();
        Result::setTrue();

        parent::tearDown();
    }

    /**
     * str_random(8) missed the number rule about one run in four; fifty runs all pass.
     * @return void
     */
    public function testGuestWithoutTypedPasswordAlwaysGetsTheAccountAndTheOrder()
    {
        $this->assertEveryGuestRunPlacesTheOrder('guest');
    }

    /**
     * @return void
     */
    public function testStricterPolicyStillAlwaysPlacesTheOrder()
    {
        $obSetting = Setting::instance();
        $obSetting->password_require_mixed_case = true;
        $obSetting->password_require_symbol = true;
        $obSetting->password_require_number = true;

        $this->assertEveryGuestRunPlacesTheOrder('strict');
    }

    /**
     * The generated password never reaches the orders table.
     * @return void
     */
    public function testRawOrderPropertyHoldsNoPassword()
    {
        $this->submitCheckout(['email' => 'secret@example.com', 'name' => 'Anna', 'password' => '']);

        $this->assertTrue(Result::status(), (string) Result::message());
        $sProperty = (string) DB::table(self::ORDERS_TABLE)->value('property');
        $this->assertStringContainsString('secret@example.com', $sProperty);
        $this->assertStringNotContainsString('password', $sProperty);
    }

    /**
     * A weak typed password is still refused, in the shopper's language, before any write.
     * @return void
     */
    public function testWeakTypedPasswordIsRefusedInLatvian()
    {
        App::setLocale('lv');

        $this->submitCheckout(['email' => 'weak@example.com', 'name' => 'Anna', 'password' => 'abcdefgh']);

        $this->assertFalse(Result::status());
        $this->assertSame('Paroles laukā jābūt vismaz vienam ciparam.', Result::message());
        $this->assertSame('password', array_get((array) Result::data(), 'field'));
        $this->assertSame(0, User::where('email', 'weak@example.com')->count());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    /**
     * An existing account keeps its password; the order attaches to it.
     * @return void
     */
    public function testExistingAccountKeepsItsPassword()
    {
        $obUser = $this->createAccount('existing@example.com');

        $this->submitCheckout(['email' => 'existing@example.com', 'name' => 'Anna', 'password' => '']);

        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
        $this->assertTrue(Hash::check(self::KNOWN_PASSWORD, (string) $obUser->fresh()->password));
        $this->assertSame(1, DB::table(self::ORDERS_TABLE)->where('user_id', $obUser->id)->count());
    }

    /**
     * A logged-in shopper's account keeps its password.
     * @return void
     */
    public function testLoggedInShopperKeepsTheirPassword()
    {
        $obUser = $this->createAccount('member@example.com');

        $this->submitCheckout(['password' => ''], $obUser);

        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertTrue(Hash::check(self::KNOWN_PASSWORD, (string) $obUser->fresh()->password));
        $this->assertSame(1, DB::table(self::ORDERS_TABLE)->where('user_id', $obUser->id)->count());
    }

    /**
     * The fake email still comes from MakeOrder's own random string, never the merged password.
     * @return void
     */
    public function testFakeEmailIsUnaffected()
    {
        Settings::set('generate_fake_email', '1');

        $this->submitCheckout(['email' => '', 'name' => 'Anna', 'password' => '']);

        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertMatchesRegularExpression('/^fake[A-Za-z0-9]{8}@fake\.com$/', (string) User::query()->value('email'));
    }

    /**
     * @param string $sPrefix
     * @return void
     */
    protected function assertEveryGuestRunPlacesTheOrder($sPrefix)
    {
        for ($iRun = 1; $iRun <= self::RUN_COUNT; $iRun++) {
            $sEmail = $sPrefix.'-'.$iRun.'@example.com';

            $this->submitCheckout(['email' => $sEmail, 'name' => 'Anna', 'password' => '']);

            $this->assertTrue(Result::status(), 'run '.$iRun.': '.Result::message());
            $obUser = User::where('email', $sEmail)->first();
            $this->assertNotNull($obUser, 'run '.$iRun.' account');
            $this->assertFalse((bool) $obUser->is_guest, 'run '.$iRun.' registered, not a guest');
            $this->assertSame(1, DB::table(self::ORDERS_TABLE)->where('user_id', $obUser->id)->count(), 'run '.$iRun.' order');
        }
    }

    /**
     * @param string $sEmail
     * @return User registered account with the known password, logged out
     */
    protected function createAccount($sEmail)
    {
        return User::create([
            'email'                 => $sEmail,
            'first_name'            => 'Anna',
            'password'              => self::KNOWN_PASSWORD,
            'password_confirmation' => self::KNOWN_PASSWORD,
        ]);
    }

    /**
     * A fresh checkout request for a one-offer cart.
     * @param array     $arUserData
     * @param User|null $obLoginUser
     * @return mixed MakeOrder's AJAX answer
     */
    protected function submitCheckout($arUserData, $obLoginUser = null)
    {
        $this->forgetCheckoutState();
        if (!empty($obLoginUser)) {
            $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
            $sAuthFacadeClass::login($obLoginUser);
        }

        $iCartID = DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => $obLoginUser->id ?? null]);
        DB::table('lovata_orders_shopaholic_cart_positions')->insert(['cart_id' => $iCartID, 'item_id' => CheckoutStubTables::OFFER_ID, 'quantity' => 1]);
        CartProcessor::$iTestCartID = $iCartID;

        request()->replace([
            'order' => ['payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID],
            'user'  => $arUserData,
        ]);

        $obMakeOrder = new MakeOrder(null, ['mode' => 'ajax']);
        $obMakeOrder->init();

        return $obMakeOrder->runAjaxHandler('onCreate');
    }

    /**
     * Each submit is a fresh request: guest session, new cart, order and helper singletons.
     * @return void
     */
    protected function forgetCheckoutState()
    {
        $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
        $sAuthFacadeClass::logout();
        UserHelper::forgetInstance();
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        OrderProcessor::forgetInstance();
        TaxHelper::forgetInstance();
        CurrencyHelper::forgetInstance();
        Result::setTrue();
    }
}
