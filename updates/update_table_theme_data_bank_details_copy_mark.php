<?php namespace Logingrupa\StoreExtender\Updates;

use Db;
use Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableThemeDataBankDetailsCopyMark
 *
 * The order page and the order mails give a Copy button to the text a manager styled as
 * "Code" in the Theme options bank details block. This marks the account number in the
 * blocks the shops already hold, and drops the link an online-bank page pasted around the
 * .lt account. A block that already carries a mark is left as the manager made it.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableThemeDataBankDetailsCopyMark extends Migration
{
    const TABLE_NAME = 'cms_theme_data';

    const FIELD_LIST = ['bank_details', 'secondary_bank_details'];

    const COPY_MARK_CLASS = 'oc-class-code';

    /** An IBAN, else a Norwegian domestic account: eleven digits in a row */
    const ACCOUNT_PATTERN_LIST = ['/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/', '/\b\d{11}\b/'];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        foreach (Db::table(self::TABLE_NAME)->get() as $obRow) {
            $arData = json_decode((string) $obRow->data, true);
            if (!is_array($arData)) {
                continue;
            }

            $arMarkedData = $arData;
            foreach (self::FIELD_LIST as $sField) {
                if (!empty($arData[$sField]) && is_string($arData[$sField])) {
                    $arMarkedData[$sField] = self::markAccount($arData[$sField]);
                }
            }

            if ($arMarkedData !== $arData) {
                Db::table(self::TABLE_NAME)->where('id', $obRow->id)
                    ->update(['data' => json_encode($arMarkedData, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    /**
     * @param string $sHtml a bank details block
     * @return string the block with its account number styled as Code
     */
    public static function markAccount(string $sHtml): string
    {
        if (str_contains($sHtml, self::COPY_MARK_CLASS)) {
            return $sHtml;
        }

        $sHtml = preg_replace('#</?a\b[^>]*>#i', '', $sHtml);

        foreach (self::ACCOUNT_PATTERN_LIST as $sPattern) {
            if (!preg_match($sPattern, strip_tags($sHtml), $arMatch)) {
                continue;
            }

            $sAccount = preg_quote($arMatch[0], '#');
            $sMarked = '<span class="'.self::COPY_MARK_CLASS.'">'.$arMatch[0].'</span>';

            // The account alone inside a styled span (.lv prints it red): the mark replaces that span
            return preg_replace('#(?:<span\b[^>]*>\s*'.$sAccount.'\s*</span>|'.$sAccount.')#', $sMarked, $sHtml, 1);
        }

        return $sHtml;
    }
}
