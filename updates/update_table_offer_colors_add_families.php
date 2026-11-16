<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Every family an offer belongs to (JSON list, primary first) and its shade
 * code from the color-lab offers.json export. Both nullable: NULL means the
 * export did not carry the field, and readers fall back to the single
 * family column.
 */
class UpdateTableOfferColorsAddFamilies extends Migration
{
    const TABLE = 'logingrupa_storeextender_offer_colors';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, 'families')) {
            Schema::table(self::TABLE, function ($obTable) {
                $obTable->text('families')->nullable();
            });
        }

        if (!Schema::hasColumn(self::TABLE, 'shade_code')) {
            Schema::table(self::TABLE, function ($obTable) {
                $obTable->string('shade_code', 64)->nullable();
            });
        }
    }

    public function down()
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['families', 'shade_code'] as $sColumn) {
            if (Schema::hasColumn(self::TABLE, $sColumn)) {
                Schema::table(self::TABLE, function ($obTable) use ($sColumn) {
                    $obTable->dropColumn($sColumn);
                });
            }
        }
    }
}
