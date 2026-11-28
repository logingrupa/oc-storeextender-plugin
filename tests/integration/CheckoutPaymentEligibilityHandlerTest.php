<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/CheckoutStubTables.php';

use Illuminate\Support\Facades\DB;
use Kharanenka\Helper\Result;
use Logingrupa\StoreExtender\Classes\Helper\OrderEligibilityValidator;
use Logingrupa\StoreExtender\Classes\Helper\OrderStatusCode;
use Logingrupa\StoreExtender\Classes\Helper\UserAddressHelper;
use Lovata\OrdersShopaholic\Classes\Item\CartPositionItem;
use Lovata\OrdersShopaholic\Classes\Item\PaymentMethodItem;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Classes\Restriction\PaymentRestrictionByShippingType;
use Lovata\OrdersShopaholic\Classes\Store\PaymentMethodListStore;
use Lovata\OrdersShopaholic\Classes\Store\ShippingTypeListStore;
use Lovata\OrdersShopaholic\Components\MakeOrder;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\TaxHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use RainLab\User\Models\Setting;
use RainLab\User\Models\User;

class CheckoutPaymentEligibilityHandlerTest extends StoreExtenderUserPluginTestCase
{
    const ORDERS_TABLE = 'lovata_orders_shopaholic_orders';

    const ONLINE_METHOD_ID = 4;

    const OFFLINE_METHOD_ID = 3;

    const SECOND_SHIPPING_ID = 2;

    /** @var array */
    protected $arQueryWithList = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        Settings::clearInternalCache();
        Setting::clearInternalCache();
        CheckoutStubTables::create();
        CheckoutStubTables::seed();

        DB::table('lovata_orders_shopaholic_payment_methods')->insert([
            [
                'id'         => self::OFFLINE_METHOD_ID,
                'active'     => 1,
                'name'       => 'Bank transfer',
                'code'       => 'bank',
                'gateway_id' => '',
            ],
            [
                'id'         => self::ONLINE_METHOD_ID,
                'active'     => 1,
                'name'       => 'Paysera',
                'code'       => 'paysera',
                'gateway_id' => 'PayseraCheckout',
            ],
        ]);
        DB::table('lovata_orders_shopaholic_shipping_types')->insert([
            'id'     => self::SECOND_SHIPPING_ID,
            'active' => 1,
            'name'   => 'Courier',
            'code'   => 'courier',
            'price'  => 5,
        ]);
        DB::table('lovata_orders_shopaholic_statuses')->insert([
            'id'         => 9,
            'name'       => 'Canceled',
            'code'       => OrderStatusCode::CANCELED,
            'sort_order' => 9,
        ]);

        $this->arQueryWithList = [OfferItem::$arQueryWith, ProductItem::$arQueryWith];
        OfferItem::$arQueryWith = [];
        ProductItem::$arQueryWith = [];
        Settings::set('create_new_user', '1');
        Settings::set('check_offer_quantity', '0');
        PaymentMethodItem::clearCache(self::OFFLINE_METHOD_ID);
        PaymentMethodItem::clearCache(self::ONLINE_METHOD_ID);
        ShippingTypeItem::clearCache(CheckoutStubTables::SHIPPING_TYPE_ID);
        ShippingTypeItem::clearCache(self::SECOND_SHIPPING_ID);
        PaymentMethodListStore::instance()->active->clear();
        ShippingTypeListStore::instance()->active->clear();
        if (class_exists(\Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility::class)) {
            \Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility::reset();
        }
        Result::setTrue();
    }

    public function tearDown(): void
    {
        [OfferItem::$arQueryWith, ProductItem::$arQueryWith] = $this->arQueryWithList;
        $this->forgetCheckoutState();
        CartPositionItem::clearCache(1);
        OfferItem::clearCache(CheckoutStubTables::OFFER_ID);
        PaymentMethodItem::clearCache(CheckoutStubTables::PAYMENT_METHOD_ID);
        PaymentMethodItem::clearCache(self::OFFLINE_METHOD_ID);
        PaymentMethodItem::clearCache(self::ONLINE_METHOD_ID);
        ShippingTypeItem::clearCache(CheckoutStubTables::SHIPPING_TYPE_ID);
        ShippingTypeItem::clearCache(self::SECOND_SHIPPING_ID);
        PaymentMethodListStore::instance()->active->clear();
        ShippingTypeListStore::instance()->active->clear();
        if (class_exists(\Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility::class)) {
            \Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility::reset();
        }
        Settings::clearInternalCache();
        Setting::clearInternalCache();
        Result::setTrue();

        parent::tearDown();
    }

    public function testOnlineMethodIsRejectedBeforeUserOrOrderCreationOnOverstockCart(): void
    {
        $this->setOfferStock(1);

        $arResult = $this->submitCheckout(
            ['payment_method_id' => self::ONLINE_METHOD_ID, 'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID],
            ['email' => 'blocked@example.com', 'name' => 'Anna', 'password' => ''],
            2
        );

        $this->assertFalse((bool) ($arResult['status'] ?? true));
        $this->assertSame(0, User::where('email', 'blocked@example.com')->count());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testOfflineMethodSucceedsOnOverstockAndStampsAuthoritativeStockFlagDespiteUserConflict(): void
    {
        $this->setOfferStock(1);

        $this->submitCheckout(
            [
                'payment_method_id' => self::OFFLINE_METHOD_ID,
                'shipping_type_id'  => CheckoutStubTables::SHIPPING_TYPE_ID,
                'property'          => ['out_of_stock' => '0'],
            ],
            [
                'email'        => 'offline@example.com',
                'name'         => 'Anna',
                'password'     => '',
                'out_of_stock' => '0',
                'property'     => ['out_of_stock' => '0'],
            ],
            2
        );

        $this->assertTrue(Result::status(), (string) Result::message());
        $arProperty = json_decode((string) DB::table(self::ORDERS_TABLE)->value('property'), true);
        $this->assertSame('1', (string) ($arProperty['out_of_stock'] ?? ''));
    }

    public function testMissingPaymentMethodIsRejectedBeforeUserOrOrderCreation(): void
    {
        $arResult = $this->submitCheckout(
            ['shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID],
            ['email' => 'nopay@example.com', 'name' => 'Anna', 'password' => ''],
            1
        );

        $this->assertFalse((bool) ($arResult['status'] ?? true));
        $this->assertSame(0, User::where('email', 'nopay@example.com')->count());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testMissingShippingTypeIsRejectedBeforeUserOrOrderCreation(): void
    {
        $arResult = $this->submitCheckout(
            ['payment_method_id' => self::OFFLINE_METHOD_ID],
            ['email' => 'noship@example.com', 'name' => 'Anna', 'password' => ''],
            1
        );

        $this->assertFalse((bool) ($arResult['status'] ?? true));
        $this->assertSame(0, User::where('email', 'noship@example.com')->count());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testHiddenPayseraTestMethodIsRejectedWhenExcludedFromVisibleCollection(): void
    {
        if (!class_exists(\Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility::class)) {
            $this->markTestSkipped('PayseraShopaholic plugin not installed.');
        }

        app()->bind(
            \Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection::class,
            \Logingrupa\PayseraShopaholic\Classes\Collection\VisiblePaymentMethodCollection::class
        );

        $obMethod = \Lovata\OrdersShopaholic\Models\PaymentMethod::find(self::ONLINE_METHOD_ID);
        $obMethod->gateway_id = \Logingrupa\PayseraShopaholic\Classes\Helper\PayseraPaymentGateway::CODE;
        $obMethod->gateway_property = ['test_mode' => 1];
        $obMethod->save();
        PaymentMethodItem::clearCache(self::ONLINE_METHOD_ID);
        PaymentMethodListStore::instance()->active->clear();
        \Logingrupa\PayseraShopaholic\Classes\Helper\TestModeVisibility::reset();

        $arResult = $this->submitCheckout(
            ['payment_method_id' => self::ONLINE_METHOD_ID, 'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID],
            ['email' => 'hiddenpay@example.com', 'name' => 'Anna', 'password' => ''],
            1
        );

        $this->assertFalse((bool) ($arResult['status'] ?? true));
        $this->assertSame(0, User::where('email', 'hiddenpay@example.com')->count());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testIsDynamicPostNordShippingRoutesOnApiClassNotCode(): void
    {
        DB::table('lovata_orders_shopaholic_shipping_types')->insert([
            [
                'id'        => 31,
                'active'    => 1,
                'name'      => 'Renamed Nordic Carrier Dynamic',
                'code'      => 'nordic-parcel',
                'api_class' => OrderEligibilityValidator::POSTNORD_API_CLASS,
                'property'  => json_encode(['postnord_dynamic_pricing' => true, 'postnord_api_mode' => 'norway']),
                'price'     => 0,
            ],
            [
                'id'        => 32,
                'active'    => 1,
                'name'      => 'PostNord Flat Legacy',
                'code'      => 'postnord-flat',
                'api_class' => null,
                'property'  => null,
                'price'     => 10,
            ],
            [
                'id'        => 33,
                'active'    => 1,
                'name'      => 'PostNord Flat Rate NO',
                'code'      => 'postnord-no-flat',
                'api_class' => OrderEligibilityValidator::POSTNORD_API_CLASS,
                'property'  => json_encode(['postnord_dynamic_pricing' => false]),
                'price'     => 10,
            ],
        ]);
        ShippingTypeItem::clearCache(31);
        ShippingTypeItem::clearCache(32);
        ShippingTypeItem::clearCache(33);

        $this->assertTrue(OrderEligibilityValidator::isDynamicPostNordShipping(31));
        $this->assertFalse(OrderEligibilityValidator::isDynamicPostNordShipping(32));
        $this->assertFalse(OrderEligibilityValidator::isDynamicPostNordShipping(33));

        ShippingTypeItem::clearCache(31);
        ShippingTypeItem::clearCache(32);
        ShippingTypeItem::clearCache(33);
    }

    public function testMethodAllowedForCartCarrierButRestrictedForSubmittedCarrierIsRejected(): void
    {
        $iRestrictionId = DB::table('lovata_ordersshopaholic_payment_restrictions')->insertGetId([
            'active'      => 1,
            'name'        => 'Only pickup',
            'restriction' => PaymentRestrictionByShippingType::class,
            'property'    => json_encode(['shipping_type' => [(string) CheckoutStubTables::SHIPPING_TYPE_ID]]),
        ]);
        DB::table('lovata_ordersshopaholic_payment_restrictions_link')->insert([
            'payment_method_id'      => self::OFFLINE_METHOD_ID,
            'payment_restriction_id' => $iRestrictionId,
        ]);
        PaymentMethodItem::clearCache(self::OFFLINE_METHOD_ID);

        $arResult = $this->submitCheckout(
            ['payment_method_id' => self::OFFLINE_METHOD_ID, 'shipping_type_id' => self::SECOND_SHIPPING_ID],
            ['email' => 'mismatch@example.com', 'name' => 'Anna', 'password' => ''],
            1,
            CheckoutStubTables::SHIPPING_TYPE_ID
        );

        $this->assertFalse((bool) ($arResult['status'] ?? true));
        $this->assertSame(0, User::where('email', 'mismatch@example.com')->count());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testHardStockCheckStillRejectsOverstockOfflineOrder(): void
    {
        Settings::set('check_offer_quantity', '1');
        $this->setOfferStock(1);

        $this->submitCheckout(
            ['payment_method_id' => self::OFFLINE_METHOD_ID, 'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID],
            ['email' => 'hardstock@example.com', 'name' => 'Anna', 'password' => ''],
            2
        );

        $this->assertFalse(Result::status());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testEmptyCartPassesThroughToShopaholicWithoutEligibilityInterception(): void
    {
        $this->forgetCheckoutState();
        $iCartId = DB::table('lovata_orders_shopaholic_carts')->insertGetId([]);
        CartProcessor::$iTestCartID = $iCartId;

        request()->replace([
            'order' => ['payment_method_id' => self::OFFLINE_METHOD_ID],
            'user'  => ['email' => 'empty@example.com', 'name' => 'Anna', 'password' => ''],
        ]);

        $obMakeOrder = new MakeOrder(null, ['mode' => 'ajax']);
        $obMakeOrder->init();
        $obMakeOrder->runAjaxHandler('onCreate');

        $this->assertFalse(Result::status());
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count());
    }

    public function testPaymentMethodItemCachesGatewayId(): void
    {
        $obOnline = PaymentMethodItem::make(self::ONLINE_METHOD_ID);
        $obOffline = PaymentMethodItem::make(self::OFFLINE_METHOD_ID);

        $this->assertSame('PayseraCheckout', (string) $obOnline->gateway_id);
        $this->assertSame('', (string) $obOffline->gateway_id);
    }

    public function testGetLastShippingTypeIdSkipsCanceledAndInactiveTypes(): void
    {
        $obUser = User::create([
            'email'                 => 'returning@example.com',
            'first_name'            => 'Anna',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);

        DB::table(self::ORDERS_TABLE)->insert([
            ['user_id' => $obUser->id, 'status_id' => CheckoutStubTables::STATUS_ID, 'shipping_type_id' => self::SECOND_SHIPPING_ID],
            ['user_id' => $obUser->id, 'status_id' => 9, 'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID],
        ]);

        $arActive = [
            (object) ['id' => CheckoutStubTables::SHIPPING_TYPE_ID],
            (object) ['id' => self::SECOND_SHIPPING_ID],
        ];

        $this->assertSame(self::SECOND_SHIPPING_ID, UserAddressHelper::getLastShippingTypeId($obUser, $arActive));
        $this->assertNull(UserAddressHelper::getLastShippingTypeId(null, $arActive));
        $this->assertNull(UserAddressHelper::getLastShippingTypeId($obUser, [(object) ['id' => CheckoutStubTables::SHIPPING_TYPE_ID]]));
    }

    protected function setOfferStock(int $iStock): void
    {
        DB::table('lovata_shopaholic_offers')
            ->where('id', CheckoutStubTables::OFFER_ID)
            ->update(['quantity' => $iStock]);
        OfferItem::clearCache(CheckoutStubTables::OFFER_ID);
    }

    /**
     * @param  array<string, mixed>  $arOrderData
     * @param  array<string, mixed>  $arUserData
     * @return mixed
     */
    protected function submitCheckout(array $arOrderData, array $arUserData, int $iQuantity, ?int $iCartShippingId = null)
    {
        $this->forgetCheckoutState();
        $iCartId = DB::table('lovata_orders_shopaholic_carts')->insertGetId([
            'shipping_type_id' => $iCartShippingId,
        ]);
        $iPositionId = DB::table('lovata_orders_shopaholic_cart_positions')->insertGetId([
            'cart_id'  => $iCartId,
            'item_id'  => CheckoutStubTables::OFFER_ID,
            'quantity' => $iQuantity,
        ]);
        CartPositionItem::clearCache($iPositionId);
        CartProcessor::$iTestCartID = $iCartId;
        if ($iCartShippingId !== null) {
            CartProcessor::instance()->setActiveShippingType(ShippingTypeItem::make($iCartShippingId));
        }

        request()->replace([
            'order' => $arOrderData,
            'user'  => $arUserData,
        ]);

        $obMakeOrder = new MakeOrder(null, ['mode' => 'ajax']);
        $obMakeOrder->init();

        return $obMakeOrder->runAjaxHandler('onCreate');
    }

    protected function forgetCheckoutState(): void
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
