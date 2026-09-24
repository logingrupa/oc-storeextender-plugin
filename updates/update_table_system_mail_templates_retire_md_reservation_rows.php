<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailTemplatesRetireMdReservationRows
 *
 * The Master Days summary page sent its two attendee mails under a misspelt plugin code,
 * so they resolved only where a hand-written database row carried that code (one shop) and
 * failed silently elsewhere. The page now sends the plugin's md_reservation views, so the
 * rows have no sender left and are removed.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailTemplatesRetireMdReservationRows extends Migration
{
    const TABLE_NAME = 'system_mail_templates';

    const CODE_LIST = [
        'logingrupa.storeesxtender::mail.reservationdeleted',
        'logingrupa.storeesxtender::mail.reservationreminder',
    ];

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
