<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * A family row is either a color or a collection (kind), and may carry a
 * 2 to 3 stop gradient the pills and group headings draw instead of the flat
 * hex. kind defaults to color so every row synced before this keeps its
 * meaning; gradient is NULL when the export has none.
 */
class UpdateTableColorFamilyMetaAddKindGradient extends Migration
{
    const TABLE = 'logingrupa_storeextender_color_family_meta';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE, 'kind')) {
            Schema::table(self::TABLE, function ($obTable) {
                $obTable->string('kind', 16)->default('color');
            });
        }

        if (!Schema::hasColumn(self::TABLE, 'gradient')) {
            Schema::table(self::TABLE, function ($obTable) {
                $obTable->text('gradient')->nullable();
            });
        }
    }

    public function down()
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['kind', 'gradient'] as $sColumn) {
            if (Schema::hasColumn(self::TABLE, $sColumn)) {
                Schema::table(self::TABLE, function ($obTable) use ($sColumn) {
                    $obTable->dropColumn($sColumn);
                });
            }
        }
    }
}
