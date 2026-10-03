<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * One row per payment reminder sent, so each unpaid online order gets each reminder stage
 * once. The unique key is the claim two overlapping scheduler runs race for.
 */
class CreateTableOrderPaymentReminders extends Migration
{
    const TABLE = 'logingrupa_storeextender_order_payment_reminders';

    public function up()
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function ($obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id');
            $obTable->integer('order_id')->unsigned();
            $obTable->string('stage', 32);
            $obTable->timestamp('sent_at');
            $obTable->unique(['order_id', 'stage'], 'lg_se_payment_reminder_order_stage_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists(self::TABLE);
    }
}
