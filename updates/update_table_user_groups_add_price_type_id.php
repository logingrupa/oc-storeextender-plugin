<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use System\Classes\PluginManager;

/**
 * Class UpdateTableUserGroupsAddPriceTypeId
 *
 * User group pricing reads price_type_id off the group. update_table_user_group_1
 * added it to lovata_buddies_groups but had already run before its RainLab branch
 * was corrected to "user_groups", and will not re-run, so the RainLab column lands
 * here instead. Both migrations guard on Schema::hasColumn, so overlap is a no-op.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableUserGroupsAddPriceTypeId extends Migration
{
    const TABLE_NAME = 'user_groups';

    /**
     * Apply migration
     */
    public function up()
    {
        if (!$this->isApplicable() || Schema::hasColumn(self::TABLE_NAME, 'price_type_id')) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->integer('price_type_id')->nullable();
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (!$this->isApplicable() || !Schema::hasColumn(self::TABLE_NAME, 'price_type_id')) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->dropColumn(['price_type_id']);
        });
    }

    /**
     * @return bool
     */
    protected function isApplicable()
    {
        return PluginManager::instance()->hasPlugin('RainLab.User')
            && Schema::hasTable(self::TABLE_NAME);
    }
}
