<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Per-offer classification confidence from the color-lab offers.json export:
 * the ?color= catalog grid orders the shades inside a family by it, most
 * certainly-classified first. Nullable by design: NULL means "no score
 * synced" and the shade keeps its unsorted position at the tail.
 */
class UpdateTableOfferColorsAddConfidence extends Migration
{
    const TABLE = 'logingrupa_storeextender_offer_colors';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, 'confidence')) {
            return;
        }

        Schema::table(self::TABLE, function ($obTable) {
            $obTable->decimal('confidence', 5, 2)->nullable();
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, 'confidence')) {
            return;
        }

        Schema::table(self::TABLE, function ($obTable) {
            $obTable->dropColumn('confidence');
        });
    }
}
