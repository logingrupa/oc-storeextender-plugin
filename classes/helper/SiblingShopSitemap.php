<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which URLs another shop of the family serves, read from its sitemap.xml.
 *
 * The cross-domain hreflang block used to assume every path exists on every
 * shop, so each page advertised two URLs that were often 404 on the other
 * domains (products one shop does not carry, static pages of another
 * language). A link is now emitted only for a URL the other shop lists.
 *
 * The url set is filled by storeextender:warm-sibling-maps, never by a page
 * render: urlSet() is a cache read and nothing else, because hreflang.htm asks
 * it once per cross-domain site and a miss used to fetch a sibling sitemap
 * inline, 5 s timeout each. An unfilled set means no cross-domain link until
 * the next successful warm. That holds on ALL THREE shops, so blank alternates
 * after a cache clear are the fail-closed contract and not a regression; the
 * ten-minute schedule refills them.
 *
 * This surface is only the path fallback, for pages that are no catalogue
 * entity. A product or category resolves through SiblingShopMap by 1C id.
 */
class SiblingShopSitemap
{
    const CACHE_KEY = 'storeextender.sibling_sitemap.';
    const TTL_OK_MINUTES = 24 * 60;
    const TTL_FAILED_MINUTES = 60;
    const TIMEOUT_SECONDS = 5;

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
        SiblingShopMap::markWarmed(SiblingShopMap::SURFACE_SITEMAP, $sDomain);

        return count($arUrlSet);
    }

    public static function forget(string $sDomain): void
    {
        Cache::forget(static::cacheKey(rtrim($sDomain, '/')));
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
}
