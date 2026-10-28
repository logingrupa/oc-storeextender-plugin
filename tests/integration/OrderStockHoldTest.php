<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/CheckoutStubTables.php';

use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RainLab\User\Models\Setting;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Shopaholic\Classes\Helper\TaxHelper;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Components\MakeOrder;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Logingrupa\RetrypaymentShopaholic\Classes\Store\RetryableStatusListStore;
use Logingrupa\StoreExtender\Classes\Helper\StockHoldPolicy;

/**
 * A checkout records the offer units it took; canceling the order gives them back,
 * reopening takes them again, a shipped order keeps them. Driven through MakeOrder::onCreate
 * and real Order saves, so the Lovata decrement and the status paths are the shop's own.
 * Row locks compile to nothing on SQLite: lock behaviour is not covered here.
 */
class OrderStockHoldTest extends StoreExtenderUserPluginTestCase
{
    const HOLDS_TABLE = 'logingrupa_storeextender_order_stock_holds';

    const OFFERS_TABLE = 'lovata_shopaholic_offers';

    const SECOND_OFFER_ID = 2;

    const STATUS_ID_MAP = [
        'in_progress' => 2,
        'complete' => 3,
        'canceled' => 4,
        'new-payment-received' => 5,
        'new-payment-canceled' => 6,
        'new-payment-error' => 7,
        'sent' => 8,
        'payment-pending' => 9,
    ];

    /** @var array eager loads of plugins whose relations the hermetic schema lacks */
    protected $arQueryWithList = [];

    /** @var int */
    protected $iSubmitCount = 0;

    /**
     * Checkout tables, two offers at 1000, every status the holds react to, the ledger,
     * stock decrement on with negative stock allowed (.no).
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        Settings::clearInternalCache();
        Setting::clearInternalCache();
        CheckoutStubTables::create();
        CheckoutStubTables::seed();
        foreach (self::STATUS_ID_MAP as $sCode => $iStatusID) {
            DB::table('lovata_orders_shopaholic_statuses')->insert(['id' => $iStatusID, 'name' => $sCode, 'code' => $sCode, 'sort_order' => $iStatusID]);
        }
        DB::table(self::OFFERS_TABLE)->insert(['id' => self::SECOND_OFFER_ID, 'active' => 1, 'product_id' => CheckoutStubTables::PRODUCT_ID, 'name' => 'Gel 15 ml', 'quantity' => 1000]);
        DB::table('lovata_shopaholic_prices')->insert(['item_id' => self::SECOND_OFFER_ID, 'item_type' => 'Lovata\Shopaholic\Models\Offer', 'price' => 20, 'price_type_id' => null]);

        $this->arQueryWithList = [OfferItem::$arQueryWith, ProductItem::$arQueryWith];
        OfferItem::$arQueryWith = [];
        ProductItem::$arQueryWith = [];
        Settings::set('create_new_user', '1');
        Settings::set('decrement_offer_quantity', '1');
        Settings::set('allow_negative_offer_quantity', '1');
        Settings::set('check_offer_quantity', '0');
        StockHoldPolicy::forget();
        RetryableStatusListStore::instance()->clear();
        Result::setTrue();

        // order mails are not under test
        Event::listen('mailer.beforeSend', fn () => false);
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        [OfferItem::$arQueryWith, ProductItem::$arQueryWith] = $this->arQueryWithList;
        $this->forgetCheckoutState();
        StockHoldPolicy::forget();
        // the singleton store outlives the app; later tests seed other status ids
        RetryableStatusListStore::instance()->clear();
        Settings::clearInternalCache();
        Setting::clearInternalCache();
        Result::setTrue();

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testCheckoutRecordsTheUnitsItTook()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);

        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 3, false]], $this->holds($iOrderID));
    }

    /**
     * The backend status field sets the id as a string; a re-save of the canceled order moves nothing more.
     * @return void
     */
    public function testBackendCancelGivesTheUnitsBackOnce()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);

        $this->saveStatus($iOrderID, 'canceled');
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 3, true]], $this->holds($iOrderID));

        $this->saveStatus($iOrderID, 'canceled');
        $obOrder = Order::find($iOrderID);
        $obOrder->property = ['note' => 'called the customer'];
        $obOrder->save();
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * @return void
     */
    public function testCustomerCancelGivesTheUnitsBack()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 2]]);

        RetryPaymentHelper::cancel(Order::find($iOrderID));

        $this->assertSame('canceled', (string) Order::find($iOrderID)->status->code);
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * @return void
     */
    public function testReopenedOrderTakesTheUnitsAgain()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iOrderID, 'canceled');

        $this->saveStatus($iOrderID, 'in_progress');

        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 3, false]], $this->holds($iOrderID));
    }

    /**
     * The goods left the shop: canceling a shipped order moves no stock.
     * @return void
     */
    public function testShippedOrderKeepsItsUnitsWhenCanceledLater()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iOrderID, 'sent');

        $this->saveStatus($iOrderID, 'canceled');

        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * A canceled order that ships after all takes its units again before they settle.
     * @return void
     */
    public function testCanceledOrderShippedAfterAllTakesItsUnitsAgain()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iOrderID, 'canceled');

        $this->saveStatus($iOrderID, 'complete');
        $this->saveStatus($iOrderID, 'canceled');

        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * allow_negative_offer_quantity off (.lt): an order of 5 against stock 2 takes 2 and gives back 2.
     * @return void
     */
    public function testClampedCheckoutGivesBackOnlyWhatItTook()
    {
        Settings::set('allow_negative_offer_quantity', '0');
        DB::table(self::OFFERS_TABLE)->where('id', CheckoutStubTables::OFFER_ID)->update(['quantity' => 2]);

        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 5]]);
        $this->assertSame(0, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 2, false]], $this->holds($iOrderID));

        $this->saveStatus($iOrderID, 'canceled');
        $this->assertSame(2, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * A reopen clamped at 0 records the units it really took, so the next cancel gives back that much.
     * @return void
     */
    public function testClampedReopenRecordsWhatItTook()
    {
        Settings::set('allow_negative_offer_quantity', '0');
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iOrderID, 'canceled');
        DB::table(self::OFFERS_TABLE)->where('id', CheckoutStubTables::OFFER_ID)->update(['quantity' => 1]);

        $this->saveStatus($iOrderID, 'new');
        $this->assertSame(0, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 1, false]], $this->holds($iOrderID));

        $this->saveStatus($iOrderID, 'canceled');
        $this->assertSame(1, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * Two lines of one offer and a second offer: one row per offer.
     * @return void
     */
    public function testLinesOfOneOfferSumIntoOneHold()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 2], [CheckoutStubTables::OFFER_ID, 3], [self::SECOND_OFFER_ID, 4]]);

        $this->assertSame([[CheckoutStubTables::OFFER_ID, 5, false], [self::SECOND_OFFER_ID, 4, false]], $this->holds($iOrderID));

        $this->saveStatus($iOrderID, 'canceled');
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame(1000, $this->stock(self::SECOND_OFFER_ID));
    }

    /**
     * The record follows Lovata's own arithmetic even when its offer model was loaded before the
     * stock changed underneath it: the hold is what the checkout took, and a cancel returns it.
     * @return void
     */
    public function testHoldFollowsTheModelLovataDecremented()
    {
        OfferItem::clearCache(CheckoutStubTables::OFFER_ID);
        OfferItem::make(CheckoutStubTables::OFFER_ID)->getObject();
        DB::table(self::OFFERS_TABLE)->where('id', CheckoutStubTables::OFFER_ID)->update(['quantity' => 500]);

        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]], false);
        $iStockAfterCheckout = $this->stock(CheckoutStubTables::OFFER_ID);
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 3, false]], $this->holds($iOrderID));

        $this->saveStatus($iOrderID, 'canceled');
        $this->assertSame($iStockAfterCheckout + 3, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * A failed checkout rolls its decrement and its hold back, and the next checkout records cleanly.
     * @return void
     */
    public function testFailedCheckoutLeavesNoHold()
    {
        Settings::set('check_offer_quantity', '1');
        DB::table(self::OFFERS_TABLE)->where('id', self::SECOND_OFFER_ID)->update(['quantity' => 2]);

        $this->submitCheckout([[CheckoutStubTables::OFFER_ID, 1], [self::SECOND_OFFER_ID, 5]]);

        $this->assertFalse(Result::status());
        $this->assertSame(0, DB::table('lovata_orders_shopaholic_orders')->count());
        $this->assertSame(0, DB::table(self::HOLDS_TABLE)->count());
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));

        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 1]]);
        $this->assertSame([[CheckoutStubTables::OFFER_ID, 1, false]], $this->holds($iOrderID));
    }

    /**
     * Where the 1C import maps offer quantity (.lv) the import owns stock: nothing is held or given back.
     * @return void
     */
    public function testImportOwnedStockIsNeitherHeldNorGivenBack()
    {
        DB::table('system_settings')->insert([
            'item' => 'lovata_shopaholic_xml_import_settings',
            'value' => json_encode(['offer' => [['field' => 'name', 'path_to_field' => 'Naimenovanie'], ['field' => 'quantity', 'path_to_field' => 'Sklad']]]),
        ]);
        StockHoldPolicy::forget();

        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iOrderID, 'canceled');

        $this->assertSame([], $this->holds($iOrderID));
        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * @return void
     */
    public function testNoDecrementNoHold()
    {
        Settings::set('decrement_offer_quantity', '0');

        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);

        $this->assertSame([], $this->holds($iOrderID));
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * A failed payment leaves the order payable, so it keeps its units.
     * @return void
     */
    public function testPaymentFailureKeepsTheUnits()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);

        $this->saveStatus($iOrderID, 'new-payment-error');

        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * A late gateway callback cannot make a canceled order unpaid again; a paid one can.
     * @return void
     */
    public function testCanceledOrderStaysCanceledOnLatePaymentFailure()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iOrderID, 'canceled');

        foreach (['payment-pending', 'new-payment-canceled', 'new-payment-error'] as $sUnpaidCode) {
            $this->saveStatus($iOrderID, $sUnpaidCode);
            $this->assertSame('canceled', (string) Order::find($iOrderID)->status->code, $sUnpaidCode);
            $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID), $sUnpaidCode);
        }

        $this->saveStatus($iOrderID, 'new-payment-received');
        $this->assertSame('new-payment-received', (string) Order::find($iOrderID)->status->code);
        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * An order made in the backend never took stock, so canceling it gives nothing back.
     * @return void
     */
    public function testBackendMadeOrderMovesNoStock()
    {
        $obOrder = Order::create(['status_id' => CheckoutStubTables::STATUS_ID, 'payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID]);
        DB::table('lovata_orders_shopaholic_order_positions')->insert(['order_id' => $obOrder->id, 'item_id' => CheckoutStubTables::OFFER_ID, 'item_type' => 'Lovata\Shopaholic\Models\Offer', 'quantity' => 3]);

        $this->saveStatus($obOrder->id, 'canceled');

        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * Deleting an order gives back what it still held and drops its rows.
     * @return void
     */
    public function testDeletedOrderGivesBackWhatItHeld()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);

        $this->deleteOrder($iOrderID);

        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame(0, DB::table(self::HOLDS_TABLE)->count());
    }

    /**
     * A canceled order already gave its units back, a shipped one keeps them: deleting either moves nothing.
     * @return void
     */
    public function testDeletingACanceledOrShippedOrderMovesNothingMore()
    {
        $iCanceledOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->saveStatus($iCanceledOrderID, 'canceled');
        $iShippedOrderID = $this->placeOrder([[self::SECOND_OFFER_ID, 4]]);
        $this->saveStatus($iShippedOrderID, 'sent');

        $this->deleteOrder($iCanceledOrderID);
        $this->deleteOrder($iShippedOrderID);

        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
        $this->assertSame(996, $this->stock(self::SECOND_OFFER_ID));
        $this->assertSame(0, DB::table(self::HOLDS_TABLE)->count());
    }

    /**
     * The backend saves the form inside its own transaction; the give-back joins it.
     * @return void
     */
    public function testCancelInsideTheBackendTransactionGivesTheUnitsBack()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);

        DB::transaction(fn () => $this->saveStatus($iOrderID, 'canceled'));

        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * Inside a caller's transaction a failed give-back throws, so the status change rolls back with it.
     * @return void
     */
    public function testFailedGiveBackInsideTheBackendTransactionRollsTheCancelBack()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->failNextOfferSave();

        try {
            DB::transaction(fn () => $this->saveStatus($iOrderID, 'canceled'));
            $this->fail('the failed give-back must reach the caller');
        } catch (RuntimeException $obException) {
            $this->assertSame('offer save refused', $obException->getMessage());
        }

        $this->assertSame('new', (string) Order::find($iOrderID)->status->code);
        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * A status saved on its own is already committed when the give-back fails: the failure is
     * logged and the next save of the order gives the units back.
     * @return void
     */
    public function testFailedGiveBackOnItsOwnHealsOnTheNextSave()
    {
        $iOrderID = $this->placeOrder([[CheckoutStubTables::OFFER_ID, 3]]);
        $this->failNextOfferSave();

        $this->saveStatus($iOrderID, 'canceled');
        $this->assertSame('canceled', (string) Order::find($iOrderID)->status->code);
        $this->assertSame(997, $this->stock(CheckoutStubTables::OFFER_ID));

        Order::find($iOrderID)->save();
        $this->assertSame(1000, $this->stock(CheckoutStubTables::OFFER_ID));
    }

    /**
     * The next Offer save throws once.
     * @return void
     */
    protected function failNextOfferSave()
    {
        $bFailed = false;
        Event::listen('eloquent.saving: '.Offer::class, function () use (&$bFailed) {
            if (!$bFailed) {
                $bFailed = true;
                throw new RuntimeException('offer save refused');
            }
        });
    }

    /**
     * Lovata's order afterDelete also removes the order's tasks.
     * @param int $iOrderID
     * @return void
     */
    protected function deleteOrder(int $iOrderID)
    {
        if (!Schema::hasTable('lovata_orders_shopaholic_tasks')) {
            Schema::create('lovata_orders_shopaholic_tasks', function ($obTable) {
                $obTable->increments('id');
                $obTable->integer('order_id')->nullable();
            });
        }

        Order::find($iOrderID)->delete();
    }

    /**
     * @param array $arLineList [[offer id, quantity], ...]
     * @param bool  $bForgetOfferItems drop memoized offer models first, as a fresh request would
     * @return int order id
     */
    protected function placeOrder(array $arLineList, bool $bForgetOfferItems = true): int
    {
        $this->submitCheckout($arLineList, $bForgetOfferItems);
        $this->assertTrue(Result::status(), (string) Result::message());

        return (int) Order::orderByDesc('id')->value('id');
    }

    /**
     * A fresh checkout request through MakeOrder::onCreate for a guest without a password.
     * @param array $arLineList [[offer id, quantity], ...]
     * @param bool  $bForgetOfferItems
     * @return void
     */
    protected function submitCheckout(array $arLineList, bool $bForgetOfferItems = true)
    {
        $this->forgetCheckoutState($bForgetOfferItems);

        $iCartID = DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => null]);
        foreach ($arLineList as [$iOfferID, $iQuantity]) {
            DB::table('lovata_orders_shopaholic_cart_positions')->insert(['cart_id' => $iCartID, 'item_id' => $iOfferID, 'quantity' => $iQuantity]);
        }
        CartProcessor::$iTestCartID = $iCartID;

        $this->iSubmitCount++;
        request()->replace([
            'order' => ['payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID],
            'user'  => ['email' => 'stock-'.$this->iSubmitCount.'@example.com', 'name' => 'Anna', 'password' => ''],
        ]);

        $obMakeOrder = new MakeOrder(null, ['mode' => 'ajax']);
        $obMakeOrder->init();
        $obMakeOrder->runAjaxHandler('onCreate');
    }

    /**
     * Save the status the way the backend relation field does, with the id as a string.
     * @param int    $iOrderID
     * @param string $sCode
     * @return void
     */
    protected function saveStatus(int $iOrderID, string $sCode)
    {
        $obOrder = Order::find($iOrderID);
        $obOrder->status_id = (string) DB::table('lovata_orders_shopaholic_statuses')->where('code', $sCode)->value('id');
        $obOrder->save();
    }

    /**
     * @param int $iOfferID
     * @return int
     */
    protected function stock(int $iOfferID): int
    {
        return (int) DB::table(self::OFFERS_TABLE)->where('id', $iOfferID)->value('quantity');
    }

    /**
     * @param int $iOrderID
     * @return array [[offer id, quantity, returned], ...] by offer id
     */
    protected function holds(int $iOrderID): array
    {
        return DB::table(self::HOLDS_TABLE)->where('order_id', $iOrderID)->orderBy('offer_id')->get()
            ->map(fn ($obRow) => [(int) $obRow->offer_id, (int) $obRow->quantity, $obRow->returned_at !== null])->all();
    }

    /**
     * Each submit is a fresh request: guest session, new cart, order and helper singletons.
     * @param bool $bForgetOfferItems
     * @return void
     */
    protected function forgetCheckoutState(bool $bForgetOfferItems = true)
    {
        $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
        $sAuthFacadeClass::logout();
        UserHelper::forgetInstance();
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        OrderProcessor::forgetInstance();
        TaxHelper::forgetInstance();
        CurrencyHelper::forgetInstance();
        if ($bForgetOfferItems) {
            OfferItem::clearCache(CheckoutStubTables::OFFER_ID);
            OfferItem::clearCache(self::SECOND_OFFER_ID);
        }
        Result::setTrue();
    }
}
