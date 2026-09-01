<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Logingrupa\StoreExtender\Classes\Helper\CartStateReader;

/**
 * CartStateReader feeds the header badge and the cart-state map on every
 * non-POST request, so its contract is load-bearing for the whole theme:
 * resolve the cart exactly like CartProcessor::init() (user cart first, then
 * the cookie, with CartProcessor::$iTestCartID as the same test seam) but
 * NEVER create a cart row and never run the position/promo build. Stub cart
 * tables on SQLite (FamilyPropertySyncTest pattern); the row count of
 * lovata_orders_shopaholic_carts is asserted after every read because the
 * no-insert guarantee is the reason the reader exists.
 */
class CartStateReaderTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->createStubTable('lovata_orders_shopaholic_carts', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_orders_shopaholic_cart_positions', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('cart_id')->default(0);
            $obTable->integer('item_id')->default(0);
            $obTable->string('item_type')->default('Lovata\Shopaholic\Models\Offer');
            $obTable->integer('quantity')->default(0);
            $obTable->timestamps();
            $obTable->timestamp('deleted_at')->nullable();
        });
    }

    public function tearDown(): void
    {
        CartProcessor::$iTestCartID = null;
        UserHelper::forgetInstance();

        parent::tearDown();
    }

    /**
     * @param int|null $iUserID
     * @return int cart ID
     */
    protected function insertCart($iUserID = null)
    {
        return DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => $iUserID]);
    }

    /**
     * @param int         $iCartID
     * @param int         $iItemID
     * @param int         $iQuantity
     * @param string|null $sDeletedAt
     * @return void
     */
    protected function insertPosition($iCartID, $iItemID, $iQuantity, $sDeletedAt = null)
    {
        DB::table('lovata_orders_shopaholic_cart_positions')->insert([
            'cart_id'    => $iCartID,
            'item_id'    => $iItemID,
            'quantity'   => $iQuantity,
            'deleted_at' => $sDeletedAt,
        ]);
    }

    /**
     * Make UserHelper::instance()->getUser() return a stand-in user without
     * booting a user plugin: the Singleton static holds any object, and the
     * reader only reads ->id.
     * @param int $iUserID
     * @return void
     */
    protected function actAsUser($iUserID)
    {
        $obStub = new class {
            public $id;
            public function getUser()
            {
                return $this;
            }
            // Cart::__construct resolves the user model class for its belongsTo
            public function getUserModel()
            {
                return null;
            }
        };
        $obStub->id = $iUserID;

        $obProperty = new ReflectionProperty(UserHelper::class, 'instance');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, $obStub);
    }

    /**
     * @return int
     */
    protected function countCarts()
    {
        return DB::table('lovata_orders_shopaholic_carts')->count();
    }

    public function testCookielessGuestReadsEmptyState()
    {
        $arState = CartStateReader::getState();

        $this->assertSame(['count' => 0, 'positions' => []], $arState);
        $this->assertSame(0, $this->countCarts(), 'a cookieless read must not mint a cart');
    }

    public function testGuestCookieReadsMapAndSkipsSoftDeletedRows()
    {
        $iCartID = $this->insertCart();
        $this->insertPosition($iCartID, 3, 1);
        $this->insertPosition($iCartID, 9, 2);
        $this->insertPosition($iCartID, 5, 4, '2026-08-30 10:00:00');
        CartProcessor::$iTestCartID = $iCartID;

        $arState = CartStateReader::getState();

        $this->assertSame(2, $arState['count']);
        $this->assertSame([3 => 1, 9 => 2], $arState['positions']);
        $this->assertSame(1, $this->countCarts(), 'a guest read must not mint a cart');
    }

    public function testStaleCookieReadsEmptyAndNeverCreatesACart()
    {
        CartProcessor::$iTestCartID = 9999;

        $arState = CartStateReader::getState();

        $this->assertSame(['count' => 0, 'positions' => []], $arState);
        $this->assertSame(0, $this->countCarts(), 'a stale cookie must read as empty, not recreate the cart');
    }

    public function testUserCartWinsOverGuestCookie()
    {
        $iUserCartID = $this->insertCart(42);
        $this->insertPosition($iUserCartID, 7, 3);
        $iGuestCartID = $this->insertCart();
        $this->insertPosition($iGuestCartID, 8, 1);
        $this->actAsUser(42);
        CartProcessor::$iTestCartID = $iGuestCartID;

        $arState = CartStateReader::getState();

        $this->assertSame([7 => 3], $arState['positions'], 'CartProcessor serves the user cart when both exist');
        $this->assertSame(2, $this->countCarts());
    }

    public function testUserWithoutCartFallsBackToGuestCookieCart()
    {
        $iGuestCartID = $this->insertCart();
        $this->insertPosition($iGuestCartID, 8, 1);
        $this->actAsUser(42);
        CartProcessor::$iTestCartID = $iGuestCartID;

        $arState = CartStateReader::getState();

        $this->assertSame([8 => 1], $arState['positions'], 'the guest cart is what the deferred merge would produce');
        $this->assertSame(1, $this->countCarts(), 'no user cart may be minted on a read');
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
