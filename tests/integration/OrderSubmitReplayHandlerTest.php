<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/OrderStubTables.php';

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\OrdersShopaholic\Models\Cart;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\StoreExtender\Classes\Helper\PlacedOrderMemory;

/**
 * A checkout submit whose answer never reached the browser leaves a placed order and an
 * empty cart. The next submit from that cart within ten minutes opens the placed order's
 * page instead of "Cart is empty". The cart row holds only the order id and an HMAC of the
 * secret key, because every Cart AJAX answer returns cart.property to the browser.
 */
class OrderSubmitReplayHandlerTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const CARTS_TABLE = 'lovata_orders_shopaholic_carts';

    const CART_POSITIONS_TABLE = 'lovata_orders_shopaholic_cart_positions';

    const ACCOUNT_USER_ID = 42;

    /**
     * Order stubs plus the cart and cart position tables, Result reset to true.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        OrderStubTables::create();
        $this->createStubTable(self::CARTS_TABLE, function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->integer('shipping_type_id')->nullable();
            $obTable->integer('payment_method_id')->nullable();
            $obTable->string('email')->nullable();
            $obTable->text('user_data')->nullable();
            $obTable->text('property')->nullable();
            $obTable->text('shipping_address')->nullable();
            $obTable->text('billing_address')->nullable();
            $obTable->timestamps();
        });
        $this->createStubTable(self::CART_POSITIONS_TABLE, function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('cart_id')->default(0);
            $obTable->integer('item_id')->default(0);
            $obTable->string('item_type')->default('Lovata\Shopaholic\Models\Offer');
            $obTable->integer('quantity')->default(0);
            $obTable->timestamps();
            $obTable->timestamp('deleted_at')->nullable();
        });

        Result::setTrue();
    }

    /**
     * Drop the cart seam and the processor singleton, both outlive the app.
     * @return void
     */
    public function tearDown(): void
    {
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
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
        $sRedirectUrl = $this->fireRepeatedSubmit($iAccountCartID);

        $this->assertTrue(str_ends_with((string) $sRedirectUrl, '/checkout/'.$obOrder->secret_key), 'got: '.var_export($sRedirectUrl, true));
    }

    /**
     * A logged-in checkout writes its own cart row once and creates no second row.
     * @return void
     */
    public function testRecordIsWrittenOnceWhenTheProcessorCartIsTheAccountCart()
    {
        $iAccountCartID = $this->insertCart(self::ACCOUNT_USER_ID);
        $obOrder = $this->makeOrder(self::ACCOUNT_USER_ID, 0);
        $iCartUpdateCount = 0;
        DB::listen(function ($obQuery) use (&$iCartUpdateCount) {
            if (str_starts_with($obQuery->sql, 'update "'.self::CARTS_TABLE.'"')) {
                $iCartUpdateCount++;
            }
        });

        $this->firePlacedOrder($obOrder, $iAccountCartID);

        $this->assertSame(1, $iCartUpdateCount);
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

        $sRedirectUrl = $this->fireRepeatedSubmit($iCartID);

        $this->assertTrue(str_ends_with((string) $sRedirectUrl, '/checkout/'.$obOrder->secret_key), 'got: '.var_export($sRedirectUrl, true));
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
        DB::table(self::CART_POSITIONS_TABLE)->insert(['cart_id' => $iCartID, 'item_id' => 7, 'quantity' => 1]);

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
        DB::table('lovata_orders_shopaholic_orders')->where('id', $obOrder->id)->update(['created_at' => $sCreatedAt]);

        return $obOrder->fresh();
    }

    /**
     * EVENT_ORDER_CREATED as OrderProcessor::create fires it, before the cart is cleared.
     * @param Order $obOrder
     * @param int   $iCartID
     * @return void
     */
    protected function firePlacedOrder($obOrder, $iCartID)
    {
        CartProcessor::$iTestCartID = $iCartID;
        Event::fire(OrderProcessor::EVENT_ORDER_CREATED, $obOrder);
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
        CartProcessor::$iTestCartID = $iCartID;
        Result::setFalse();

        return Event::fire(OrderProcessor::EVENT_ORDER_GET_REDIRECT_URL, null, true);
    }

    /**
     * @param Order $obOrder
     * @return string
     */
    protected function hmacOf($obOrder)
    {
        return hash_hmac('sha256', $obOrder->secret_key, (string) config('app.key'));
    }

    /**
     * @param string   $sTableName
     * @param \Closure $fnDefineTable
     * @return void
     */
    protected function createStubTable($sTableName, $fnDefineTable)
    {
        if (Schema::hasTable($sTableName)) {
            return;
        }
        Schema::create($sTableName, $fnDefineTable);
    }
}
