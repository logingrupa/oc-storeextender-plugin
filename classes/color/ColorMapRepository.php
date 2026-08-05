<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Support\Facades\Cache;
use Logingrupa\StoreExtender\Models\OfferColor;
use System\Models\Parameter;

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
    // Legacy key: nothing reads it any more, markSynced() clears it once so a
    // pre-upgrade entry does not sit in the cache forever.
    const CACHE_KEY_LEGACY_VERSION = 'storeextender.offer_colors.version';
    const CACHE_TTL_SECONDS = 600;

    // Sync state is PERSISTED, not cached. `cache:clear` runs on every deploy
    // and by hand, and it used to take the version stamp with it - after which
    // nothing could answer "what did we last import", so a drift check had no
    // choice but to re-import blindly. system_parameters is October's own
    // key/value store, so this needs no table of its own.
    const PARAM_VERSION = 'logingrupa.storeextender::offer_colors.version';
    const PARAM_EXPORT_UPDATED_AT = 'logingrupa.storeextender::offer_colors.export_updated_at';
    const PARAM_SYNCED_AT = 'logingrupa.storeextender::offer_colors.synced_at';

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
        // Read the parameter directly rather than caching in front of it.
        // A cache wrapper here is actively dangerous on upgrade: this value
        // used to live under a cache key of its own, and a leftover entry
        // would shadow the persisted one indefinitely, so the sync command
        // would compare against a stale stamp, decide it was already in sync
        // and never record anything. Parameter keeps its own per-process
        // static cache, so the offer sheet costs one query per request.
        return (string) Parameter::get(self::PARAM_VERSION, '');
    }

    /**
     * When the upstream export was last changed, as the API reported it at our
     * last successful sync. Empty before the first sync.
     */
    public function getExportUpdatedAt(): string
    {
        return (string) Parameter::get(self::PARAM_EXPORT_UPDATED_AT, '');
    }

    /**
     * When we last imported, in this application's own time. Empty before the
     * first sync. Read by the scheduled drift check, never per request.
     */
    public function getSyncedAt(): string
    {
        return (string) Parameter::get(self::PARAM_SYNCED_AT, '');
    }

    /**
     * Record a completed import and drop the cached map. Sync command only.
     *
     * @param string $sVersion         payload version stamp, the WHETHER-it-changed signal
     * @param string $sExportUpdatedAt payload last_updated_at, the WHEN-it-changed signal
     */
    public function markSynced(string $sVersion, string $sExportUpdatedAt): void
    {
        Parameter::set([
            self::PARAM_VERSION            => $sVersion,
            self::PARAM_EXPORT_UPDATED_AT  => $sExportUpdatedAt,
            self::PARAM_SYNCED_AT          => now()->toDateTimeString(),
        ]);

        Cache::forget(self::CACHE_KEY_LEGACY_VERSION);
        Cache::forget(self::CACHE_KEY_MAP);
    }
}
