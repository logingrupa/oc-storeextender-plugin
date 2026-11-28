<?php

declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/CheckoutStubTables.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as LaravelEvent;
use Kharanenka\Helper\Result;
use October\Rain\Support\Facades\Event;
use Lovata\OrdersShopaholic\Classes\Item\CartPositionItem;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OfferCartPositionProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Helper\TaxHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Toolbox\Classes\Item\ItemStorage;

/**
 * Covers OrderStockLockHandler:
 * - Unconditional offer + parent-product locking (even when both check & decrement settings are OFF)
 * - Stale CartPositionItem / OfferItem eviction so two 1-unit orders decrement 2 -> 0 accurately
 * - Withdrawn (soft-deleted or deactivated) offer OR parent product rejected cleanly under lock
 * - Duplicate cart lines for the same offer aggregated for online-gateway veto and bidirectional out_of_stock
 * - Lock-wait exception caught with Result::setFalse() returning null so DB::transactionLevel() returns to 0
 */
class OrderStockLockHandlerTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var array<int, mixed> */
    private array $arQueryWithBackup = [];

    public function setUp(): void
    {
        parent::setUp();

        Settings::clearInternalCache();
        CheckoutStubTables::create();
        CheckoutStubTables::seed();
        DB::table('lovata_orders_shopaholic_payment_methods')->insert([
            'id' => 2,
            'active' => 1,
            'name' => 'Paysera Online',
            'code' => 'paysera',
            'gateway_id' => 'paysera',
            'send_purchase_request' => 1,
            'sort_order' => 2,
        ]);
        $this->arQueryWithBackup = [OfferItem::$arQueryWith, ProductItem::$arQueryWith];
        OfferItem::$arQueryWith = [];
        ProductItem::$arQueryWith = [];
        CurrencyHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();
        TaxHelper::forgetInstance();
        CartProcessor::forgetInstance();
        Result::setTrue();
        Event::listen('mailer.beforeSend', static fn () => false);
    }

    public function tearDown(): void
    {
        if (count($this->arQueryWithBackup) === 2) {
            OfferItem::$arQueryWith = $this->arQueryWithBackup[0];
            ProductItem::$arQueryWith = $this->arQueryWithBackup[1];
        }
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        CurrencyHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();
        TaxHelper::forgetInstance();
        Settings::clearInternalCache();
        Result::setTrue();

        parent::tearDown();
    }

    public function testTwoSequentialOneUnitOrdersDecrementTwoUnitsToZeroEvenWithWarmPreLockCache(): void
    {
        Settings::set('check_offer_quantity', '1');
        Settings::set('decrement_offer_quantity', '1');
        Settings::set('allow_negative_offer_quantity', '0');

        $iOfferId = CheckoutStubTables::OFFER_ID;
        DB::table('lovata_shopaholic_offers')->where('id', $iOfferId)->update(['quantity' => 2]);

        // First 1-unit order commits: stock 2 -> 1.
        $obOrder1 = $this->placeOrder([$iOfferId => 1], CheckoutStubTables::SHIPPING_TYPE_ID, CheckoutStubTables::PAYMENT_METHOD_ID);
        $this->assertInstanceOf(Order::class, $obOrder1);
        $this->assertSame(1, (int) Offer::findOrFail($iOfferId)->quantity);

        // Poison ItemStorage with a stale pre-lock quantity=2 OfferItem before the 2nd order.
        $obStaleOffer = Offer::findOrFail($iOfferId);
        $obStaleOffer->quantity = 2;
        ItemStorage::set(OfferItem::class, $iOfferId, OfferItem::makeNoCache($iOfferId, $obStaleOffer));

        // Second 1-unit order must evict CartPositionItem + inject locked DB row (qty=1) and decrement 1 -> 0.
        $obOrder2 = $this->placeOrder([$iOfferId => 1], CheckoutStubTables::SHIPPING_TYPE_ID, CheckoutStubTables::PAYMENT_METHOD_ID);
        $this->assertInstanceOf(Order::class, $obOrder2);
        $this->assertSame(0, (int) Offer::findOrFail($iOfferId)->quantity);

        // Third 1-unit order is rejected under hard stock check with clean rollback.
        $obOrder3 = $this->placeOrder([$iOfferId => 1], CheckoutStubTables::SHIPPING_TYPE_ID, CheckoutStubTables::PAYMENT_METHOD_ID);
        $this->assertNull($obOrder3);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testWithdrawnOfferOrInactiveParentProductIsRejectedUnderLock(): void
    {
        Settings::set('check_offer_quantity', '1');
        Settings::set('decrement_offer_quantity', '1');

        $iProductId = CheckoutStubTables::PRODUCT_ID;
        $iOfferId = CheckoutStubTables::OFFER_ID;
        DB::table('lovata_shopaholic_offers')->where('id', $iOfferId)->update(['quantity' => 5]);

        // Deactivate parent product right before lock runs (simulating race after initCartPositionList).
        Event::listen(OrderProcessor::EVENT_UPDATE_ORDER_BEFORE_CREATE, static function () use ($iProductId): void {
            DB::table('lovata_shopaholic_products')->where('id', $iProductId)->update(['active' => 0]);
        }, 20);

        $obOrder = $this->placeOrder([$iOfferId => 1], CheckoutStubTables::SHIPPING_TYPE_ID, CheckoutStubTables::PAYMENT_METHOD_ID);
        $this->assertNull($obOrder);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, Order::query()->count());
    }

    public function testBothStockSettingsOffStillEnforcesAggregateOnlineGatewayVetoAndBidirectionalOutOfStock(): void
    {
        Settings::set('check_offer_quantity', '0');
        Settings::set('decrement_offer_quantity', '0');

        $iOfferId = CheckoutStubTables::OFFER_ID;
        DB::table('lovata_shopaholic_offers')->where('id', $iOfferId)->update(['quantity' => 3]);

        // Two separate cart positions for the SAME offer (2 + 2 = 4 > 3 stock).
        $iCartId = $this->createCartWithDuplicateLines($iOfferId, [2, 2]);

        // 1. Online payment method must be rejected under the lock because aggregate 4 > 3!
        CartProcessor::$iTestCartID = $iCartId;
        CartProcessor::forgetInstance();
        Result::setTrue();
        $obRejected = OrderProcessor::instance()->create([
            'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID,
            'payment_method_id' => 2,
        ]);
        $this->assertNull($obRejected);
        $this->assertSame(0, DB::transactionLevel());

        // 2. Invoice payment method succeeds and stamps out_of_stock = '1' inside transaction.
        CartProcessor::$iTestCartID = $iCartId;
        CartProcessor::forgetInstance();
        Result::setTrue();
        $obOverstockOrder = OrderProcessor::instance()->create([
            'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID,
            'payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID,
        ]);
        $this->assertInstanceOf(Order::class, $obOverstockOrder);
        $this->assertSame('1', Order::findOrFail($obOverstockOrder->id)->property['out_of_stock'] ?? null);

        // 3. Replenishment before lock corrects pre-stamped out_of_stock '1' -> '0' inside transaction.
        $iCartId2 = $this->createCartWithDuplicateLines($iOfferId, [2, 2]);
        Event::listen(OrderProcessor::EVENT_UPDATE_ORDER_BEFORE_CREATE, static function () use ($iOfferId): void {
            DB::table('lovata_shopaholic_offers')->where('id', $iOfferId)->update(['quantity' => 10]);
        }, 20);

        CartProcessor::$iTestCartID = $iCartId2;
        CartProcessor::forgetInstance();
        Result::setTrue();
        $obReplenished = OrderProcessor::instance()->create([
            'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID,
            'payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID,
            'property' => ['out_of_stock' => '1'],
        ]);
        $this->assertInstanceOf(Order::class, $obReplenished);
        $this->assertSame('0', Order::findOrFail($obReplenished->id)->property['out_of_stock'] ?? null);
    }

    public function testBrowserCheckoutRejectsWhenOneCartLineIsWithdrawnBeforeTransaction(): void
    {
        $iOfferA = CheckoutStubTables::OFFER_ID;
        $iOfferB = (int) DB::table('lovata_shopaholic_offers')->insertGetId([
            'active' => 1,
            'product_id' => CheckoutStubTables::PRODUCT_ID,
            'name' => 'Second Shade',
            'quantity' => 10,
        ]);
        DB::table('lovata_shopaholic_prices')->insert([
            'item_id' => $iOfferB,
            'item_type' => Offer::class,
            'price_type_id' => null,
            'price' => 5.00,
            'old_price' => 0,
        ]);

        $iCartId = (int) DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => null]);
        CartProcessor::$iTestCartID = $iCartId;
        CartProcessor::forgetInstance();
        CartProcessor::instance()->add([
            ['offer_id' => $iOfferA, 'quantity' => 1],
            ['offer_id' => $iOfferB, 'quantity' => 1],
        ], OfferCartPositionProcessor::class);

        // Withdraw offer B BEFORE OrderProcessor::create() so Shopaholic's initCartPositionList()
        // excludes it from the in-memory collection while offer A remains valid.
        DB::table('lovata_shopaholic_offers')->where('id', $iOfferB)->update(['active' => 0]);
        OfferItem::clearCache($iOfferB);
        ItemStorage::clear(OfferItem::class, $iOfferB);
        CartProcessor::forgetInstance();

        Result::setTrue();
        $obResult = OrderProcessor::instance()->create([
            'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID,
            'payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID,
        ]);

        $this->assertNull($obResult);
        $this->assertFalse(Result::status());
        $this->assertSame(0, Order::count());
    }

    /**
     * @param  array<int, int>  $arOfferQuantities
     */
    private function placeOrder(array $arOfferQuantities, int $iShippingId, int $iPaymentMethodId): ?Order
    {
        $iCartId = (int) DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => null]);
        CartProcessor::$iTestCartID = $iCartId;
        CartProcessor::forgetInstance();

        $arLines = [];
        foreach ($arOfferQuantities as $iOfferId => $iQty) {
            $arLines[] = ['offer_id' => $iOfferId, 'quantity' => $iQty];
        }
        CartProcessor::instance()->add($arLines, OfferCartPositionProcessor::class);

        Result::setTrue();

        return OrderProcessor::instance()->create([
            'shipping_type_id' => $iShippingId,
            'payment_method_id' => $iPaymentMethodId,
        ]);
    }

    /**
     * @param  list<int>  $arQuantities
     */
    private function createCartWithDuplicateLines(int $iOfferId, array $arQuantities): int
    {
        $iCartId = (int) DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => null]);
        foreach ($arQuantities as $iQty) {
            $iPosId = (int) DB::table('lovata_orders_shopaholic_cart_positions')->insertGetId([
                'cart_id' => $iCartId,
                'item_id' => $iOfferId,
                'item_type' => Offer::class,
                'quantity' => $iQty,
            ]);
            ItemStorage::clear(CartPositionItem::class, $iPosId);
        }

        return $iCartId;
    }
}
