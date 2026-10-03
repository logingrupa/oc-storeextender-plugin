<?php

use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;

/**
 * SQLite stubs of the order tables, columns per the production DESCRIBE of 2026-10-02.
 * The full Lovata migration chain is SQLite-incompatible, so the tables are hand-built
 * and only created when missing. The payment reminder log comes from its real migration.
 */
final class OrderStubTables
{
    /**
     * @return void
     */
    public static function create()
    {
        self::createIfMissing('lovata_orders_shopaholic_statuses', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('code');
            $obTable->integer('sort_order')->nullable();
            $obTable->boolean('is_user_show')->default(false);
            $obTable->integer('user_status_id')->nullable();
            $obTable->text('preview_text')->nullable();
            $obTable->string('color')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_orders_shopaholic_payment_methods', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(false);
            $obTable->string('name');
            $obTable->string('code');
            $obTable->integer('sort_order')->nullable();
            $obTable->text('preview_text')->nullable();
            $obTable->integer('cancel_status_id')->nullable();
            $obTable->integer('fail_status_id')->nullable();
            $obTable->boolean('send_purchase_request')->default(false);
            $obTable->string('gateway_id')->nullable();
            $obTable->string('gateway_currency')->nullable();
            $obTable->text('gateway_property')->nullable();
            $obTable->integer('before_status_id')->nullable();
            $obTable->integer('after_status_id')->nullable();
            $obTable->boolean('restore_cart')->default(false);
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_orders_shopaholic_orders', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->integer('status_id')->nullable();
            $obTable->string('order_number')->nullable();
            $obTable->string('secret_key')->nullable();
            $obTable->decimal('shipping_price', 15, 2)->nullable();
            $obTable->integer('shipping_type_id')->nullable();
            $obTable->integer('payment_method_id')->nullable();
            $obTable->mediumText('property')->nullable();
            $obTable->string('transaction_id')->nullable();
            $obTable->text('payment_data')->nullable();
            $obTable->text('payment_response')->nullable();
            $obTable->string('payment_token')->nullable();
            $obTable->integer('manager_id')->nullable();
            $obTable->integer('currency_id')->nullable();
            $obTable->decimal('shipping_tax_percent', 15, 2)->nullable();
            $obTable->integer('one_c_status_id')->nullable();
            $obTable->integer('site_id')->nullable();
            $obTable->timestamps();
        });

        self::createIfMissing('lovata_shopaholic_entity_site_relation', function (Blueprint $obTable) {
            $obTable->integer('entity_id');
            $obTable->string('entity_type');
            $obTable->integer('site_id');
        });

        require_once __DIR__.'/../../updates/create_table_order_payment_reminders.php';
        (new \Logingrupa\StoreExtender\Updates\CreateTableOrderPaymentReminders())->up();
    }

    /**
     * @param string $sTable
     * @param \Closure $fnBuild
     * @return void
     */
    protected static function createIfMissing($sTable, \Closure $fnBuild)
    {
        if (Schema::hasTable($sTable)) {
            return;
        }

        Schema::create($sTable, $fnBuild);
    }
}
