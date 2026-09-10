<?php namespace Logingrupa\StoreExtender\Classes\Event\Seo;

use Request;
use Redirect;
use Cms\Classes\Page;
use Cms\Classes\Controller;
use Lovata\Toolbox\Classes\Component\ElementPage;
use Lovata\Shopaholic\Components\CategoryPage;
use Logingrupa\Storeextender\Components\CustomProductPage;
use Logingrupa\StoreExtender\Classes\Helper\LegacyUrlResolver;

/**
 * Turns the 404 a catalogue page is about to serve into a 301 when the
 * resolver knows where the URL went. Runs after the components resolved
 * their slugs and before the page renders, so a live URL costs nothing.
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
        $arParams = $obController->getRouter()->getParameters();
        $arNewParams = $this->productTarget($obPage) ?? $this->categoryTarget($obPage, $arParams);
        if ($arNewParams === null) {
            return null;
        }

        return Redirect::to($obController->pageUrl($obPage->getBaseFileName(), $arNewParams, false), 301);
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
