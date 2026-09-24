<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;

/**
 * Where a static page URL asked for in another locale's words should go.
 *
 * A static page carries one URL per locale (viewBag url plus localeUrl).
 * The v1 shops served every locale under the base URL, so crawlers still
 * ask for /ru/palidziba/kontakti when the page lives at /ru/pomosh/kontakty.
 */
class LegacyStaticPageResolver
{
    /**
     * The page's URL in $sLocale when some static page owns $sPath under
     * another locale, null when no page owns it or it already lives there.
     * @param string   $sPath         request path without the site prefix, leading slash
     * @param string   $sLocale       the site locale, for example ru or nb-no
     * @param iterable $arPageUrlMaps one [locale => url] map per static page, key base for the viewBag url
     * @return string|null prefix-free URL with a leading slash
     */
    public function resolve(string $sPath, string $sLocale, iterable $arPageUrlMaps): ?string
    {
        if ($sPath === '' || $sPath[0] !== '/') {
            throw new InvalidArgumentException("Path must start with a slash, got: {$sPath}");
        }
        if ($sLocale === '') {
            throw new InvalidArgumentException('Locale must not be empty');
        }

        foreach ($arPageUrlMaps as $arUrlList) {
            if (!in_array($sPath, $arUrlList, true)) {
                continue;
            }

            $sOwnUrl = $arUrlList[$sLocale] ?? $arUrlList['base'];

            return $sOwnUrl === $sPath ? null : $sOwnUrl;
        }

        return null;
    }

    /**
     * The request path with the site route prefix removed.
     * @param string $sRequestPath raw request path, with or without a leading slash
     * @param string $sPrefix      the site route prefix, empty when the site is not prefixed
     */
    public static function stripPrefix(string $sRequestPath, string $sPrefix): string
    {
        $sPath = self::normalizeUrl($sRequestPath);
        $sPrefix = self::normalizeUrl($sPrefix);
        if ($sPrefix === '/') {
            return $sPath;
        }
        if ($sPath === $sPrefix) {
            return '/';
        }
        if (str_starts_with($sPath, $sPrefix . '/')) {
            return substr($sPath, strlen($sPrefix));
        }

        return $sPath;
    }

    /**
     * One leading slash, no trailing slash; the viewBag holds both help/contacts and /help/contacts.
     */
    public static function normalizeUrl(string $sUrl): string
    {
        return '/' . trim($sUrl, '/');
    }
}
