<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which URLs another shop of the family serves, read from its sitemap.xml.
 *
 * The cross-domain hreflang block used to assume every path exists on every
 * shop, so each page advertised two URLs that were often 404 on the other
 * domains (products one shop does not carry, static pages of another
 * language). A link is emitted only for a URL the other shop lists, which also
 * means a renamed slug stops advertising the pair instead of pointing at a
 * dead URL.
 *
 * The url set is filled by storeextender:warm-sibling-maps, never by a page
 * render: urlSet() is a cache read and nothing else, because hreflang.htm asks
 * it once per cross-domain site and a miss used to fetch a sibling sitemap
 * inline, 5 s timeout each, two siblings, serially - a measured 0.9 s cold and
 * a real 10 s for a visitor on 2026-11-12. An unfilled set means no
 * cross-domain link until the next successful warm. That holds on ALL THREE
 * shops, so blank alternates after a cache clear are the fail-closed contract
 * and not a regression; the ten-minute schedule refills them.
 *
 * This class also holds the freshness lease and the last-success stamp the
 * warm command reads, so --status can tell a stopped scheduler from a
 * transient failure.
 */
class SiblingShopSitemap
{
    const CACHE_KEY = 'storeextender.sibling_sitemap.';
    const LEASE_KEY = 'storeextender.sibling_warm_lease.';
    const SUCCESS_KEY = 'storeextender.sibling_warm_success.';

    const TTL_OK_MINUTES = 24 * 60;
    const TTL_FAILED_MINUTES = 60;

    /** How long a successful warm keeps the next scheduled tick from fetching */
    const TTL_LEASE_MINUTES = 60;

    /** The last-success stamp outlives the lease so --status can report a stopped scheduler */
    const TTL_SUCCESS_MINUTES = 30 * 24 * 60;

    const TIMEOUT_SECONDS = 5;

    /** The shops of the family, in the order the hreflang partial lists them */
    const CROSS_DOMAIN_LIST = [
        'https://nailscosmetics.no',
        'https://nailscosmetics.lv',
        'https://nailscosmetics.lt',
    ];

    /**
     * Twig: shop_has_url('https://nailscosmetics.no', '/p/slug')
     */
    public static function hasUrl(string $sDomain, string $sPath): bool
    {
        $sDomain = rtrim($sDomain, '/');
        $sUrl = $sDomain . ($sPath === '' ? '' : '/' . ltrim($sPath, '/'));

        return isset(static::urlSet($sDomain)[rtrim($sUrl, '/')]);
    }

    /**
     * Cache only, by contract: this runs inside the page render.
     *
     * @return array<string,true> every <loc> of the sitemap, trailing slash trimmed
     */
    public static function urlSet(string $sDomain): array
    {
        $arCached = Cache::get(static::cacheKey($sDomain));

        return is_array($arCached) ? $arCached : [];
    }

    /**
     * Fetch one sibling's sitemap and cache it. Console only.
     *
     * @return int|null how many urls were stored, null when the sitemap could
     *                  not be read
     */
    public static function warm(string $sDomain): ?int
    {
        $sDomain = rtrim($sDomain, '/');

        $arUrlSet = static::fetch($sDomain);
        if ($arUrlSet === null) {
            // A failed fetch may not replace a good url set with blank alternates
            if (static::urlSet($sDomain) === []) {
                Cache::put(static::cacheKey($sDomain), [], now()->addMinutes(self::TTL_FAILED_MINUTES));
            }

            return null;
        }

        Cache::put(static::cacheKey($sDomain), $arUrlSet, now()->addMinutes(self::TTL_OK_MINUTES));
        static::markWarmed($sDomain);

        return count($arUrlSet);
    }

    public static function forget(string $sDomain): void
    {
        Cache::forget(static::cacheKey(rtrim($sDomain, '/')));
    }

    /**
     * True while a successful warm is still inside its lease, which is what
     * keeps the ten-minute schedule at one cache read per sibling in steady
     * state.
     */
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

    /**
     * When this sibling last warmed successfully, or null when it never did on
     * this box - which reads as stale, the loud direction.
     */
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
     * @return array<string,true>|null null when the sitemap could not be read
     */
    protected static function fetch(string $sDomain): ?array
    {
        try {
            $obResponse = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'nailscosmetics-hreflang/1.0'])
                ->get($sDomain . '/sitemap.xml');
        } catch (\Throwable $obException) {
            // Boundary layer: an unreachable sibling is a cached miss, not a fatal
            Log::warning('SiblingShopSitemap: ' . $sDomain . ' unreachable: ' . $obException->getMessage());

            return null;
        }

        if (!$obResponse->ok()) {
            Log::warning('SiblingShopSitemap: ' . $sDomain . ' answered ' . $obResponse->status());

            return null;
        }

        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $obResponse->body(), $arMatches);
        if (empty($arMatches[1])) {
            Log::warning('SiblingShopSitemap: ' . $sDomain . ' listed no url');

            return null;
        }

        $arUrlSet = [];
        foreach ($arMatches[1] as $sLoc) {
            $arUrlSet[rtrim(html_entity_decode($sLoc), '/')] = true;
        }

        return $arUrlSet;
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
