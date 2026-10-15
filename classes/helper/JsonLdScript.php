<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

/**
 * The text of one JSON-LD script element a Twig function prints. A content
 * gap the block's builder rejects prints an empty object instead and is
 * logged, so the page around the element still renders.
 *
 * Read by BreadcrumbStructuredData::render() and ProductStructuredData::render().
 */
class JsonLdScript
{
    /** @var string a valid JSON-LD script body that states nothing */
    const EMPTY_BLOCK = '{}';

    /**
     * @param Closure $fnBuild   answers the block as an array, null when the page has no item to state,
     *                           throws InvalidArgumentException on a content gap
     * @param string  $sBlock    the block's name in the log line
     * @param array   $arContext what identifies the render in the log
     * @return string JSON, safe to inline inside a script element
     */
    public static function encode(Closure $fnBuild, string $sBlock, array $arContext): string
    {
        try {
            $arBlock = $fnBuild();
            if ($arBlock === null) {
                return self::EMPTY_BLOCK;
            }

            return json_encode($arBlock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
        } catch (InvalidArgumentException | JsonException $obException) {
            // Twig render boundary: a content gap leaves one structured-data block out, the page still renders
            Log::error($sBlock.' JSON-LD left out: '.$obException->getMessage(), $arContext + ['exception' => $obException]);

            return self::EMPTY_BLOCK;
        }
    }
}
