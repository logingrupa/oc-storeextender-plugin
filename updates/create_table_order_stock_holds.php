<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * The offer units each checkout took from stock, one row per order and offer. returned_at
 * is set while a canceled order has given them back, settled_at once the order shipped.
 */
class CreateTableOrderStockHolds extends Migration
{
    const TABLE = 'logingrupa_storeextender_order_stock_holds';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function ($obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->integer('order_id')->unsigned();
            $obTable->integer('offer_id')->unsigned();
            $obTable->integer('quantity')->unsigned();
            $obTable->timestamp('returned_at')->nullable();
            $obTable->timestamp('settled_at')->nullable();
            $obTable->timestamps();
            $obTable->unique(['order_id', 'offer_id'], 'lg_se_order_stock_hold_order_offer_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
