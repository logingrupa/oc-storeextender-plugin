<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/CartStubTables.php';

use Illuminate\Support\Facades\DB;
use Logingrupa\StoreExtender\Classes\Helper\AccountCart;

/**
 * AccountCart::forUser returns the row CartProcessor::findUserCart binds the cookie to:
 * the first cart of the account, created when the account has none.
 */
class AccountCartTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const CARTS_TABLE = 'lovata_orders_shopaholic_carts';

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        CartStubTables::create();
    }

    /**
     * @return void
     */
    public function testFirstCartOfTheAccountIsReturned()
    {
        $iFirstCartID = DB::table(self::CARTS_TABLE)->insertGetId(['user_id' => 42]);
        DB::table(self::CARTS_TABLE)->insert(['user_id' => 42]);
        DB::table(self::CARTS_TABLE)->insert(['user_id' => null]);

        $this->assertSame($iFirstCartID, (int) AccountCart::forUser(42)->id);
        $this->assertSame(3, DB::table(self::CARTS_TABLE)->count(), 'an existing account cart mints no row');
    }

    /**
     * @return void
     */
    public function testAccountWithoutACartGetsANewRow()
    {
        DB::table(self::CARTS_TABLE)->insert(['user_id' => null]);

        $obCart = AccountCart::forUser(42);

        $this->assertSame(42, (int) $obCart->user_id);
        $this->assertSame([(int) $obCart->id], DB::table(self::CARTS_TABLE)->where('user_id', 42)->pluck('id')->map('intval')->all());
    }

    /**
     * @return void
     */
    public function testUserIdBelowOneIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        AccountCart::forUser(0);
    }
}
