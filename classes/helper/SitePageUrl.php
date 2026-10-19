<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cms\Classes\Page;
use Cms\Classes\Theme;
use October\Rain\Router\Router as RainRouter;
use System\Models\SiteDefinition;
use RuntimeException;

/**
 * Class SitePageUrl
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * The absolute URL of a theme page on a given site, built from the page pattern and base URL
 * the CMS router uses for that site. Page::url() and Cms::siteUrl() carry the current
 * request's route prefix, router parameters or query string into the URL instead.
 */
class SitePageUrl
{
    /**
     * @param SiteDefinition $obSite
     * @param string $sPageName theme page file name without the extension
     * @param array $arParamList the page's route parameters, nothing else is merged in
     * @return string
     */
    public static function make(SiteDefinition $obSite, string $sPageName, array $arParamList): string
    {
        $obPage = Page::loadCached(Theme::getActiveTheme(), $sPageName);
        if (empty($obPage)) {
            throw new RuntimeException('The active theme has no page "'.$sPageName.'" to link to');
        }

        $sPattern = $obPage->getTranslatableUrl($obSite) ?: $obPage->url;

        return $obSite->base_url.(new RainRouter)->urlFromPattern($sPattern, $arParamList);
    }
}
