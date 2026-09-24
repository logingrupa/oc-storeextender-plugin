<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Str;
use Twig\Markup;

/**
 * Twig filter `highlight`: wraps every occurrence of the search term in a
 * name with <span class="highlight">, escaping both the name and the term.
 *
 * Matching is case- and accent-insensitive so it agrees with the
 * utf8mb4_unicode_ci search that produced the result: "sarkana" marks
 * "Sarkanā". The result is Twig Markup, so autoescape leaves it alone.
 *
 * Twig, registered in Plugin::registerMarkupTags():
 *   {{ obProduct.name|highlight(sSearch) }}
 */
class TextHighlighter
{
    /**
     * @param string|null $sText subject, a product, offer or category name
     * @param string|array|null $mTermList search term or a list of them
     * @return Markup
     */
    public static function highlight($sText, $mTermList): Markup
    {
        $sText = (string) $sText;
        $arTermList = array_filter(array_map('trim', array_map('strval', (array) $mTermList)), 'strlen');
        if ($sText === '' || empty($arTermList)) {
            return new Markup(e($sText), 'UTF-8');
        }

        $sFolded = static::fold($sText);
        $arPatternList = array_map(function ($sTerm) {
            return preg_quote(static::fold($sTerm), '/');
        }, $arTermList);
        if (!preg_match_all('/'.implode('|', $arPatternList).'/u', $sFolded, $arMatchList, PREG_OFFSET_CAPTURE)) {
            return new Markup(e($sText), 'UTF-8');
        }

        $sHtml = '';
        $iCursor = 0;
        foreach ($arMatchList[0] as [$sMatch, $iByteOffset]) {
            $iStart = mb_strlen(substr($sFolded, 0, $iByteOffset));
            $iLength = mb_strlen($sMatch);
            $sHtml .= e(mb_substr($sText, $iCursor, $iStart - $iCursor));
            $sHtml .= '<span class="highlight">'.e(mb_substr($sText, $iStart, $iLength)).'</span>';
            $iCursor = $iStart + $iLength;
        }
        $sHtml .= e(mb_substr($sText, $iCursor));

        return new Markup($sHtml, 'UTF-8');
    }

    /**
     * Lowercase ASCII copy with the same character count as the input, so a
     * byte offset found in the copy maps back onto the original by position.
     * A character whose transliteration is not exactly one character stays
     * as its lowercase self.
     *
     * @param string $sText
     * @return string
     */
    protected static function fold($sText): string
    {
        $sFolded = '';
        foreach (mb_str_split(mb_strtolower($sText)) as $sChar) {
            $sAscii = Str::ascii($sChar);
            $sFolded .= mb_strlen($sAscii) === 1 ? $sAscii : $sChar;
        }

        return $sFolded;
    }
}
