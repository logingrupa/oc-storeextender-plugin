<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailTemplatesRetireOrderRows
 *
 * Each shop carried its own hand-written row for the two order mails, so the templates
 * drifted: the .lt copy still called the bank details partial without its arguments and all
 * three struck through payment method 1 and told the customer to pay by bank transfer, which
 * on .lt is the live Paysera method. The plugin registers the codes against its own views,
 * so the rows are removed and the three shops render one template from git.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailTemplatesRetireOrderRows extends Migration
{
    const TABLE_NAME = 'system_mail_templates';

    const CODE_LIST = [
        'lovata.ordersshopaholic::mail.create_order_user',
        'lovata.ordersshopaholic::mail.create_order_manager',
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
