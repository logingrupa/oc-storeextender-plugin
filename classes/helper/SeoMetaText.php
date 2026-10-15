<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Stringable;

/**
 * A MightySeo description as plain text, cut on a word boundary to LENGTH_LIMIT
 * characters. MightySeo escapes the {{ }} output of a template once; literal
 * template text and the CMS meta_description fallback come back unescaped, and
 * decoding once leaves those unchanged.
 *
 * Twig, registered in Plugin::registerMarkupTags() as seo_meta_text; the
 * template escapes the result itself.
 */
class SeoMetaText
{
    const LENGTH_LIMIT = 160;
    const ELLIPSIS = '…';
    const TRAILING_SEPARATOR_PATTERN = '/[\s\p{Pd},.;:\x{2022}]+$/u';

    /**
     * @param mixed $sSeoValue SeoToolbox text, null when the page has none
     * @return string plain text, empty when the value holds none
     */
    public static function fromSeoValue($sSeoValue): string
    {
        $sText = PlainText::fromHtml(html_entity_decode(self::sourceText($sSeoValue), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (mb_strlen($sText) <= self::LENGTH_LIMIT) {
            return $sText;
        }

        // One character past the room the ellipsis leaves, so a word that ends at the cut keeps its trailing space
        $sWindow = mb_substr($sText, 0, self::LENGTH_LIMIT - mb_strlen(self::ELLIPSIS) + 1);
        $iLastSpace = mb_strrpos($sWindow, ' ');
        $sCut = $iLastSpace === false ? mb_substr($sWindow, 0, -1) : mb_substr($sWindow, 0, $iLastSpace);

        return (string) preg_replace(self::TRAILING_SEPARATOR_PATTERN, '', $sCut) . self::ELLIPSIS;
    }

    /**
     * Twig render boundary: a value that is not text is logged and prints no
     * description, invalid UTF-8 is logged and its bad bytes replaced.
     * @param mixed $sSeoValue
     * @return string valid UTF-8
     */
    protected static function sourceText($sSeoValue): string
    {
        if ($sSeoValue === null) {
            return '';
        }
        if (!is_scalar($sSeoValue) && !$sSeoValue instanceof Stringable) {
            Log::warning('SeoMetaText: description meta value is not text', ['type' => get_debug_type($sSeoValue)]);

            return '';
        }

        $sSource = (string) $sSeoValue;
        if (!mb_check_encoding($sSource, 'UTF-8')) {
            Log::warning('SeoMetaText: description meta value is not valid UTF-8, invalid bytes replaced');

            return mb_scrub($sSource, 'UTF-8');
        }

        return $sSource;
    }
}
