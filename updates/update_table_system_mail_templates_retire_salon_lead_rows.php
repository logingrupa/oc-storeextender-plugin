<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailTemplatesRetireSalonLeadRows
 *
 * The salon application page used to send two templates that existed only as hand-written
 * database rows in Latvian: backend::mail.lead for the managers and a misspelt plugin code
 * for the applicant. The page now sends the plugin's own salon_lead views, which carry a
 * variant per shop locale, so the rows have no sender left and are removed.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailTemplatesRetireSalonLeadRows extends Migration
{
    const TABLE_NAME = 'system_mail_templates';

    const CODE_LIST = [
        'backend::mail.lead',
        'logingrupa.storeesxtender::mail.salonlead',
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
