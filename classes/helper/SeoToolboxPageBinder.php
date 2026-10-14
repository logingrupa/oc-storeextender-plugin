<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;
use ReflectionProperty;
use Lovata\MightySeo\Classes\Item\SeoParamItem;
use Lovata\MightySeo\Components\SeoToolbox;

/**
 * MightySeo keys page-level SEO params by the CMS page file name, and the only
 * product row it holds is the one for page code product. Both product pages
 * bind that row through the layout's SeoToolbox: product.htm for both of its
 * head branches, and product-legacy.htm, which has no row of its own and
 * serves an empty title without the bind.
 * Twig, registered in Plugin::registerMarkupTags() as seo_toolbox_for_page.
 */
class SeoToolboxPageBinder
{
    const PAGE_ITEM_PROPERTY = 'obPageSeoParamItem';

    /**
     * Point the component at the params row of a named page and at the model
     * whose own seo_param wins over it, the same way the upstream onRun and
     * onRender do. Every getter stays the upstream code.
     * @param SeoToolbox $obComponent      the layout's component instance
     * @param string     $sPageCode        CMS page file base name whose row to read, e.g. product
     * @param mixed      $obModel          Item or model with a seo_param relation, null for none
     * @param array      $arTemplateParams values the seo templates read, e.g. ['product' => $obProductItem]
     * @return SeoToolbox the same instance, bound
     */
    public static function bind(SeoToolbox $obComponent, string $sPageCode, $obModel, array $arTemplateParams): SeoToolbox
    {
        if (trim($sPageCode) === '') {
            throw new InvalidArgumentException('SeoToolboxPageBinder: page code is empty');
        }

        $obComponent->setProperty('model', $obModel);
        $obComponent->setProperty('params', $arTemplateParams);
        $obComponent->onRender();

        $obPageItemProperty = new ReflectionProperty(SeoToolbox::class, self::PAGE_ITEM_PROPERTY);
        $obPageItemProperty->setValue($obComponent, SeoParamItem::make($sPageCode));

        return $obComponent;
    }
}
