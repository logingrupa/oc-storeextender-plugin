<?php namespace Logingrupa\StoreExtender\Updates;

use DB;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableSystemMailPartialHeaderLogo
 *
 * The mail header partial is a database row carried over from the v1 shop. It still points
 * at the theme directory the shop used before the rename, so the logo is a 404 in every
 * message, and it links to one fixed country shop from all of them. Plugin::shareMailBrandLogo
 * publishes brandLogoUrl and brandLogoLink, which resolve per installation.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableSystemMailPartialHeaderLogo extends Migration
{
    const TABLE_NAME = 'system_mail_partials';

    const FIELD_LIST = ['content_html', 'content_text'];

    const REPLACE_LIST = [
        'https://nailscosmetics.lv/themes/naisstore/assets/images/logo.png' => '{{ brandLogoUrl }}',
        'https://nailscosmetics.lt'                                        => '{{ brandLogoLink }}',
    ];

    /**
     * Apply migration
     */
    public function up()
    {
        $this->replaceContent(self::REPLACE_LIST);
    }

    /**
     * Rollback migration
     */
    public function down()
    {
        $this->replaceContent(array_flip(self::REPLACE_LIST));
    }

    /**
     * Rewrite the header partial fields
     * @param array $arReplaceList
     */
    protected function replaceContent($arReplaceList)
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

        $obRowList = DB::table(self::TABLE_NAME)
            ->where('code', 'header')
            ->select(array_merge(['id'], $arFieldList))
            ->get();

        foreach ($obRowList as $obRow) {
            $arUpdateData = [];

            foreach ($arFieldList as $sField) {
                $sContent = $obRow->$sField ?? null;
                if (!is_string($sContent)) {
                    continue;
                }

                $sUpdatedContent = str_replace(array_keys($arReplaceList), array_values($arReplaceList), $sContent);
                if ($sUpdatedContent === $sContent) {
                    continue;
                }

                $arUpdateData[$sField] = $sUpdatedContent;
            }

            if (!empty($arUpdateData)) {
                DB::table(self::TABLE_NAME)->where('id', $obRow->id)->update($arUpdateData);
            }
        }
    }
}
