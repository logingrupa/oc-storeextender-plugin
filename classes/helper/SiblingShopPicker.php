<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Request;
use Site;

/**
 * The other shops of the family, as rows for the visible site picker.
 *
 * The picker lists the sites of THIS install (October's sitePicker), so .lt
 * offered lt, en and ru and .no offered nb-no alone. Latvian is a separate
 * deployment rather than a locale of those installs, so a shopper on .lt or
 * .no had no way to reach it. Crawlers have had the pair since theme 2.5.139
 * from partials/shared/seo/hreflang.htm; this is the same pair for a person.
 *
 * The domain order and the route prefixes come from the hreflang helpers, so
 * the family is listed in one place. The page equivalent is resolved by the
 * same two cache-only readers hreflang uses, and falls back to the sibling's
 * front page: an unfilled map costs the shopper a front page instead of
 * dropping the row, which is the difference between this and an alternate
 * link that has to fail closed.
 */
class SiblingShopPicker
{
    /**
     * How a shop is labelled in the picker.
     *
     * name   the shop's own name for its language, an endonym, never
     *        translated: a Lithuanian shopper looking for Latvian looks for
     *        "Latviešu", not for the Lithuanian word for it.
     * flag   which drawing partials/chrome/flag.htm carries.
     * locale the code that shop's own pages print, for the link's hreflang.
     */
    const SHOP_LABEL = [
        'https://nailscosmetics.no' => ['name' => 'Norsk', 'flag' => 'nb', 'locale' => 'nb-no'],
        'https://nailscosmetics.lv' => ['name' => 'Latviešu', 'flag' => 'lv', 'locale' => 'lv'],
        'https://nailscosmetics.lt' => ['name' => 'Lietuvių', 'flag' => 'lt', 'locale' => 'lt'],
    ];

    /**
     * Twig: shop_sibling_links()
     *
     * @return array<int,array<string,string>> one row per OTHER shop:
     *                                         domain, host, name, flag,
     *                                         locale, url
     */
    public static function links(): array
    {
        $sOwnHost = Request::getHost();
        $sPath = static::currentPath();
        $arLinkList = [];

        foreach (SiblingShopSitemap::CROSS_DOMAIN_LIST as $sDomain) {
            $sHost = (string) parse_url($sDomain, PHP_URL_HOST);
            if ($sHost === '' || $sHost === $sOwnHost) {
                continue;
            }

            $arLabel = self::SHOP_LABEL[$sDomain] ?? ['name' => $sHost, 'flag' => '', 'locale' => ''];

            $arLinkList[] = $arLabel + [
                'domain' => $sDomain,
                'host' => $sHost,
                'url' => static::url($sDomain, $sPath),
            ];
        }

        return $arLinkList;
    }

    /**
     * The sibling's URL for this page, or its front page when no pair is
     * proven. Never null: a picker row with nowhere to go is worse than one
     * that lands on the shop's front page.
     */
    public static function url(string $sDomain, string $sPath): string
    {
        $sDomain = rtrim($sDomain, '/');
        $sPrefix = SiblingShopIdMap::ROUTE_PREFIX[$sDomain] ?? '';
        $sHome = $sDomain . $sPrefix;

        $sPath = '/' . trim($sPath, '/');
        if ($sPath === '/') {
            return $sHome;
        }

        // Cache only, no rebuild: the picker is on every page and the phone
        // product page counts the queries of a cold render
        $sPaired = SiblingShopIdMap::siblingUrlFromCache($sDomain, $sPath);
        if (!empty($sPaired)) {
            return $sPaired;
        }

        // Static pages carry no 1C id and really do share a path across the
        // shops that have them, so the sitemap answer still pairs those
        return SiblingShopSitemap::hasUrl($sDomain, $sPrefix . $sPath)
            ? $sHome . $sPath
            : $sHome;
    }

    /**
     * This request's path with the active site's route prefix removed, so the
     * value is what the family's shops have in common ('/p/slug', not
     * '/lt/p/slug').
     */
    protected static function currentPath(): string
    {
        $sPath = '/' . trim(Request::getPathInfo(), '/');

        $obSite = Site::getActiveSite();
        if (empty($obSite) || empty($obSite->is_prefixed)) {
            return $sPath;
        }

        $sPrefix = '/' . trim((string) $obSite->route_prefix, '/');
        if ($sPrefix === '/') {
            return $sPath;
        }

        // The prefix must end at a segment boundary, or '/lv-something' would
        // be read as the '/lv' site serving '-something'
        if ($sPath !== $sPrefix && strpos($sPath, $sPrefix . '/') !== 0) {
            return $sPath;
        }

        $sPath = substr($sPath, strlen($sPrefix));

        return $sPath === '' ? '/' : $sPath;
    }
}
