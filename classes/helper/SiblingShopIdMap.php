<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which 1C row another shop of the family serves, and at which path.
 *
 * The cross-domain hreflang block used to pair pages by guessing that the
 * sibling served the same slug. That held while every shop carried Latvian
 * slugs and stopped on 2026-11-15, when the .no slugs were translated into
 * Norwegian; .lt never matched at all. The guard refused to print a URL the
 * sibling did not list, so the links vanished rather than 404ing, and pairing
 * fell to 4 of 261 product pages on .lv against 250 products that actually
 * exist on both shops.
 *
 * The external_id is a 1C GUID and is the same value on every shop for the
 * same row, so it is the only cross-shop identity the catalogue has. Each shop
 * publishes its own id-to-path map at /api/shop-id-map.json; this class caches
 * a sibling's copy and resolves a pair through it.
 *
 * Two contracts carried over from SiblingShopSitemap, both load-bearing:
 *
 *  - map() is a cache read and nothing else, because this runs inside a page
 *    render and is asked once per cross-domain site. A miss that fetched
 *    inline cost a measured 10 s for a visitor on 2026-11-12. The map is
 *    filled by storeextender:warm-sibling-maps, out of band.
 *  - it fails closed. No id, no sibling entry, or an unfilled map means no
 *    link. Blank alternates after a cache clear are the contract, not a
 *    regression; the schedule refills them.
 *
 * The own-shop map is different in kind and is allowed to build from the local
 * database on a miss: it is one indexed read of this shop's own rows, not a
 * call to another shop over the internet, and that was the thing worth
 * forbidding.
 */
class SiblingShopIdMap
{
    const CACHE_KEY = 'storeextender.sibling_id_map.';
    const LEASE_KEY = 'storeextender.sibling_id_lease.';
    const SUCCESS_KEY = 'storeextender.sibling_id_success.';
    const OWN_CACHE_KEY = 'storeextender.own_id_map';

    const TTL_OK_MINUTES = 24 * 60;
    const TTL_FAILED_MINUTES = 60;
    const TTL_LEASE_MINUTES = 60;
    const TTL_SUCCESS_MINUTES = 30 * 24 * 60;
    const TTL_OWN_MINUTES = 60;

    const TIMEOUT_SECONDS = 5;

    /** The path every shop of the family publishes its map at */
    const MAP_PATH = '/api/shop-id-map.json';

    /**
     * The route prefix each shop serves its pages under. .no has none, and a
     * bare slash here is what produced '//path' on that shop once before, so
     * the value is the empty string and the join never adds a separator.
     */
    const ROUTE_PREFIX = [
        'https://nailscosmetics.no' => '',
        'https://nailscosmetics.lv' => '/lv',
        'https://nailscosmetics.lt' => '/lt',
    ];

    /**
     * Twig: shop_sibling_url('https://nailscosmetics.no', '/p/acetona-zimulis')
     *
     * Takes a path on THIS shop and answers the sibling's full URL for the same
     * 1C row, or null when the pair cannot be proven. Null is the answer for a
     * static page, for a row with no external_id, and for a row the sibling
     * does not carry.
     */
    public static function siblingUrl(string $sDomain, string $sOwnPath): ?string
    {
        return static::resolve($sDomain, $sOwnPath, static::ownMap());
    }

    /**
     * The same pair, read from caches alone: an unfilled own map answers null
     * instead of rebuilding itself from the database.
     *
     * The site picker asks this one. It renders on every page of the shop,
     * including the phone product page, whose cold render has a pinned query
     * count that the rebuild broke by two. A picker row loses its page
     * equivalent for the minute until the next warm, and lands on the other
     * shop's front page meanwhile, which is a cost worth two queries less.
     */
    public static function siblingUrlFromCache(string $sDomain, string $sOwnPath): ?string
    {
        return static::resolve($sDomain, $sOwnPath, static::cachedOwnMap());
    }

    /** @param array<string,array<string,string>> $arOwnMap bucket => path => id */
    protected static function resolve(string $sDomain, string $sOwnPath, array $arOwnMap): ?string
    {
        $sDomain = rtrim($sDomain, '/');
        $sOwnPath = '/' . trim($sOwnPath, '/');
        if ($sOwnPath === '/') {
            return null;
        }

        $sBucket = ShopIdMap::kindBucketForPath($sOwnPath);

        $sExternalID = $arOwnMap[$sBucket][$sOwnPath] ?? null;
        if (empty($sExternalID)) {
            return null;
        }

        $sSiblingPath = static::map($sDomain)[$sBucket][$sExternalID] ?? null;
        if (empty($sSiblingPath) || !is_string($sSiblingPath)) {
            return null;
        }

        $sPrefix = self::ROUTE_PREFIX[$sDomain] ?? '';
        $sUrl = $sDomain . $sPrefix . '/' . ltrim($sSiblingPath, '/');

        // The .no shop has no prefix and a joined empty prefix produced
        // protocol-relative '//path' locs once already. Refuse rather than
        // advertise a malformed URL.
        if (strpos(substr($sUrl, strlen('https://')), '//') !== false) {
            Log::warning('SiblingShopIdMap: refused a doubled slash in ' . $sUrl);

            return null;
        }

        return $sUrl;
    }

    /**
     * Cache only, by contract: this runs inside the page render.
     *
     * @return array<string,array<string,string>> bucket => id => path
     */
    public static function map(string $sDomain): array
    {
        $arCached = Cache::get(static::cacheKey(rtrim($sDomain, '/')));

        return static::normalise($arCached);
    }

    /**
     * This shop's own path-to-id map. Local read, so a miss may rebuild.
     *
     * @return array<string,array<string,string>> bucket => path => id
     */
    public static function ownMap(): array
    {
        $arCached = Cache::get(self::OWN_CACHE_KEY);
        if (is_array($arCached) && $arCached !== []) {
            return static::normalise($arCached);
        }

        return static::warmOwn();
    }

    /**
     * The own map as the cache holds it, empty when it holds nothing.
     *
     * @return array<string,array<string,string>> bucket => path => id
     */
    protected static function cachedOwnMap(): array
    {
        $arCached = Cache::get(self::OWN_CACHE_KEY);

        return static::normalise(is_array($arCached) ? $arCached : []);
    }

    /**
     * Rebuild this shop's own map from the database.
     *
     * @return array<string,array<string,string>>
     */
    public static function warmOwn(): array
    {
        try {
            $arMap = ShopIdMap::ownPathIdMap();
        } catch (\Throwable $obException) {
            // Boundary layer: no map means no cross-domain link, never a 500
            Log::warning('SiblingShopIdMap: own map could not be built: ' . $obException->getMessage());

            return [];
        }

        Cache::put(self::OWN_CACHE_KEY, $arMap, now()->addMinutes(self::TTL_OWN_MINUTES));

        return static::normalise($arMap);
    }

    /**
     * Fetch one sibling's map and cache it. Console only.
     *
     * @return int|null how many rows were stored, null when it could not be read
     */
    public static function warm(string $sDomain): ?int
    {
        $sDomain = rtrim($sDomain, '/');

        $arMap = static::fetch($sDomain);
        if ($arMap === null) {
            // A failed fetch may not replace a good map with blank alternates
            if (static::map($sDomain) === static::normalise([])) {
                Cache::put(static::cacheKey($sDomain), [], now()->addMinutes(self::TTL_FAILED_MINUTES));
            }

            return null;
        }

        Cache::put(static::cacheKey($sDomain), $arMap, now()->addMinutes(self::TTL_OK_MINUTES));
        static::markWarmed($sDomain);

        return static::rowCount($arMap);
    }

    public static function forget(string $sDomain): void
    {
        Cache::forget(static::cacheKey(rtrim($sDomain, '/')));
    }

    public static function forgetOwn(): void
    {
        Cache::forget(self::OWN_CACHE_KEY);
    }

    public static function leaseIsFresh(string $sDomain): bool
    {
        return Cache::get(static::leaseKey(rtrim($sDomain, '/'))) !== null;
    }

    public static function markWarmed(string $sDomain): void
    {
        $sDomain = rtrim($sDomain, '/');
        $sNow = now()->toIso8601String();

        Cache::put(static::leaseKey($sDomain), $sNow, now()->addMinutes(self::TTL_LEASE_MINUTES));
        Cache::put(static::successKey($sDomain), $sNow, now()->addMinutes(self::TTL_SUCCESS_MINUTES));
    }

    public static function forgetLease(string $sDomain): void
    {
        Cache::forget(static::leaseKey(rtrim($sDomain, '/')));
    }

    public static function lastSuccessAt(string $sDomain): ?Carbon
    {
        $sStamp = Cache::get(static::successKey(rtrim($sDomain, '/')));
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
     * Both buckets present and every value a string, whatever the cache held.
     * A poisoned or half-written entry must read as empty inside a render, the
     * way urlSet() already guards its own.
     *
     * @return array<string,array<string,string>>
     */
    protected static function normalise($arCandidate): array
    {
        $arMap = [
            ShopIdMap::BUCKET_PRODUCTS => [],
            ShopIdMap::BUCKET_CATEGORIES => [],
        ];

        if (!is_array($arCandidate)) {
            return $arMap;
        }

        foreach (array_keys($arMap) as $sBucket) {
            if (!isset($arCandidate[$sBucket]) || !is_array($arCandidate[$sBucket])) {
                continue;
            }

            foreach ($arCandidate[$sBucket] as $sKey => $sValue) {
                if (is_string($sValue) && $sValue !== '' && (is_string($sKey) || is_int($sKey))) {
                    $arMap[$sBucket][(string) $sKey] = $sValue;
                }
            }
        }

        return $arMap;
    }

    protected static function rowCount(array $arMap): int
    {
        $iCount = 0;
        foreach ($arMap as $arBucket) {
            $iCount += is_array($arBucket) ? count($arBucket) : 0;
        }

        return $iCount;
    }

    /**
     * @return array<string,array<string,string>>|null null when unreadable
     */
    protected static function fetch(string $sDomain): ?array
    {
        try {
            $obResponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'nailscosmetics-hreflang/1.0'])
                ->get($sDomain . self::MAP_PATH);
        } catch (\Throwable $obException) {
            Log::warning('SiblingShopIdMap: ' . $sDomain . ' unreachable: ' . $obException->getMessage());

            return null;
        }

        if (!$obResponse->ok()) {
            Log::warning('SiblingShopIdMap: ' . $sDomain . ' answered ' . $obResponse->status());

            return null;
        }

        $arMap = static::normalise($obResponse->json());
        if (static::rowCount($arMap) === 0) {
            Log::warning('SiblingShopIdMap: ' . $sDomain . ' published no row');

            return null;
        }

        return $arMap;
    }

    protected static function leaseKey(string $sDomain): string
    {
        return self::LEASE_KEY . md5($sDomain);
    }

    protected static function successKey(string $sDomain): string
    {
        return self::SUCCESS_KEY . md5($sDomain);
    }
}
