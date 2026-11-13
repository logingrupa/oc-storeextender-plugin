<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which URL another shop of the family serves for a 1C external_id.
 *
 * The three shops carry the same catalogue under slugs in three languages, so
 * an identical path is no longer proof that two pages are the same product.
 * Each shop publishes its own id -> URL map over ROUTE_PATH and the others
 * read it here.
 *
 * Nothing in this class fetches during a page render: read() is a cache read
 * and warm() belongs to storeextender:warm-sibling-maps. An unwarmed sibling
 * contributes no cross-domain link, on every shop of the family, which is the
 * fail-closed contract - never a guessed URL, and never a sibling call while a
 * visitor waits.
 *
 * This class also holds the freshness lease and the last-success stamp for
 * BOTH warmed sibling surfaces, keyed by surface, so the two helpers and the
 * warm command share one implementation of that bookkeeping.
 */
class SiblingShopMap
{
    const CACHE_KEY = 'storeextender.sibling_id_map.';
    const LEASE_KEY = 'storeextender.sibling_warm_lease.';
    const SUCCESS_KEY = 'storeextender.sibling_warm_success.';

    const TTL_OK_MINUTES = 24 * 60;

    /**
     * A missing map is a deploy race, not an outage: .lv/.lt and .no are two
     * Forge boxes finishing minutes apart, so whichever lands first reads a
     * 404 from the other and must retry soon rather than blank its alternates.
     */
    const TTL_FAILED_MINUTES = 5;

    /** How long a successful warm keeps the next scheduled tick from fetching */
    const TTL_LEASE_MINUTES = 60;

    /** The last-success stamp outlives the lease so --status can report a stopped scheduler */
    const TTL_SUCCESS_MINUTES = 30 * 24 * 60;

    const TIMEOUT_SECONDS = 5;

    const ROUTE_PATH = '/api/shop-id-map';

    const SURFACE_ID_MAP = 'id-map';
    const SURFACE_SITEMAP = 'sitemap';

    /** The shops of the family, in the order the hreflang partial lists them */
    const CROSS_DOMAIN_LIST = [
        'https://nailscosmetics.no',
        'https://nailscosmetics.lv',
        'https://nailscosmetics.lt',
    ];

    /**
     * Twig: shop_cross_alternates('/p/slug')
     *
     * Resolves the LOCAL identity once, then reads each sibling's cached map.
     * has_identity false is the only state that may fall back to a path check;
     * an entity with no sibling entry carries no URL for that sibling, because
     * the sibling's sitemap may list the same path for a different product.
     *
     * @return array{has_identity:bool,urls:array<string,string>}
     */
    public static function crossAlternates(string $sPath): array
    {
        $arResolved = ShopIdMap::resolvePath($sPath);
        if ($arResolved['kind'] !== ShopIdMap::PAGE_ENTITY) {
            return ['has_identity' => false, 'urls' => []];
        }

        return ['has_identity' => true, 'urls' => static::siblingUrlList($arResolved['external_id'])];
    }

    /**
     * Cache only, by contract: this runs inside the page render.
     *
     * @return array<string,string>
     */
    public static function read(string $sDomain): array
    {
        $arCached = Cache::get(static::cacheKey($sDomain));

        return is_array($arCached) ? $arCached : [];
    }

    /**
     * Fetch one sibling's map and cache it. Console only.
     *
     * @return int|null how many entries were stored, 0 when the sibling
     *                  publishes nothing right now, null when it could not be
     *                  read or every row it published failed validation
     */
    public static function warm(string $sDomain): ?int
    {
        $sDomain = rtrim($sDomain, '/');

        $arMap = static::fetch($sDomain);
        if ($arMap === null) {
            static::rememberFailure($sDomain);

            return null;
        }

        Cache::put(static::cacheKey($sDomain), $arMap, now()->addMinutes(self::TTL_OK_MINUTES));
        static::markWarmed(self::SURFACE_ID_MAP, $sDomain);

        return count($arMap);
    }

    public static function forget(string $sDomain): void
    {
        Cache::forget(static::cacheKey(rtrim($sDomain, '/')));
    }

    /**
     * True while a successful warm of this surface is still inside its lease,
     * which is what keeps the ten-minute schedule at one cache read per
     * sibling in steady state.
     */
    public static function leaseIsFresh(string $sSurface, string $sDomain): bool
    {
        return Cache::get(static::leaseKey($sSurface, rtrim($sDomain, '/'))) !== null;
    }

    public static function markWarmed(string $sSurface, string $sDomain): void
    {
        $sDomain = rtrim($sDomain, '/');
        $sNow = now()->toIso8601String();

        Cache::put(static::leaseKey($sSurface, $sDomain), $sNow, now()->addMinutes(self::TTL_LEASE_MINUTES));
        Cache::put(static::successKey($sSurface, $sDomain), $sNow, now()->addMinutes(self::TTL_SUCCESS_MINUTES));
    }

    public static function forgetLease(string $sSurface, string $sDomain): void
    {
        Cache::forget(static::leaseKey($sSurface, rtrim($sDomain, '/')));
    }

    /**
     * When this surface last warmed successfully, or null when it never did on
     * this box - which reads as stale, the loud direction.
     */
    public static function lastSuccessAt(string $sSurface, string $sDomain): ?Carbon
    {
        $sStamp = Cache::get(static::successKey($sSurface, rtrim($sDomain, '/')));
        if (!is_string($sStamp) || $sStamp === '') {
            return null;
        }

        return Carbon::parse($sStamp);
    }

    public static function cacheKey(string $sDomain): string
    {
        return self::CACHE_KEY . md5(rtrim($sDomain, '/'));
    }

    /**
     * @return array<string,string>
     */
    protected static function siblingUrlList(?string $sExternalId): array
    {
        if ($sExternalId === null || $sExternalId === '') {
            return [];
        }

        $sOwnOrigin = ShopIdMap::ownOrigin();

        $arUrlList = [];
        foreach (self::CROSS_DOMAIN_LIST as $sDomain) {
            if ($sDomain === $sOwnOrigin) {
                continue;
            }

            $sUrl = static::read($sDomain)[$sExternalId] ?? null;
            if (is_string($sUrl) && $sUrl !== '') {
                $arUrlList[$sDomain] = $sUrl;
            }
        }

        return $arUrlList;
    }

    /**
     * An empty entry is written only when nothing is cached: a failed fetch
     * must never replace a good map with blank alternates.
     */
    protected static function rememberFailure(string $sDomain): void
    {
        if (static::read($sDomain) !== []) {
            return;
        }

        Cache::put(static::cacheKey($sDomain), [], now()->addMinutes(self::TTL_FAILED_MINUTES));
    }

    /**
     * @return array<string,string>|null null when the map could not be read
     */
    protected static function fetch(string $sDomain): ?array
    {
        try {
            $obResponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'nailscosmetics-hreflang/1.0'])
                ->get($sDomain . self::ROUTE_PATH);
        } catch (\Throwable $obException) {
            // Boundary layer: an unreachable sibling is a cached miss, not a fatal
            Log::warning('SiblingShopMap: ' . $sDomain . ' unreachable: ' . $obException->getMessage());

            return null;
        }

        if (!$obResponse->ok()) {
            Log::warning('SiblingShopMap: ' . $sDomain . ' answered ' . $obResponse->status());

            return null;
        }

        $arBody = $obResponse->json();
        if (!is_array($arBody) || !isset($arBody['map']) || !is_array($arBody['map'])) {
            Log::warning('SiblingShopMap: ' . $sDomain . ' published no map');

            return null;
        }

        // A sibling that deactivated its last publishable row answers 200 with
        // an empty map, and that must REPLACE what we hold: keeping the old
        // one would advertise entities the sibling no longer serves. Only a
        // map that had rows and lost all of them to validation is a failure.
        $arSourceMap = $arBody['map'];
        $arMap = static::validated($sDomain, $arSourceMap);
        if ($arMap === [] && $arSourceMap !== []) {
            Log::warning('SiblingShopMap: ' . $sDomain . ' published no usable entry');

            return null;
        }

        return $arMap;
    }

    /**
     * Only a non-empty id against a URL on the sibling's OWN https origin: a
     * poisoned or mis-hosted response may not inject a foreign href into our
     * head tag.
     *
     * @param array<mixed,mixed> $arMap
     * @return array<string,string>
     */
    protected static function validated(string $sDomain, array $arMap): array
    {
        $sPrefix = 'https://' . parse_url($sDomain, PHP_URL_HOST) . '/';

        $arValidated = [];
        $iDropped = 0;
        foreach ($arMap as $sExternalId => $sUrl) {
            $sExternalId = is_string($sExternalId) ? trim($sExternalId) : '';
            if ($sExternalId === '' || !is_string($sUrl) || strpos($sUrl, $sPrefix) !== 0) {
                $iDropped++;

                continue;
            }

            $arValidated[$sExternalId] = $sUrl;
        }

        if ($iDropped > 0) {
            Log::warning('SiblingShopMap: ' . $sDomain . ' published ' . $iDropped . ' entries off its own origin, dropped');
        }

        return $arValidated;
    }

    protected static function leaseKey(string $sSurface, string $sDomain): string
    {
        return self::LEASE_KEY . $sSurface . '.' . md5($sDomain);
    }

    protected static function successKey(string $sSurface, string $sDomain): string
    {
        return self::SUCCESS_KEY . $sSurface . '.' . md5($sDomain);
    }
}
