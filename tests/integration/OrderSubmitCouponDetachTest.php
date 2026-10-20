<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/CheckoutStubTables.php';

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Database\Events\TransactionCommitting;
use System\Classes\UpdateManager;
use October\Rain\Support\Facades\Event;
use Lovata\Shopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\WithoutConditionDiscountPosition;
use Lovata\CouponsShopaholic\Models\Coupon;
use Logingrupa\StoreExtender\Classes\Helper\PlacedOrderMemory;

/**
 * The cart's coupons leave with the order. Lovata.CouponsShopaholic copies them onto the
 * order at after_create; the checkout commit then detaches them from the cart in the same
 * transaction that empties it, so a throw after the commit cannot hand the shopper's next
 * cart a coupon already spent on this order.
 *
 * CouponHelper with its active group list, CartProcessor and the Shopaholic settings cache
 * are static and outlive the test application, so every test runs in its own process.
 * The coupon tables come from the coupons plugin's own migrations.
 */
#[RunTestsInSeparateProcesses]
class OrderSubmitCouponDetachTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const ORDERS_TABLE = 'lovata_orders_shopaholic_orders';

    const CARTS_TABLE = 'lovata_orders_shopaholic_carts';

    const CART_POSITIONS_TABLE = 'lovata_orders_shopaholic_cart_positions';

    const ORDER_PROMO_TABLE = 'lovata_orders_shopaholic_order_promo_mechanism';

    const COUPON_CART_TABLE = 'lovata_coupons_shopaholic_coupon_cart';

    const ORDER_COUPON_TABLE = 'lovata_coupons_shopaholic_order_coupon';

    const COUPON_CODE = 'rudens2026';

    /** @var int */
    protected $iCouponID;

    /**
     * Checkout tables with one offer, the coupons plugin booted and migrated, one active
     * multi-use coupon.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        CheckoutStubTables::create();
        CheckoutStubTables::seed();
        $this->loadPlugin('Lovata.CouponsShopaholic');
        UpdateManager::instance()->migratePlugin('Lovata.CouponsShopaholic');
        $this->iCouponID = $this->insertMultiUseCoupon();

        Result::setTrue();
    }

    /**
     * The order keeps its coupon copy from after_create; a committing listener running
     * after the handler sees level 1 with no cart coupon and no position. Lovata's own
     * order.created detach then finds nothing to remove.
     * @return void
     */
    public function testCommitDetachesTheCartCouponsAndTheOrderKeepsItsCopy()
    {
        $iCartID = $this->insertCartWithCoupon();
        $obOrder = $this->makeOrder();
        $arSeenList = [];
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $obCommitting) use (&$arSeenList, $iCartID) {
            $arSeenList[] = [$obCommitting->connection->transactionLevel(), $this->countCartCoupons($iCartID), $this->countPositions($iCartID)];
        });

        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertSame([[1, 0, 0]], $arSeenList, 'level, cart coupons and positions seen before the PDO commit');
        $this->assertOrderKeepsItsCoupon($obOrder);
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));

        Event::fire(OrderProcessor::EVENT_ORDER_CREATED, $obOrder);

        $this->assertSame(0, $this->countCartCoupons($iCartID));
        $this->assertOrderKeepsItsCoupon($obOrder);
    }

    /**
     * An order created listener that throws ahead of Lovata's detach leaves no coupon on the cart.
     * @return void
     */
    public function testThrowingOrderCreatedListenerLeavesNoCouponOnTheCart()
    {
        // the October facade runs priority 100 before Lovata's detach at 0, as in OrderProcessor
        Event::listen(OrderProcessor::EVENT_ORDER_CREATED, function () {
            throw new RuntimeException('mail data builder failed');
        }, 100);
        $iCartID = $this->insertCartWithCoupon();
        $obOrder = $this->makeOrder();
        $this->firePlacedOrder($obOrder, $iCartID);

        try {
            Event::fire(OrderProcessor::EVENT_ORDER_CREATED, $obOrder);
            $this->fail('the order created listener should have thrown');
        } catch (RuntimeException $obException) {
            $this->assertSame('mail data builder failed', $obException->getMessage());
        }

        $this->assertSame(0, $this->countCartCoupons($iCartID));
        $this->assertSame(0, $this->countPositions($iCartID));
    }

    /**
     * disable_cart_clear_on_new_order keeps the positions; the coupons are detached anyway,
     * as Lovata's order.created listener does.
     * @return void
     */
    public function testDisabledCartClearKeepsThePositionsAndStillDetachesTheCoupons()
    {
        Settings::set('disable_cart_clear_on_new_order', '1');
        $iCartID = $this->insertCartWithCoupon();
        $obOrder = $this->makeOrder();

        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertSame(1, $this->countPositions($iCartID));
        $this->assertSame(0, $this->countCartCoupons($iCartID));
        $this->assertOrderKeepsItsCoupon($obOrder);
    }

    /**
     * A failing detach aborts the order: rolled back with its coupon copy, the cart keeps
     * its positions and coupon, the exception is rethrown and logged once.
     * @return void
     */
    public function testCouponDetachFailureRollsTheOrderBackAndRethrows()
    {
        $iCartID = $this->insertCartWithCoupon();
        CartProcessor::$iTestCartID = $iCartID;
        $arLoggedList = [];
        Event::listen(MessageLogged::class, function (MessageLogged $obLogged) use (&$arLoggedList) {
            $arLoggedList[] = $obLogged->message;
        });

        DB::beginTransaction();
        $obOrder = Order::create(['property' => ['email' => 'buyer@example.com']]);
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        $this->assertSame(1, DB::table(self::ORDER_COUPON_TABLE)->count(), 'after_create copied the coupon onto the order');
        // SQLite DDL is transactional: the rollback brings the table and its row back
        Schema::drop(self::COUPON_CART_TABLE);

        try {
            DB::commit();
            $this->fail('the commit should have rethrown the detach failure');
        } catch (QueryException $obException) {
            $this->assertStringContainsString('no such table: '.self::COUPON_CART_TABLE, $obException->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $arAbortLogList = array_values(array_filter($arLoggedList, function ($sMessage) use ($obOrder) {
            return str_starts_with($sMessage, 'Checkout commit aborted for order '.$obOrder->id.':');
        }));
        $this->assertCount(1, $arAbortLogList);
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count(), 'the order row was rolled back');
        $this->assertSame(0, DB::table(self::ORDER_COUPON_TABLE)->count(), 'the order coupon copy was rolled back');
        $this->assertSame(0, DB::table(self::ORDER_PROMO_TABLE)->count(), 'the order promo row was rolled back');
        $this->assertSame(1, $this->countPositions($iCartID));
        $this->assertSame(1, $this->countCartCoupons($iCartID));
        $this->assertArrayNotHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * A cart without coupons runs the same detach, a delete that removes nothing, and commits.
     * @return void
     */
    public function testCartWithoutCouponsCommitsTheOrder()
    {
        $iCartID = DB::table(self::CARTS_TABLE)->insertGetId(['user_id' => null]);
        $this->insertPosition($iCartID);
        $obOrder = $this->makeOrder();
        $arDetachList = [];
        DB::listen(function ($obQuery) use (&$arDetachList) {
            if (str_starts_with($obQuery->sql, 'delete from "'.self::COUPON_CART_TABLE.'"')) {
                $arDetachList[] = DB::transactionLevel();
            }
        });

        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertSame([1], $arDetachList, 'one detach, inside the checkout transaction');
        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertSame(0, DB::table(self::ORDER_COUPON_TABLE)->count());
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * An active group with no usage limit and a ten percent position discount, one public coupon.
     * @return int coupon ID
     */
    protected function insertMultiUseCoupon()
    {
        $iMechanismID = DB::table('lovata_orders_shopaholic_promo_mechanism')->insertGetId([
            'name'           => 'Autumn 10%',
            'type'           => WithoutConditionDiscountPosition::class,
            'increase'       => false,
            'priority'       => 100,
            'discount_value' => 10,
            'discount_type'  => 'percent',
        ]);

        $iGroupID = DB::table('lovata_coupons_shopaholic_coupon_groups')->insertGetId([
            'active'             => true,
            'name'               => 'Autumn',
            'date_begin'         => Carbon::now()->subDay()->toDateTimeString(),
            'promo_mechanism_id' => $iMechanismID,
            'max_usage'          => 0,
        ]);

        return DB::table('lovata_coupons_shopaholic_coupons')->insertGetId(['group_id' => $iGroupID, 'code' => self::COUPON_CODE]);
    }

    /**
     * @return int cart ID of a guest cart with one position and the coupon applied
     */
    protected function insertCartWithCoupon()
    {
        $iCartID = DB::table(self::CARTS_TABLE)->insertGetId(['user_id' => null]);
        $this->insertPosition($iCartID);
        DB::table(self::COUPON_CART_TABLE)->insert(['coupon_id' => $this->iCouponID, 'cart_id' => $iCartID]);

        return $iCartID;
    }

    /**
     * @param int $iCartID
     * @return void
     */
    protected function insertPosition($iCartID)
    {
        DB::table(self::CART_POSITIONS_TABLE)->insert(['cart_id' => $iCartID, 'item_id' => CheckoutStubTables::OFFER_ID, 'quantity' => 2]);
    }

    /**
     * @return Order guest order, created outside the checkout transaction
     */
    protected function makeOrder()
    {
        return Order::create(['property' => ['email' => 'buyer@example.com']])->fresh();
    }

    /**
     * The checkout transaction as OrderProcessor::create runs it: after_create inside,
     * then the outer commit.
     * @param Order $obOrder
     * @param int   $iCartID
     * @return void
     */
    protected function firePlacedOrder($obOrder, $iCartID)
    {
        CartProcessor::$iTestCartID = $iCartID;
        DB::beginTransaction();
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        DB::commit();
        CartProcessor::forgetInstance();
    }

    /**
     * The order_coupon row with the code and the coupon's promo row, both from after_create.
     * @param Order $obOrder
     * @return void
     */
    protected function assertOrderKeepsItsCoupon($obOrder)
    {
        $arOrderCouponList = DB::table(self::ORDER_COUPON_TABLE)->where('order_id', $obOrder->id)->get(['coupon_id', 'code'])->map(function ($obRow) {
            return [(int) $obRow->coupon_id, $obRow->code];
        })->all();
        $this->assertSame([[$this->iCouponID, self::COUPON_CODE]], $arOrderCouponList);

        $iPromoCount = DB::table(self::ORDER_PROMO_TABLE)
            ->where('order_id', $obOrder->id)
            ->where('element_type', Coupon::class)
            ->where('element_id', $this->iCouponID)
            ->count();
        $this->assertSame(1, $iPromoCount);
    }

    /**
     * @param int $iCartID
     * @return int
     */
    protected function countCartCoupons($iCartID)
    {
        return DB::table(self::COUPON_CART_TABLE)->where('cart_id', $iCartID)->count();
    }

    /**
     * @param int $iCartID
     * @return int live (not soft deleted) positions
     */
    protected function countPositions($iCartID)
    {
        return DB::table(self::CART_POSITIONS_TABLE)->where('cart_id', $iCartID)->whereNull('deleted_at')->count();
    }

    /**
     * @param int $iCartID
     * @return array
     */
    protected function readCartProperty($iCartID)
    {
        return (array) json_decode((string) DB::table(self::CARTS_TABLE)->where('id', $iCartID)->value('property'), true);
    }
}
