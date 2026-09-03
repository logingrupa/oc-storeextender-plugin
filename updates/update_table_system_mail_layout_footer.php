<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailLayoutFooter
 *
 * The mail layout footer is a database row seeded once at install from the October
 * default view, so it keeps the English sentence no matter what locale the message
 * is rendered in. The mailer Twig environment exposes trans(), and MailManager
 * renders inside withLocale(), so the key resolves in the language of the message.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailLayoutFooter extends Migration
{
    const TABLE_NAME = 'system_mail_layouts';

    const FIELD_LIST = ['content_html', 'content_text'];

    const SEARCH = '{{ appName }}. All rights reserved.';

    const REPLACE = "{{ appName }}. {{ trans('logingrupa.storeextender::lang.mail.rights_reserved') }}";

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        $arFieldList = array_values(array_filter(self::FIELD_LIST, function ($sField) {
            return Schema::hasColumn(self::TABLE_NAME, $sField);
        }));

        if (empty($arFieldList)) {
            return;
        }

        foreach (DB::table(self::TABLE_NAME)->select(array_merge(['id'], $arFieldList))->get() as $obRow) {
            $arUpdateData = [];

            foreach ($arFieldList as $sField) {
                $sContent = $obRow->$sField ?? null;
                if (!is_string($sContent) || !str_contains($sContent, self::SEARCH)) {
                    continue;
                }

                $arUpdateData[$sField] = str_replace(self::SEARCH, self::REPLACE, $sContent);
            }

            if (!empty($arUpdateData)) {
                DB::table(self::TABLE_NAME)->where('id', $obRow->id)->update($arUpdateData);
            }
        }
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        foreach (DB::table(self::TABLE_NAME)->select(array_merge(['id'], self::FIELD_LIST))->get() as $obRow) {
            $arUpdateData = [];

            foreach (self::FIELD_LIST as $sField) {
                $sContent = $obRow->$sField ?? null;
                if (!is_string($sContent) || !str_contains($sContent, self::REPLACE)) {
                    continue;
                }

                $arUpdateData[$sField] = str_replace(self::REPLACE, self::SEARCH, $sContent);
            }

            if (!empty($arUpdateData)) {
                DB::table(self::TABLE_NAME)->where('id', $obRow->id)->update($arUpdateData);
            }
        }
    }
}
