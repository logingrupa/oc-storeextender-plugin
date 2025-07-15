<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableLovataShopaholicOffers
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableLovataShopaholicOffers extends Migration
{
    /**
     * Apply migration
     */
    public function up()
    {
        if (Schema::hasTable('lovata_shopaholic_offers') && !Schema::hasColumn('lovata_shopaholic_offers', 'variation')) {

            Schema::table('lovata_shopaholic_offers', function (Blueprint $obTable) {
                $obTable->string('variation')->nullable()->after('description');
            });
        }
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (Schema::hasTable('lovata_shopaholic_offers') && Schema::hasColumn('lovata_shopaholic_offers', 'variation')) {
            Schema::table('lovata_shopaholic_offers', function (Blueprint $obTable) {
                $obTable->dropColumn(['variation']);
            });
        }
    }
}
