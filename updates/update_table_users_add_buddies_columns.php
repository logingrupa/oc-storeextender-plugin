<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use System\Classes\PluginManager;

/**
 * Class UpdateTableUsersAddBuddiesColumns
 *
 * RainLab.User has no equivalent for these four Buddies columns. Checkout posts
 * "phone" and "property[...]" as a frozen contract, so both must exist on the user
 * table. "property" stays a JSON column rather than a pivot: a pivot would add a
 * query to every logged in page render.
 *
 * "phone_short" is derived, not authored - Buddies fills it from the phone setter.
 *
 * "viewed_products" has no reader in the current theme; the column is carried over so
 * the 4420 rows of history survive until a replacement feature lands.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableUsersAddBuddiesColumns extends Migration
{
    const TABLE_NAME = 'users';

    /**
     * Apply migration
     */
    public function up()
    {
        if (!$this->isApplicable()) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            if (!Schema::hasColumn(self::TABLE_NAME, 'phone')) {
                $obTable->text('phone')->nullable();
            }

            if (!Schema::hasColumn(self::TABLE_NAME, 'phone_short')) {
                $obTable->text('phone_short')->nullable();
            }

            if (!Schema::hasColumn(self::TABLE_NAME, 'property')) {
                $obTable->mediumText('property')->nullable();
            }

            if (!Schema::hasColumn(self::TABLE_NAME, 'viewed_products')) {
                $obTable->text('viewed_products')->nullable();
            }
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (!$this->isApplicable()) {
            return;
        }

        $arColumnList = array_values(array_filter(
            ['phone', 'phone_short', 'property', 'viewed_products'],
            function ($sColumn) {
                return Schema::hasColumn(self::TABLE_NAME, $sColumn);
            }
        ));

        if (empty($arColumnList)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) use ($arColumnList) {
            $obTable->dropColumn($arColumnList);
        });
    }

    /**
     * RainLab.User owns the "users" table. Without the plugin the table either does not
     * exist or belongs to something else, and must not be touched.
     * @return bool
     */
    protected function isApplicable()
    {
        return PluginManager::instance()->hasPlugin('RainLab.User')
            && Schema::hasTable(self::TABLE_NAME);
    }
}
