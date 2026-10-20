<?php

require_once __DIR__.'/OrderStubTables.php';
require_once __DIR__.'/ShippingLadderStubTables.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;

/**
 * SQLite stubs of every table MakeOrder::onCreate reads or writes for a one-offer cart,
 * on top of the order, cart and promo stubs. Columns per the dashboard plugin's checkout
 * stubs (local MySQL DESCRIBE). Created only when missing.
 */
final class CheckoutStubTables
{
    const OFFER_ID = 1;

    const PRODUCT_ID = 1;

    const STATUS_ID = 1;

    const PAYMENT_METHOD_ID = 1;

    const SHIPPING_TYPE_ID = 1;

    /**
     * @return void
     */
    public static function create()
    {
        OrderStubTables::create();
        ShippingLadderStubTables::create();

        self::createIfMissing('lovata_orders_shopaholic_order_positions', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('item_id');
            $obTable->string('item_type');
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('quantity')->nullable();
            $obTable->decimal('tax_percent', 8, 2)->nullable();
            $obTable->string('code')->nullable();
            $obTable->text('property')->nullable();
            $obTable->decimal('weight', 15, 3)->nullable();
            $obTable->decimal('height', 15, 3)->nullable();
            $obTable->decimal('length', 15, 3)->nullable();
            $obTable->decimal('width', 15, 3)->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_orders_shopaholic_order_promo_mechanism', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('mechanism_id');
            $obTable->string('name');
            $obTable->string('type');
            $obTable->boolean('increase')->default(false);
            $obTable->integer('priority');
            $obTable->decimal('discount_value', 15, 2);
            $obTable->string('discount_type');
            $obTable->boolean('final_discount')->default(false);
            $obTable->text('property')->nullable();
            $obTable->integer('element_id')->nullable();
            $obTable->string('element_type')->nullable();
            $obTable->text('element_data')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_shopaholic_price_types', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
        });

        self::createIfMissing('lovata_shopaholic_prices', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('item_id');
            $obTable->string('item_type');
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('price_type_id')->nullable();
        });

        self::createIfMissing('lovata_shopaholic_currency', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->boolean('is_default')->default(false);
            $obTable->boolean('active')->default(true);
            $obTable->string('symbol')->nullable();
            $obTable->decimal('rate', 15, 4)->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_shopaholic_offers', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->integer('product_id')->nullable();
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('quantity')->default(0);
            $obTable->decimal('weight', 15, 3)->nullable();
            $obTable->decimal('height', 15, 3)->nullable();
            $obTable->decimal('length', 15, 3)->nullable();
            $obTable->decimal('width', 15, 3)->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_shopaholic_products', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('category_id')->nullable();
            $obTable->integer('brand_id')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_shopaholic_categories', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->integer('parent_id')->nullable();
            $obTable->integer('nest_left')->nullable();
            $obTable->integer('nest_right')->nullable();
            $obTable->integer('nest_depth')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_shopaholic_additional_categories', function (Blueprint $obTable) {
            $obTable->integer('product_id');
            $obTable->integer('category_id');
        });

        self::createIfMissing('lovata_shopaholic_taxes', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(false);
            $obTable->boolean('is_global')->default(false);
            $obTable->boolean('applied_to_shipping_price')->default(false);
            $obTable->decimal('percent', 5, 2)->nullable();
            $obTable->string('name')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });

        // RainLab Translate eager loads and indexes translations of the catalog models
        self::createIfMissing('rainlab_translate_attributes', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('locale')->nullable();
            $obTable->string('model_id')->nullable();
            $obTable->string('model_type')->nullable();
            $obTable->text('attribute_data')->nullable();
        });

        self::createIfMissing('rainlab_translate_indexes', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('locale')->nullable();
            $obTable->string('model_id')->nullable();
            $obTable->string('model_type')->nullable();
            $obTable->string('item')->nullable();
            $obTable->text('value')->nullable();
        });
    }

    /**
     * One active product with one offer in stock, an order status, a payment method,
     * a shipping type and the default currency.
     * @return void
     */
    public static function seed()
    {
        DB::table('lovata_orders_shopaholic_statuses')->insert(['id' => self::STATUS_ID, 'name' => 'New', 'code' => 'new', 'sort_order' => 1]);
        DB::table('lovata_orders_shopaholic_payment_methods')->insert(['id' => self::PAYMENT_METHOD_ID, 'active' => 1, 'name' => 'Invoice', 'code' => 'invoice']);
        DB::table('lovata_orders_shopaholic_shipping_types')->insert(['id' => self::SHIPPING_TYPE_ID, 'active' => 1, 'name' => 'Pickup', 'code' => 'pickup', 'price' => 0]);
        DB::table('lovata_shopaholic_currency')->insert(['id' => 1, 'name' => 'EUR', 'code' => 'EUR', 'is_default' => 1, 'active' => 1, 'symbol' => 'EUR', 'rate' => 1]);
        DB::table('lovata_shopaholic_products')->insert(['id' => self::PRODUCT_ID, 'active' => 1, 'name' => 'Gel', 'slug' => 'gel']);
        DB::table('lovata_shopaholic_offers')->insert(['id' => self::OFFER_ID, 'active' => 1, 'product_id' => self::PRODUCT_ID, 'name' => 'Gel 5 ml', 'quantity' => 1000]);
        DB::table('lovata_shopaholic_prices')->insert(['item_id' => self::OFFER_ID, 'item_type' => 'Lovata\Shopaholic\Models\Offer', 'price' => 10, 'price_type_id' => null]);
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
