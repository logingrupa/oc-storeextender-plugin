<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableLovataShopaholicProductsAddedAditionalField
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableLovataShopaholicProductsAddedAditionalField extends Migration
{
    /**
     * Apply migration
     */
    public function up()
    {
        if (Schema::hasTable('lovata_shopaholic_products') && !Schema::hasColumn('lovata_shopaholic_products', 'hide_dropdown')) {

            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                $obTable->string('hide_dropdown')->nullable()->after('description');
            });
        }
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (Schema::hasTable('lovata_shopaholic_products') && Schema::hasColumn('lovata_shopaholic_products', 'hide_dropdown')) {
            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                $obTable->dropColumn(['hide_dropdown']);
            });
        }
    }
}
