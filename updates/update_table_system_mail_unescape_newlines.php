<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailUnescapeNewlines
 *
 * The v1 to v2 data import wrote every customized mail record with the two character
 * sequences "\r" and "\n" in place of line breaks, so the header partial rendered a
 * column of visible \r\n above the greeting of every message and the order templates
 * carried the same damage through their own markup.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailUnescapeNewlines extends Migration
{
    const TABLE_NAME_LIST = [
        'system_mail_partials',
        'system_mail_templates',
        'system_mail_layouts',
    ];

    const FIELD_LIST = ['subject', 'content_html', 'content_text', 'content_css'];

    const ESCAPED_NEWLINE = '\r\n';

    /**
     * Apply migration
     */
    public function up()
    {
        foreach (self::TABLE_NAME_LIST as $sTableName) {
            if (Schema::hasTable($sTableName)) {
                $this->unescapeTable($sTableName);
            }
        }
    }

    /**
     * Rollback migration
     *
     * Re-escaping would restore a rendering bug, so the repair is one way.
     */
    public function down()
    {
    }

    /**
     * @param string $sTableName
     */
    protected function unescapeTable($sTableName)
    {
        $arFieldList = array_values(array_filter(self::FIELD_LIST, function ($sField) use ($sTableName) {
            return Schema::hasColumn($sTableName, $sField);
        }));

        if (empty($arFieldList)) {
            return;
        }

        foreach (DB::table($sTableName)->select(array_merge(['id'], $arFieldList))->get() as $obRow) {
            $arUpdateData = [];

            foreach ($arFieldList as $sField) {
                $sContent = $obRow->$sField ?? null;
                if (!is_string($sContent) || !str_contains($sContent, self::ESCAPED_NEWLINE)) {
                    continue;
                }

                $arUpdateData[$sField] = str_replace(self::ESCAPED_NEWLINE, "\n", $sContent);
            }

            if (!empty($arUpdateData)) {
                DB::table($sTableName)->where('id', $obRow->id)->update($arUpdateData);
            }
        }
    }
}
