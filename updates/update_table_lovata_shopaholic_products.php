<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableLovataShopaholicProducts
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableLovataShopaholicProducts extends Migration
{
    /**
     * Apply migration
     */
    public function up()
    {
        if (Schema::hasTable('lovata_shopaholic_products') && !Schema::hasColumn('lovata_shopaholic_products', 'video_link')) {

            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                $obTable->string('video_link')->nullable()->after('description');
            });
        }
        if (Schema::hasTable('lovata_shopaholic_products') && !Schema::hasColumn('lovata_shopaholic_products', 'how_to')) {

            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                $obTable->text('how_to')->nullable()->after('description');
            });
        }
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (Schema::hasTable('lovata_shopaholic_products') && Schema::hasColumn('lovata_shopaholic_products', 'video_link')) {
            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                $obTable->dropColumn(['video_link']);
            });
        }
        if (Schema::hasTable('lovata_shopaholic_products') && Schema::hasColumn('lovata_shopaholic_products', 'how_to')) {
            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                $obTable->dropColumn(['how_to']);
            });
        }
    }
}
