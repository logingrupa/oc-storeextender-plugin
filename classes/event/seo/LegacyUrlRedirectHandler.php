<?php namespace Logingrupa\StoreExtender\Classes\Event\Seo;

use Url;
use Site;
use Request;
use Redirect;
use Cms\Classes\Page;
use Cms\Classes\Theme;
use Cms\Classes\Controller;
use RainLab\Pages\Classes\Page as StaticPage;
use Lovata\Toolbox\Classes\Component\ElementPage;
use Lovata\Shopaholic\Components\CategoryPage;
use Logingrupa\Storeextender\Components\CustomProductPage;
use Logingrupa\StoreExtender\Classes\Helper\LegacyUrlResolver;
use Logingrupa\StoreExtender\Classes\Helper\LegacyStaticPageResolver;

/**
 * Turns the 404 a catalogue page is about to serve into a 301 when the
 * resolver knows where the URL went. Runs after the components resolved
 * their slugs and before the page renders, so a live URL costs nothing.
 *
 * A static page asked for by another locale's URL gets the same treatment,
 * whether the request fell through to the theme 404 page or was caught by a
 * catalogue route first: Toolbox builds its 404 from the nested run's content
 * alone, so the redirect has to happen on the catalogue page itself.
 */
class LegacyUrlRedirectHandler
{
    public function subscribe($obEvent)
    {
        $obEvent->listen('cms.page.init', function (Controller $obController, Page $obPage) {
            if (Request::ajax() || !Request::isMethod('GET')) {
                return null;
            }

            return $this->redirectFor($obController, $obPage);
        });
    }

    protected function redirectFor(Controller $obController, Page $obPage)
    {
        if (!$this->aboutToServe404($obPage)) {
            return null;
        }

        $sStaticPageUrl = $this->staticPageTarget();
        if ($sStaticPageUrl !== null) {
            return Redirect::to($sStaticPageUrl, 301);
        }

        $arParams = $obController->getRouter()->getParameters();
        $arNewParams = $this->productTarget($obPage) ?? $this->categoryTarget($obPage, $arParams);
        if ($arNewParams === null) {
            return null;
        }

        return Redirect::to($obController->pageUrl($obPage->getBaseFileName(), $arNewParams, false), 301);
    }

    /**
     * The theme 404 page, or a catalogue page whose slug found nothing.
     */
    protected function aboutToServe404(Page $obPage): bool
    {
        return $obPage->getBaseFileName() === '404'
            || $this->missingComponent($obPage, CustomProductPage::class) !== null
            || $this->missingComponent($obPage, CategoryPage::class) !== null;
    }

    /**
     * The absolute URL of the static page the request stands for, in the
     * active site's locale, or null.
     */
    protected function staticPageTarget(): ?string
    {
        $obSite = Site::getActiveSite();
        $sPrefix = $obSite->is_prefixed ? LegacyStaticPageResolver::normalizeUrl((string) $obSite->route_prefix) : '';
        $sPath = LegacyStaticPageResolver::stripPrefix(Request::path(), $sPrefix);
        $sOwnUrl = (new LegacyStaticPageResolver)->resolve($sPath, (string) $obSite->locale, $this->staticPageUrlMaps());

        return $sOwnUrl === null ? null : Url::to($sPrefix . $sOwnUrl);
    }

    /**
     * One [locale => url] map per static page of the active theme, the
     * viewBag url under the key base. RainLab Translate rewrites the loaded
     * url to the active locale, the original attributes keep the base one.
     * @return iterable<array<string, string>>
     */
    protected function staticPageUrlMaps(): iterable
    {
        foreach (StaticPage::listInTheme(Theme::getActiveTheme(), true) as $obStaticPage) {
            $arViewBag = (array) array_get($obStaticPage->getOriginal(), 'viewBag', []);
            $arUrlList = ['base' => LegacyStaticPageResolver::normalizeUrl((string) ($arViewBag['url'] ?? $obStaticPage->url))];
            foreach ((array) ($arViewBag['localeUrl'] ?? []) as $sLocale => $sUrl) {
                if (trim((string) $sUrl) !== '') {
                    $arUrlList[$sLocale] = LegacyStaticPageResolver::normalizeUrl((string) $sUrl);
                }
            }

            yield $arUrlList;
        }
    }

    protected function productTarget(Page $obPage): ?array
    {
        $obComponent = $this->missingComponent($obPage, CustomProductPage::class);
        if (empty($obComponent)) {
            return null;
        }

        $sNewSlug = (new LegacyUrlResolver)->resolveProductSlug((string) $obComponent->property('slug'));

        return $sNewSlug === null ? null : ['slug' => $sNewSlug];
    }

    protected function categoryTarget(Page $obPage, array $arParams): ?array
    {
        if (empty($this->missingComponent($obPage, CategoryPage::class))) {
            return null;
        }

        $arParamNames = $this->urlParamNames($obPage);
        $arSlugList = array_values(array_filter(array_map(fn($sName) => $arParams[$sName] ?? null, $arParamNames)));
        $arNewPath = (new LegacyUrlResolver)->resolveCategoryPath($arSlugList);
        if ($arNewPath === null || count($arNewPath) > count($arParamNames)) {
            return null;
        }

        return array_combine(array_slice($arParamNames, 0, count($arNewPath)), $arNewPath);
    }

    /**
     * The first component of $sClass on the page that was given a slug and
     * found nothing for it.
     */
    protected function missingComponent(Page $obPage, string $sClass): ?ElementPage
    {
        foreach ($obPage->components as $obComponent) {
            if (!$obComponent instanceof $sClass) {
                continue;
            }
            if (!empty($obComponent->property('slug')) && $obComponent->get() === null) {
                return $obComponent;
            }
        }

        return null;
    }

    /**
     * @return string[] the :param names of the page URL, in order
     */
    protected function urlParamNames(Page $obPage): array
    {
        preg_match_all('/:([a-z0-9_]+)/i', (string) $obPage->url, $arMatches);

        return $arMatches[1];
    }
}
