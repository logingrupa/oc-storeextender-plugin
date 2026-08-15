<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * The families.json export order is a contract the storefront must render:
 * sort_order persists each family's position in the export (written by
 * `storeextender:sync-offer-colors` on every upsert), so the pill/chip order
 * survives the by-slug upserts that keep row ids stable. Nullable by design:
 * NULL means "no position synced yet" and readers fall back to id order.
 */
class UpdateTableColorFamilyMetaAddSortOrder extends Migration
{
    const TABLE = 'logingrupa_storeextender_color_family_meta';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'sort_order')) {
            return;
        }

        Schema::table(self::TABLE, function ($obTable) {
            $obTable->integer('sort_order')->nullable();
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'sort_order')) {
            return;
        }

        Schema::table(self::TABLE, function ($obTable) {
            $obTable->dropColumn('sort_order');
        });
    }
}
