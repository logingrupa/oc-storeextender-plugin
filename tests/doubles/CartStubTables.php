<?php

use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;

/**
 * SQLite stubs of the cart and cart position tables, columns per the local MySQL
 * DESCRIBE of 2026-09-17. The full Shopaholic migration chain is SQLite-incompatible,
 * so the tables are hand-built and only created when missing.
 */
final class CartStubTables
{
    /**
     * @return void
     */
    public static function create()
    {
        self::createIfMissing('lovata_orders_shopaholic_carts', function (Blueprint $obTable) {
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

        self::createIfMissing('lovata_orders_shopaholic_cart_positions', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('cart_id')->default(0);
            $obTable->integer('item_id')->default(0);
            $obTable->string('item_type')->default('Lovata\Shopaholic\Models\Offer');
            $obTable->integer('quantity')->default(0);
            $obTable->text('property')->nullable();
            $obTable->timestamps();
            $obTable->timestamp('deleted_at')->nullable();
        });
    }

    /**
     * @param string   $sTableName
     * @param \Closure $fnDefineTable
     * @return void
     */
    private static function createIfMissing($sTableName, $fnDefineTable)
    {
        if (Schema::hasTable($sTableName)) {
            return;
        }
        Schema::create($sTableName, $fnDefineTable);
    }
}
