<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * Class CreateTableUserProperties
 *
 * Dynamic user property definitions. RainLab.User has no equivalent for the Buddies
 * "addition properties" feature, and the nine definitions on this store carry the B2B
 * invoicing form. Column shape mirrors lovata_buddies_addition_properties so the port
 * is a straight copy and Toolbox CommonProperty can back both models unchanged.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class CreateTableUserProperties extends Migration
{
    const TABLE_NAME = 'logingrupa_storeextender_user_properties';

    /**
     * Apply migration
     */
    public function up()
    {
        if (Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::create(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->string('code')->nullable();
            $obTable->string('description')->nullable();
            $obTable->string('type')->default('input');
            $obTable->text('settings')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamps();

            $obTable->index('name');
            $obTable->index('slug');
            $obTable->index('code');
            $obTable->index('sort_order');
        });
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        Schema::dropIfExists(self::TABLE_NAME);
    }
}
