<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailPartialsRetireOrderRows
 *
 * The order mails render from the orderMail* partials now. The old codes are no longer
 * registered or called, and .lt and .no still carried hand-edited custom rows for them,
 * the .lt bankdetails row with seller and bank details typed in by hand.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailPartialsRetireOrderRows extends Migration
{
    const TABLE_NAME = 'system_mail_partials';

    const CODE_LIST = ['product', 'orderSummary', 'buttons', 'bankdetails', 'orderDetails'];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        DB::table(self::TABLE_NAME)->whereIn('code', self::CODE_LIST)->delete();
    }
}
