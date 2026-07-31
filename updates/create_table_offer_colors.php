<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Local mirror of the nailolab color-lab offer color data.
 * Filled by `storeextender:sync-offer-colors`; production reads only this
 * table, never the remote API at request time.
 */
class CreateTableOfferColors extends Migration
{
    const TABLE = 'logingrupa_storeextender_offer_colors';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function ($obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->string('offer_uuid', 64)->unique();
            $obTable->string('family', 64);
            $obTable->string('hex', 7)->nullable();
            $obTable->decimal('hue', 6, 2)->default(0);
            $obTable->decimal('lightness', 5, 4)->default(0);
            $obTable->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
