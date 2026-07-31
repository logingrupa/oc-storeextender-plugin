<?php namespace Logingrupa\StoreExtender\Updates;

use October\Rain\Database\Updates\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rainlab_translate_attributes.model_id is varchar(255) while every caller binds an
 * integer morph key. MySQL cannot use a varchar index against an integer operand
 * (implicit cast to double), so each translated model load scans every row of its
 * model_type (5000+ rows for offers, 13-20ms per query, 350+ such queries on a cold
 * home page). Convert the column to INT UNSIGNED and add a composite
 * (model_type, model_id) index so the lookup becomes a point read.
 */
class UpdateTableRainlabTranslateAttributesModelIdType extends Migration
{
    const TABLE = 'rainlab_translate_attributes';
    const INDEX = 'translate_attributes_type_id_index';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        $iNonNumericCount = DB::table(self::TABLE)
            ->whereNotNull('model_id')
            ->whereRaw("model_id NOT REGEXP '^[0-9]+$'")
            ->count();
        if ($iNonNumericCount > 0) {
            throw new \RuntimeException(sprintf(
                '%s holds %d non-numeric model_id values - cannot convert to INT UNSIGNED',
                self::TABLE,
                $iNonNumericCount
            ));
        }

        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `model_id` INT UNSIGNED NULL', self::TABLE));

        Schema::table(self::TABLE, function ($obTable) {
            $obTable->index(['model_type', 'model_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::table(self::TABLE, function ($obTable) {
            $obTable->dropIndex(self::INDEX);
        });

        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `model_id` VARCHAR(255) NULL', self::TABLE));
    }
}
