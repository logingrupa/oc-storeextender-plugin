<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableLovataShopaholicProductsAddManufacturerAndIngredients
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableLovataShopaholicProductsAddManufacturerAndIngredients extends Migration
{
    /**
     * Apply migration
     *
     * Adds manufacturer (string), ingredients (text), and warning (text) fields to lovata_shopaholic_products table.
     * manufacturer: Name of the product manufacturer.
     * ingredients: List or description of product ingredients.
     * warning: Important product warnings or cautions.
     */
    public function up(): void
    {
        if (Schema::hasTable('lovata_shopaholic_products')) {
            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                if (!Schema::hasColumn('lovata_shopaholic_products', 'manufacturer')) {
                    $obTable->string('manufacturer', 255)->nullable()->after('description');
                }
                if (!Schema::hasColumn('lovata_shopaholic_products', 'ingredients')) {
                    $obTable->text('ingredients')->nullable()->after('manufacturer');
                }
                if (!Schema::hasColumn('lovata_shopaholic_products', 'warning')) {
                    $obTable->text('warning')->nullable()->after('ingredients');
                }
            });
        }
    }

    /**
     * Rollback migration
     */
    public function down(): void
    {
        if (Schema::hasTable('lovata_shopaholic_products')) {
            Schema::table('lovata_shopaholic_products', function (Blueprint $obTable) {
                if (Schema::hasColumn('lovata_shopaholic_products', 'manufacturer')) {
                    $obTable->dropColumn(['manufacturer']);
                }
                if (Schema::hasColumn('lovata_shopaholic_products', 'ingredients')) {
                    $obTable->dropColumn(['ingredients']);
                }
                if (Schema::hasColumn('lovata_shopaholic_products', 'warning')) {
                    $obTable->dropColumn(['warning']);
                }
            });
        }
    }
}