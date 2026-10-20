<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/OrderStubTables.php';
require_once __DIR__.'/../doubles/ShippingLadderStubTables.php';
require_once __DIR__.'/../doubles/TaxHelperDouble.php';

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Lovata\Shopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\StoreExtender\Classes\Helper\PlacedOrderMemory;

/**
 * A checkout submit whose answer never reached the browser leaves a placed order and an
 * empty cart. The next submit from that cart within ten minutes opens the placed order's
 * page instead of "Cart is empty" or a second order. The record and the emptied cart
 * commit with the order. The cart row holds only the order id and an HMAC of the secret
 * key, because every Cart AJAX answer returns cart.property to the browser.
 */
class OrderSubmitReplayHandlerTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const ORDERS_TABLE = 'lovata_orders_shopaholic_orders';

    const CARTS_TABLE = 'lovata_orders_shopaholic_carts';

    const CART_POSITIONS_TABLE = 'lovata_orders_shopaholic_cart_positions';

    const ACCOUNT_USER_ID = 42;

    const SECOND_CONNECTION = 'sqlite_second';

    /**
     * Order stubs, the cart and promo tables a cart clear reads, the tax helper double,
     * Result reset to true.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        OrderStubTables::create();
        ShippingLadderStubTables::create();
        TaxHelperDouble::install();

        Result::setTrue();
    }

    /**
     * Drop the cart seam and the singletons, they outlive the app.
     * @return void
     */
    public function tearDown(): void
    {
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        TaxHelperDouble::reset();
        Settings::clearInternalCache();
        Result::setTrue();

        parent::tearDown();
    }

    /**
     * The guest cart gets the order id and an HMAC, never the secret key, and keeps its other keys.
     * @return void
     */
    public function testPlacedOrderIsRecordedOnTheGuestCartWithoutTheSecretKey()
    {
        $iCartID = $this->insertCart(null, ['coupon_hint' => 'kept']);
        $obOrder = $this->makeOrder(null, 0);

        $this->firePlacedOrder($obOrder, $iCartID);

        $arProperty = $this->readCartProperty($iCartID);
        $this->assertSame(['id' => $obOrder->id, 'mac' => $this->hmacOf($obOrder)], $arProperty[PlacedOrderMemory::PROPERTY_KEY]);
        $this->assertNotSame($obOrder->secret_key, $arProperty[PlacedOrderMemory::PROPERTY_KEY]['mac']);
        $this->assertStringNotContainsString($obOrder->secret_key, (string) DB::table(self::CARTS_TABLE)->where('id', $iCartID)->value('property'));
        $this->assertSame('kept', $arProperty['coupon_hint'], 'other cart property keys survive the record');
    }

    /**
     * The record and the cleared cart are written inside the checkout transaction: a
     * committing listener running after the handler sees level 1 and no positions.
     * @return void
     */
    public function testCommitRecordsTheOrderAndClearsTheCartInsideTheTransaction()
    {
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        $arSeenList = [];
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $obCommitting) use (&$arSeenList, $iCartID) {
            $arSeenList[] = [$obCommitting->connection->transactionLevel(), $this->countPositions($iCartID)];
        });

        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertSame([[1, 0]], $arSeenList, 'level and position count seen before the PDO commit');
        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertSame(['id' => $obOrder->id, 'mac' => $this->hmacOf($obOrder)], $this->readCartProperty($iCartID)[PlacedOrderMemory::PROPERTY_KEY]);
    }

    /**
     * A checkout rolled back with Result false places nothing: a later commit in the same
     * request neither records nor clears.
     * @return void
     */
    public function testRolledBackCheckoutLeavesTheCartAndWritesNoRecord()
    {
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        CartProcessor::$iTestCartID = $iCartID;

        DB::beginTransaction();
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        DB::rollBack();
        DB::beginTransaction();
        DB::commit();

        $this->assertSame(2, $this->countPositions($iCartID));
        $this->assertArrayNotHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * A savepoint rolled back inside the checkout keeps the pending order for the outer commit.
     * @return void
     */
    public function testSavepointRollbackKeepsThePendingOrder()
    {
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        CartProcessor::$iTestCartID = $iCartID;

        DB::beginTransaction();
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        DB::beginTransaction();
        DB::rollBack();
        $this->assertSame(1, DB::transactionLevel());
        DB::commit();

        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * A savepoint commit is not the checkout commit: nothing happens until the outer one.
     * @return void
     */
    public function testSavepointCommitWaitsForTheOuterCommit()
    {
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        CartProcessor::$iTestCartID = $iCartID;

        DB::beginTransaction();
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        DB::beginTransaction();
        DB::commit();

        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(2, $this->countPositions($iCartID));
        $this->assertArrayNotHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));

        DB::commit();

        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * Any other transaction of the request leaves the cart and its property alone.
     * @return void
     */
    public function testUnrelatedCommitTouchesNeitherTheCartNorItsProperty()
    {
        $iCartID = $this->insertCart(null, ['coupon_hint' => 'kept']);
        $this->insertPositions($iCartID, 2);
        CartProcessor::$iTestCartID = $iCartID;

        DB::beginTransaction();
        DB::commit();

        $this->assertSame(2, $this->countPositions($iCartID));
        $this->assertSame(['coupon_hint' => 'kept'], $this->readCartProperty($iCartID));
    }

    /**
     * disable_cart_clear_on_new_order keeps the positions; the record is still written.
     * @return void
     */
    public function testDisabledCartClearKeepsThePositionsAndWritesTheRecord()
    {
        Settings::set('disable_cart_clear_on_new_order', '1');
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);

        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertSame(2, $this->countPositions($iCartID));
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * An order created listener that throws after the commit (mail, popularity) no longer
     * leaves a full cart behind: the repeated submit opens the placed order.
     * @return void
     */
    public function testThrowingOrderCreatedListenerAfterTheCommitStillReplays()
    {
        // only the October facade (events.priority) honours the priority argument
        Event::listen(OrderProcessor::EVENT_ORDER_CREATED, function () {
            throw new RuntimeException('mail transport down');
        }, 100);
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        $this->firePlacedOrder($obOrder, $iCartID);

        try {
            Event::fire(OrderProcessor::EVENT_ORDER_CREATED, $obOrder);
            $this->fail('the order created listener should have thrown');
        } catch (RuntimeException $obException) {
            $this->assertSame('mail transport down', $obException->getMessage());
        }

        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertReplaysTo($obOrder, $this->fireRepeatedSubmit($iCartID));
    }

    /**
     * The post-commit order save throwing skips every order created listener, the
     * repeated submit still opens the placed order.
     * @return void
     */
    public function testThrowingPostCommitSaveStillReplays()
    {
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        $this->firePlacedOrder($obOrder, $iCartID);
        // above the Toolbox ModelHandler priority 1000
        $obOrder->bindEvent('model.afterSave', function () {
            throw new RuntimeException('after save listener failed');
        }, 1001);

        try {
            $obOrder->save();
            $this->fail('the after save listener should have thrown');
        } catch (RuntimeException $obException) {
            $this->assertSame('after save listener failed', $obException->getMessage());
        }

        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertReplaysTo($obOrder, $this->fireRepeatedSubmit($iCartID));
    }

    /**
     * A failure while settling at commit aborts the order: rolled back, the cart intact,
     * the exception rethrown, no open transaction left behind.
     * @return void
     */
    public function testCommittingFailureRollsTheOrderBackAndRethrows()
    {
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        CartProcessor::$iTestCartID = $iCartID;
        $iRolledBackLevel = null;
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $obRolledBack) use (&$iRolledBackLevel) {
            $iRolledBackLevel = $obRolledBack->connection->transactionLevel();
        });
        $arLoggedList = [];
        Event::listen(MessageLogged::class, function (MessageLogged $obLogged) use (&$arLoggedList) {
            $arLoggedList[] = [$obLogged->message, DB::transactionLevel()];
        });

        DB::beginTransaction();
        $obOrder = Order::create(['property' => ['email' => 'buyer@example.com']]);
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        config(['app.key' => '']);

        try {
            DB::commit();
            $this->fail('the commit should have rethrown the record failure');
        } catch (RuntimeException $obException) {
            $this->assertStringContainsString('APP_KEY is empty', $obException->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, $iRolledBackLevel, 'TransactionRolledBack fired for the outer transaction');
        $arAbortLogList = array_values(array_filter($arLoggedList, function ($arLogged) use ($obOrder) {
            return str_starts_with($arLogged[0], 'Checkout commit aborted for order '.$obOrder->id.':');
        }));
        $this->assertCount(1, $arAbortLogList, 'the abort is logged once with the order id');
        $this->assertSame(0, $arAbortLogList[0][1], 'the log line is written after the rollback');
        $this->assertSame(0, DB::table(self::ORDERS_TABLE)->count(), 'the order row was rolled back');
        $this->assertSame(2, $this->countPositions($iCartID));
        $this->assertArrayNotHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * A commit on another connection is not the checkout commit.
     * @return void
     */
    public function testCommitOnAnotherConnectionLeavesThePendingOrder()
    {
        config(['database.connections.'.self::SECOND_CONNECTION => config('database.connections.sqlite')]);
        $iCartID = $this->insertCart();
        $this->insertPositions($iCartID, 2);
        $obOrder = $this->makeOrder(null, 0);
        CartProcessor::$iTestCartID = $iCartID;

        DB::beginTransaction();
        Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        DB::connection(self::SECOND_CONNECTION)->beginTransaction();
        DB::connection(self::SECOND_CONNECTION)->commit();

        $this->assertSame(2, $this->countPositions($iCartID));
        $this->assertArrayNotHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));

        DB::commit();

        $this->assertSame(0, $this->countPositions($iCartID));
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * The order created event no longer writes the record; the commit does.
     * @return void
     */
    public function testOrderCreatedEventAloneWritesNoRecord()
    {
        $iCartID = $this->insertCart();
        $obOrder = $this->makeOrder(null, 0);
        CartProcessor::$iTestCartID = $iCartID;

        Event::fire(OrderProcessor::EVENT_ORDER_CREATED, $obOrder);

        $this->assertArrayNotHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iCartID));
    }

    /**
     * Submit 1 registered an account: its new cart row carries the record and replays after the cookie moved there.
     * @return void
     */
    public function testAccountOrderIsRecordedOnANewAccountCartAndReplaysThroughIt()
    {
        $iGuestCartID = $this->insertCart();
        $obOrder = $this->makeOrder(self::ACCOUNT_USER_ID, 2);

        $this->firePlacedOrder($obOrder, $iGuestCartID);

        $arAccountCartIdList = DB::table(self::CARTS_TABLE)->where('user_id', self::ACCOUNT_USER_ID)->pluck('id')->all();
        $this->assertCount(1, $arAccountCartIdList, 'the account gets one cart row carrying the record');
        $iAccountCartID = (int) $arAccountCartIdList[0];
        $this->assertSame(['id' => $obOrder->id, 'mac' => $this->hmacOf($obOrder)], $this->readCartProperty($iAccountCartID)[PlacedOrderMemory::PROPERTY_KEY]);
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iGuestCartID), 'the guest cart that placed the order keeps its record');

        // The next Cart request moved the cookie to the account's cart
        $this->assertReplaysTo($obOrder, $this->fireRepeatedSubmit($iAccountCartID));
    }

    /**
     * A logged-in checkout writes its own cart row once: no account cart lookup follows
     * the write, because re-saving an unchanged record would issue no UPDATE to catch.
     * @return void
     */
    public function testRecordIsWrittenOnceWhenTheProcessorCartIsTheAccountCart()
    {
        $iAccountCartID = $this->insertCart(self::ACCOUNT_USER_ID);
        $obOrder = $this->makeOrder(self::ACCOUNT_USER_ID, 0);
        $arCartQueryList = [];
        DB::listen(function ($obQuery) use (&$arCartQueryList) {
            if (str_contains($obQuery->sql, '"'.self::CARTS_TABLE.'"')) {
                $arCartQueryList[] = $obQuery->sql;
            }
        });

        $this->firePlacedOrder($obOrder, $iAccountCartID);

        $arUpdateIndexList = array_keys(array_filter($arCartQueryList, function ($sQuery) {
            return str_starts_with($sQuery, 'update ');
        }));
        $this->assertCount(1, $arUpdateIndexList, implode(PHP_EOL, $arCartQueryList));
        $this->assertSame([], array_slice($arCartQueryList, $arUpdateIndexList[0] + 1), 'no cart query after the record write');
        $this->assertSame(1, DB::table(self::CARTS_TABLE)->count());
        $this->assertArrayHasKey(PlacedOrderMemory::PROPERTY_KEY, $this->readCartProperty($iAccountCartID));
    }

    /**
     * A failed submit from the emptied cart two minutes later redirects to the placed order page.
     * @return void
     */
    public function testRepeatedSubmitOnTheEmptiedCartOpensThePlacedOrder()
    {
        $iCartID = $this->insertCart();
        $obOrder = $this->makeOrder(null, 2);
        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertReplaysTo($obOrder, $this->fireRepeatedSubmit($iCartID));
    }

    /**
     * Eleven minutes is past the replay window.
     * @return void
     */
    public function testOrderOlderThanTheReplayWindowIsNotReplayed()
    {
        $iCartID = $this->insertCart();
        $obOrder = $this->makeOrder(null, 11);
        $this->firePlacedOrder($obOrder, $iCartID);

        $this->assertNull($this->fireRepeatedSubmit($iCartID));
    }

    /**
     * An id swapped to another order fails the HMAC check.
     * @return void
     */
    public function testRecordPointingAtAnotherOrderIsNotReplayed()
    {
        $obOwnOrder = $this->makeOrder(null, 2);
        $obOtherOrder = $this->makeOrder(null, 2);
        $iCartID = $this->insertCart(null, [PlacedOrderMemory::PROPERTY_KEY => ['id' => $obOtherOrder->id, 'mac' => $this->hmacOf($obOwnOrder)]]);

        $this->assertNull($this->fireRepeatedSubmit($iCartID));
    }

    /**
     * The browser can write any cart property key, so a record that is not an int id
     * plus the HMAC of that order's key gets the normal answer.
     * @param string $sRecordKind
     * @return void
     */
    #[DataProvider('provideUntrustedRecord')]
    public function testUntrustedRecordIsNotReplayed($sRecordKind)
    {
        $obOrder = $this->makeOrder(null, 2);
        $arRecordMap = [
            'forged mac'      => ['id' => $obOrder->id, 'mac' => str_repeat('a', 64)],
            'raw secret key'  => ['id' => $obOrder->id, 'mac' => $obOrder->secret_key],
            'missing mac'     => ['id' => $obOrder->id],
            'non-numeric id'  => ['id' => 'abc', 'mac' => $this->hmacOf($obOrder)],
            'numeric string'  => ['id' => (string) $obOrder->id, 'mac' => $this->hmacOf($obOrder)],
            'scalar record'   => 'not-an-array',
        ];
        $iCartID = $this->insertCart(null, [PlacedOrderMemory::PROPERTY_KEY => $arRecordMap[$sRecordKind]]);

        $this->assertNull($this->fireRepeatedSubmit($iCartID));
    }

    /**
     * @return array
     */
    public static function provideUntrustedRecord()
    {
        return [
            'forged mac'     => ['forged mac'],
            'raw secret key' => ['raw secret key'],
            'missing mac'    => ['missing mac'],
            'non-numeric id' => ['non-numeric id'],
            'numeric string' => ['numeric string'],
            'scalar record'  => ['scalar record'],
        ];
    }

    /**
     * A cart with positions is a real submit, not a lost answer.
     * @return void
     */
    public function testCartThatStillHasPositionsIsNotReplayed()
    {
        $iCartID = $this->insertCart();
        $obOrder = $this->makeOrder(null, 2);
        $this->firePlacedOrder($obOrder, $iCartID);
        $this->insertPositions($iCartID, 1);

        $this->assertNull($this->fireRepeatedSubmit($iCartID));
    }

    /**
     * An order payload or Result true is the success path and gets no replay.
     * @return void
     */
    public function testSuccessfulSubmitKeepsTheNormalAnswer()
    {
        $iCartID = $this->insertCart();
        $obOrder = $this->makeOrder(null, 2);
        $this->firePlacedOrder($obOrder, $iCartID);

        CartProcessor::$iTestCartID = $iCartID;
        Result::setFalse();
        $this->assertNull(Event::fire(OrderProcessor::EVENT_ORDER_GET_REDIRECT_URL, $obOrder, true), 'an order payload is the success path');

        CartProcessor::forgetInstance();
        Result::setTrue();
        $this->assertNull(Event::fire(OrderProcessor::EVENT_ORDER_GET_REDIRECT_URL, null, true), 'Result true is the success path');
    }

    /**
     * @param int|null $iUserID
     * @param array    $arProperty
     * @return int cart ID
     */
    protected function insertCart($iUserID = null, $arProperty = [])
    {
        return DB::table(self::CARTS_TABLE)->insertGetId([
            'user_id'  => $iUserID,
            'property' => empty($arProperty) ? null : json_encode($arProperty),
        ]);
    }

    /**
     * @param int $iCartID
     * @param int $iCount
     * @return void
     */
    protected function insertPositions($iCartID, $iCount)
    {
        for ($iIndex = 1; $iIndex <= $iCount; $iIndex++) {
            DB::table(self::CART_POSITIONS_TABLE)->insert(['cart_id' => $iCartID, 'item_id' => $iIndex, 'quantity' => 1]);
        }
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

    /**
     * @param int|null $iUserID
     * @param int      $iAgeMinutes
     * @return Order
     */
    protected function makeOrder($iUserID, $iAgeMinutes)
    {
        $obOrder = Order::create(['user_id' => $iUserID, 'property' => ['email' => 'buyer@example.com']]);
        $this->assertNotEmpty($obOrder->secret_key, 'Order::create generates the secret key');

        $sCreatedAt = Carbon::now()->subMinutes($iAgeMinutes)->toDateTimeString();
        DB::table(self::ORDERS_TABLE)->where('id', $obOrder->id)->update(['created_at' => $sCreatedAt]);

        return $obOrder->fresh();
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
     * A later submit from the emptied cart: OrderProcessor::create set Result false and
     * MakeOrder::onCreate asks for the redirect URL with no order.
     * @param int $iCartID
     * @return string|null
     */
    protected function fireRepeatedSubmit($iCartID)
    {
        CartProcessor::forgetInstance();
        CartProcessor::$iTestCartID = $iCartID;
        Result::setFalse();

        return Event::fire(OrderProcessor::EVENT_ORDER_GET_REDIRECT_URL, null, true);
    }

    /**
     * @param Order       $obOrder
     * @param string|null $sRedirectUrl
     * @return void
     */
    protected function assertReplaysTo($obOrder, $sRedirectUrl)
    {
        $this->assertTrue(str_ends_with((string) $sRedirectUrl, '/checkout/'.$obOrder->secret_key), 'got: '.var_export($sRedirectUrl, true));
    }

    /**
     * @param Order $obOrder
     * @return string
     */
    protected function hmacOf($obOrder)
    {
        return hash_hmac('sha256', $obOrder->secret_key, (string) config('app.key'));
    }
}
