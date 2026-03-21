<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableCurrencyIncreaseRatePrecision
 *
 * Increases the decimal precision of the rate column in lovata_shopaholic_currency
 * from decimal(8,2) to decimal(12,6) to support exchange rates like 0.0898.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableCurrencyIncreaseRatePrecision extends Migration
{
    const TABLE_NAME = 'lovata_shopaholic_currency';

    /**
     * Apply migration
     */
    public function up()
    {
        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->decimal('rate', 12, 6)->change();
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->decimal('rate', 8, 2)->change();
        });
    }
}
