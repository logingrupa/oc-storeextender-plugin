<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Companion data for the Color Family Shopaholic property: per PropertyValue
 * slug the localized display names, the per-locale synonym lists the search
 * matcher scans, and the canonical swatch hex. PropertyValue has no home for
 * synonyms or hex; this table is matcher/render-only data owned by
 * `storeextender:sync-offer-colors`.
 */
class CreateTableColorFamilyMeta extends Migration
{
    const TABLE = 'logingrupa_storeextender_color_family_meta';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function ($obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->string('value_slug', 64)->unique();
            $obTable->string('family', 64);
            $obTable->string('hex', 7)->nullable();
            $obTable->longText('names')->nullable();
            $obTable->longText('synonyms')->nullable();
            $obTable->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
