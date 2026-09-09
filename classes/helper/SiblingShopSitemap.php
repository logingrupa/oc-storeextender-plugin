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
 * Fails closed: an unreachable sitemap means no cross links until the next
 * successful fetch, never a link to a page that may not exist.
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
     * @return array<string,true> every <loc> of the sitemap, trailing slash trimmed
     */
    public static function urlSet(string $sDomain): array
    {
        $sKey = self::CACHE_KEY . md5($sDomain);
        $arCached = Cache::get($sKey);
        if (is_array($arCached)) {
            return $arCached;
        }

        $arUrlSet = static::fetch($sDomain);
        $iMinutes = $arUrlSet === null ? self::TTL_FAILED_MINUTES : self::TTL_OK_MINUTES;
        Cache::put($sKey, $arUrlSet ?? [], now()->addMinutes($iMinutes));

        return $arUrlSet ?? [];
    }

    public static function forget(string $sDomain): void
    {
        Cache::forget(self::CACHE_KEY . md5(rtrim($sDomain, '/')));
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
            Log::warning('SiblingShopSitemap: ' . $sDomain . ' unreachable: ' . $obException->getMessage());

            return null;
        }

        if (!$obResponse->ok()) {
            Log::warning('SiblingShopSitemap: ' . $sDomain . ' answered ' . $obResponse->status());

            return null;
        }

        preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#', $obResponse->body(), $arMatches);
        if (empty($arMatches[1])) {
            return null;
        }

        $arUrlSet = [];
        foreach ($arMatches[1] as $sLoc) {
            $arUrlSet[rtrim(html_entity_decode($sLoc), '/')] = true;
        }

        return $arUrlSet;
    }
}
