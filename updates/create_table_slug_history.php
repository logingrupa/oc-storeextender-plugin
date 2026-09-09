<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Every slug a product or category used to answer to, keyed to the slug it
 * answers to now. Filled by SlugHistoryHandler on every rename, read by
 * LegacyUrlResolver to turn a dead URL into a 301.
 */
class CreateTableSlugHistory extends Migration
{
    const TABLE = 'logingrupa_storeextender_slug_history';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function ($obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->string('model_type', 16);
            $obTable->string('old_slug');
            $obTable->string('new_slug');
            $obTable->timestamps();
            $obTable->unique(['model_type', 'old_slug']);
            $obTable->index(['model_type', 'new_slug']);
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
