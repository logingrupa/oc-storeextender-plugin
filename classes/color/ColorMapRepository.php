<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Support\Facades\Cache;
use Logingrupa\StoreExtender\Models\OfferColor;

/**
 * Class ColorMapRepository
 *
 * Read side of the local offer color table. Storefront code (offer sheet,
 * swatch grouping) reads ONLY through this class - the remote color-lab API
 * is touched exclusively by the sync command. Map shape is identical to
 * ColorApiClient::getColorMap() so OfferColorGrouper works unchanged.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorMapRepository
{
    const CACHE_KEY_MAP = 'storeextender.offer_colors.map';
    const CACHE_KEY_VERSION = 'storeextender.offer_colors.version';
    const CACHE_TTL_SECONDS = 600;

    /**
     * Get color map keyed by bare offer UUID
     *
     * @return array ['<offerUuid>' => ['family' => string, 'hex' => string|null, 'hue' => float, 'lightness' => float]]
     */
    public function getColorMap(): array
    {
        return (array) Cache::remember(self::CACHE_KEY_MAP, self::CACHE_TTL_SECONDS, function (): array {
            $arColorMap = [];
            foreach (OfferColor::query()->get() as $obOfferColor) {
                $arColorMap[(string) $obOfferColor->offer_uuid] = [
                    'family'    => (string) $obOfferColor->family,
                    'hex'       => $obOfferColor->hex !== null ? (string) $obOfferColor->hex : null,
                    'hue'       => (float) $obOfferColor->hue,
                    'lightness' => (float) $obOfferColor->lightness,
                ];
            }

            return $arColorMap;
        });
    }

    /**
     * Version stamp of the current data set - written by the sync command
     * from the API payload version, changes only when upstream data changes.
     * Safe cache key part for anything derived from the color map.
     */
    public function getVersion(): string
    {
        return (string) Cache::get(self::CACHE_KEY_VERSION, '');
    }

    /**
     * Store the new version stamp and drop the cached map. Sync command only.
     */
    public function markSynced(string $sVersion): void
    {
        Cache::forever(self::CACHE_KEY_VERSION, $sVersion);
        Cache::forget(self::CACHE_KEY_MAP);
    }
}
