<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

/**
 * HTML as the plain text a schema.org property carries: block boundaries
 * become spaces, tags are stripped, entities decoded, whitespace collapsed.
 * Editors write one paragraph per block with no whitespace between them, so a
 * bare strip_tags glues sentences.
 *
 * Read by ProductStructuredData for the product description and for the body
 * of a review.
 */
class PlainText
{
    const BLOCK_BOUNDARY_PATTERN = '#<br\s*/?>|</(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|script|style)\s*>#i';

    /**
     * @param string $sHtml markup from an editor field or a visitor comment
     * @return string plain text, empty when the markup holds no text
     */
    public static function fromHtml(string $sHtml): string
    {
        $sSpaced = (string) preg_replace(self::BLOCK_BOUNDARY_PATTERN, ' ', $sHtml);
        $sText = html_entity_decode(strip_tags($sSpaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $sText));
    }
}
