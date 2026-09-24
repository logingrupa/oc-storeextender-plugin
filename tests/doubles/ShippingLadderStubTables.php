<?php

use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;

/**
 * SQLite stubs of the tables the shipping ladder reads, columns per the
 * local MySQL DESCRIBE of 2026-09-17. The full Shopaholic migration chain
 * is SQLite-incompatible (EqualOldPriceHandlerTest), so the tables are
 * hand-built and only created when missing.
 */
final class ShippingLadderStubTables
{
    /**
     * @return void
     */
    public static function create()
    {
        self::createIfMissing('lovata_orders_shopaholic_shipping_types', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('external_id')->default('');
            $obTable->boolean('active')->default(false);
            $obTable->string('name');
            $obTable->string('code');
            $obTable->integer('sort_order')->nullable();
            $obTable->text('preview_text')->nullable();
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->text('property')->nullable();
            $obTable->string('api_class')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_orders_shopaholic_promo_mechanism', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('type');
            $obTable->boolean('increase')->default(false);
            $obTable->boolean('auto_add')->default(false);
            $obTable->integer('priority');
            $obTable->double('discount_value');
            $obTable->string('discount_type');
            $obTable->boolean('final_discount')->default(false);
            $obTable->text('property')->nullable();
            $obTable->text('display_template')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_campaigns_shopaholic_campaigns', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(false);
            $obTable->string('name');
            $obTable->dateTime('date_begin');
            $obTable->dateTime('date_end')->nullable();
            $obTable->integer('promo_mechanism_id');
            $obTable->integer('promo_block_id')->nullable();
            $obTable->text('preview_text')->nullable();
            $obTable->text('description')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_campaigns_shopaholic_campaign_shipping_type', function (Blueprint $obTable) {
            $obTable->integer('shipping_type_id');
            $obTable->integer('campaign_id');
            $obTable->primary(['shipping_type_id', 'campaign_id']);
        });

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
