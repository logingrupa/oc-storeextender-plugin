<?php namespace Logingrupa\StoreExtender\Classes\Registrar;

use Event;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Models\Category as ShopaholicCategoryModel;

/**
 * PageLookupRegistrar registers every pagefinder lookup type this plugin owns:
 * the id-keyed product type, the slug-keyed product and promo block types and
 * the slug-keyed category type.
 *
 * The directory is lowercase on purpose. October resolves a plugin class path
 * from the lowercased namespace, so a capital Registrar/ directory loads on
 * Windows and aborts october:migrate on the Linux deploy.
 */
class PageLookupRegistrar
{
    /**
     * registerProductPageLookupType registers a "shop-product" type for the
     * pagefinder widget so individual products can be selected as link targets.
     */
    public static function registerProductPageLookupType()
    {
        // Register shop-product type on both event sets (same pattern as RainLab Pages)
        Event::listen(['cms.pageLookup.listTypes', 'pages.menuitem.listTypes'], function () {
            return ['shop-product' => 'Product'];
        });

        Event::listen(['cms.pageLookup.getTypeInfo', 'pages.menuitem.getTypeInfo'], function ($sType) {
            if ($sType !== 'shop-product') {
                return;
            }

            $arReferences = \Lovata\Shopaholic\Models\Product::lists('name', 'id');

            $obTheme = \Cms\Classes\Theme::getActiveTheme();
            $obPageList = \Cms\Classes\Page::listInTheme($obTheme, true);
            $arCmsPages = [];
            foreach ($obPageList as $obPage) {
                if (!$obPage->hasComponent('CustomProductPage')) {
                    continue;
                }

                $arPropertyList = $obPage->getComponentProperties('CustomProductPage');
                if (!isset($arPropertyList['slug']) || !preg_match('/{{\s*:/', $arPropertyList['slug'])) {
                    continue;
                }

                $arCmsPages[] = $obPage;
            }

            return [
                'references' => $arReferences,
                'cmsPages' => $arCmsPages,
            ];
        });

        Event::listen(['cms.pageLookup.resolveItem', 'pages.menuitem.resolveItem'], function ($sType, $obItem, $sURL) {
            if ($sType !== 'shop-product') {
                return;
            }

            if (empty($obItem->reference)) {
                return [];
            }

            $obProductItem = \Lovata\Shopaholic\Classes\Item\ProductItem::make($obItem->reference);
            if ($obProductItem->isEmpty()) {
                return [];
            }

            // Build URL via Page::url() because getPageUrl() fails when the
            // ProductPage component is aliased as "CustomProductPage ProductPage"
            // - Lovata's PageHelper regex only matches keys starting with "ProductPage".
            $sPageUrl = \Cms\Classes\Page::url(
                $obItem->cmsPage ?: 'product',
                ['slug' => $obProductItem->slug]
            );

            return [
                'title' => $obProductItem->name,
                'url' => $sPageUrl,
                'isActive' => $sPageUrl == $sURL,
                'mtime' => $obProductItem->updated_at,
            ];
        });

        // Mirror Shopaholic's category/catalog types to cms.pageLookup.* events
        // (Shopaholic only registers on pages.menuitem.* - pagefinder needs both)
        $arShopaholicMenuTypes = [
            \Lovata\Shopaholic\Classes\Helper\CatalogMenuType::MENU_TYPE => \Lovata\Shopaholic\Classes\Helper\CatalogMenuType::class,
            \Lovata\Shopaholic\Classes\Helper\CategoryMenuType::MENU_TYPE => \Lovata\Shopaholic\Classes\Helper\CategoryMenuType::class,
            \Lovata\Shopaholic\Classes\Helper\AllCategoriesMenuType::MENU_TYPE => \Lovata\Shopaholic\Classes\Helper\AllCategoriesMenuType::class,
        ];

        Event::listen('cms.pageLookup.listTypes', function () {
            return [
                \Lovata\Shopaholic\Classes\Helper\CatalogMenuType::MENU_TYPE => 'lovata.shopaholic::lang.menu.shop_catalog',
                \Lovata\Shopaholic\Classes\Helper\CategoryMenuType::MENU_TYPE => 'lovata.shopaholic::lang.menu.shop_category',
                \Lovata\Shopaholic\Classes\Helper\AllCategoriesMenuType::MENU_TYPE => 'lovata.shopaholic::lang.menu.all_shop_categories',
            ];
        });

        Event::listen('cms.pageLookup.getTypeInfo', function ($sType) use ($arShopaholicMenuTypes) {
            if (!isset($arShopaholicMenuTypes[$sType])) {
                return;
            }
            return (new $arShopaholicMenuTypes[$sType]())->getMenuTypeInfo();
        });

        Event::listen('cms.pageLookup.resolveItem', function ($sType, $obItem, $sURL) use ($arShopaholicMenuTypes) {
            if (!isset($arShopaholicMenuTypes[$sType])) {
                return;
            }
            return (new $arShopaholicMenuTypes[$sType]())->resolveMenuItem($obItem, $sURL);
        });
    }

    /**
     * registerSlugPageLookupTypes registers slug-keyed pagefinder types for
     * products and promo blocks.
     *
     * The id-keyed "shop-product" type has to load the whole ProductItem -
     * offers, images, categories - only to read one field, its slug. Measured
     * on the home page's seven campaign banners that is 140 queries on a cold
     * cache, 20 per banner, against 0 for these types: the route takes a slug
     * and the stored reference already IS the slug, so nothing has to be
     * loaded to build the URL.
     *
     * Slugs are also stable across environments where ids are not, so one
     * theme-data record works on local, .lv, .no and .lt alike.
     *
     * @return void
     */
    public static function registerSlugPageLookupTypes(): void
    {
        $arTypeList = [
            'shop-product-slug' => [
                'label'    => 'Product (by slug)',
                'page'     => 'product',
                'model'    => \Lovata\Shopaholic\Models\Product::class,
                'listType' => 'shop-product-slug',
            ],
            'shop-promo-block-slug' => [
                'label'    => 'Promo block (by slug)',
                'page'     => 'promo-block-page',
                'model'    => \Lovata\Shopaholic\Models\PromoBlock::class,
                'listType' => 'shop-promo-block-slug',
            ],
        ];

        Event::listen(['cms.pageLookup.listTypes', 'pages.menuitem.listTypes'], function () use ($arTypeList) {
            return array_map(function ($arType) {
                return $arType['label'];
            }, $arTypeList);
        });

        Event::listen(['cms.pageLookup.getTypeInfo', 'pages.menuitem.getTypeInfo'], function ($sType) use ($arTypeList) {
            if (!isset($arTypeList[$sType])) {
                return;
            }

            // The backend dropdown is keyed by slug, so what the editor picks is
            // what gets stored - no id ever enters the value
            $sModelClass = $arTypeList[$sType]['model'];
            $arReferences = $sModelClass::orderBy('name')->pluck('name', 'slug')->all();

            return [
                'references'    => $arReferences,
                'nesting'       => false,
                'dynamicItems'  => false,
            ];
        });

        Event::listen(['cms.pageLookup.resolveItem', 'pages.menuitem.resolveItem'], function ($sType, $obItem, $sURL) use ($arTypeList) {
            if (!isset($arTypeList[$sType])) {
                return;
            }

            $sSlug = (string) ($obItem->reference ?? '');
            if ($sSlug === '') {
                return [];
            }

            // No model load: Page::url resolves the route in the ACTIVE locale,
            // which is what makes one stored value render /lv/, /en/ and /ru/
            // links from the same repeater row
            $sPageUrl = \Cms\Classes\Page::url(
                $obItem->cmsPage ?: $arTypeList[$sType]['page'],
                ['slug' => $sSlug]
            );

            return [
                'title'    => $sSlug,
                'url'      => $sPageUrl,
                'isActive' => $sPageUrl == $sURL,
            ];
        });

        self::registerCategorySlugPageLookupType();
    }

    /**
     * registerCategorySlugPageLookupType registers a slug-keyed pagefinder
     * type for shop categories.
     *
     * Shopaholic's own id-keyed shop-category type is listed on the pagefinder
     * events but its resolver throws (getFileName() on null), so banners kept
     * absolute https://nailscosmetics.lv/lv/... URLs instead - which send an
     * /en/ or /ru/ visitor to the Latvian page, and on any other server off
     * the site entirely.
     *
     * The catalog route takes the whole ancestor chain
     * (:main_category/:category?/:sub_category?/:sub2_category?), so unlike
     * the product and promo block types this one has to load the category to
     * learn its parents. One indexed slug lookup per banner, memoized per
     * request, against the category tree the menu already primed.
     *
     * @return void
     */
    public static function registerCategorySlugPageLookupType(): void
    {
        $sType = 'shop-category-slug';

        Event::listen(['cms.pageLookup.listTypes', 'pages.menuitem.listTypes'], function () use ($sType) {
            return [$sType => 'Category (by slug)'];
        });

        Event::listen(['cms.pageLookup.getTypeInfo', 'pages.menuitem.getTypeInfo'], function ($sRequestedType) use ($sType) {
            if ($sRequestedType !== $sType) {
                return;
            }

            return [
                'references'   => ShopaholicCategoryModel::orderBy('name')->pluck('name', 'slug')->all(),
                'nesting'      => false,
                'dynamicItems' => false,
            ];
        });

        Event::listen(['cms.pageLookup.resolveItem', 'pages.menuitem.resolveItem'], function ($sRequestedType, $obItem, $sURL) use ($sType) {
            if ($sRequestedType !== $sType) {
                return;
            }

            $sSlug = (string) ($obItem->reference ?? '');
            if ($sSlug === '') {
                return [];
            }

            static $arCategoryIdList = [];
            if (!array_key_exists($sSlug, $arCategoryIdList)) {
                $arCategoryIdList[$sSlug] = ShopaholicCategoryModel::getBySlug($sSlug)->value('id');
            }

            if (empty($arCategoryIdList[$sSlug])) {
                return [];
            }

            $obCategoryItem = CategoryItem::make($arCategoryIdList[$sSlug]);
            if ($obCategoryItem->isEmpty()) {
                return [];
            }

            // getPageUrl builds the ancestor params and resolves the route in
            // the ACTIVE locale, so one stored value renders /lv/, /en/ and
            // /ru/ links from the same repeater row
            $sPageUrl = $obCategoryItem->getPageUrl($obItem->cmsPage ?: 'catalog');

            return [
                'title'    => $obCategoryItem->name,
                'url'      => $sPageUrl,
                'isActive' => $sPageUrl == $sURL,
            ];
        });
    }
}
