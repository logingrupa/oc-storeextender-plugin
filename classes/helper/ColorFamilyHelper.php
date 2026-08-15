<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyMatcher;
use Logingrupa\StoreExtender\Classes\Color\FamilyPropertySync;

/**
 * Class ColorFamilyHelper
 *
 * The storefront query surface of the Color Family property, exposed as the
 * Twig functions color_family_filter and color_family_chips. Product id
 * lists come from FilterShopaholic's FilterByPropertyStore - the same
 * CCache-tagged store the filter panel uses, invalidated by the link model
 * events FamilyPropertySync writes through. ProductCollection's own
 * filterByProperty is deliberately NOT used: it depends on the PropertySet
 * pivot configuration, which this property has no rows in, and fails
 * silently.
 *
 * Fail-safe boundary (catalog render and search keystrokes must never
 * 500): every miss - FilterShopaholic absent, property not yet synced,
 * unknown slug - degrades to null (no filtering) or an empty chip list.
 *
 * @package Logingrupa\StoreExtender\Classes\Helper
 */
class ColorFamilyHelper
{
    const CATALOG_PAGE_CODE = 'catalog';
    const CATALOG_ROOT_CATEGORY_ID = 1;

    /** @var int|null|false request memo of the property id; false = unresolved */
    protected static $mPropertyId = false;

    /**
     * Product ids whose offers carry the family value, or null when the
     * filter cannot apply (the caller then renders unfiltered). An empty
     * array is a real answer: the slug is filterable but matches nothing.
     *
     * @param mixed $sSlug value slug from ?color=
     * @return array|null
     */
    public static function filterProductIds($sSlug): ?array
    {
        return static::filterIds($sSlug, Product::class);
    }

    /**
     * Offer ids carrying the family value - the catalog's offer-card grid
     * for ?color= renders each shade as its own card. Same null/[] contract
     * as filterProductIds.
     *
     * @param mixed $sSlug value slug from ?color=
     * @return array|null
     */
    public static function filterOfferIds($sSlug): ?array
    {
        return static::filterIds($sSlug, Offer::class);
    }

    /**
     * Shared store lookup behind both filters. The result model picks the
     * cache bucket: Product::class maps offer links to their products,
     * Offer::class returns the linked offers themselves.
     *
     * @param mixed  $sSlug
     * @param string $sResultModel
     * @return array|null
     */
    protected static function filterIds($sSlug, string $sResultModel): ?array
    {
        if (!is_string($sSlug) || trim($sSlug) === '') {
            return null;
        }

        $iPropertyId = static::getPropertyId();
        if ($iPropertyId === null) {
            return null;
        }

        return \Lovata\FilterShopaholic\Classes\Store\FilterValueStore::instance()
            ->property
            ->getListByPropertyValue($iPropertyId, trim($sSlug), Offer::class, $sResultModel);
    }

    /**
     * Search chips for the families a query names: slug, localized name,
     * swatch hex, offer (shade) count and the ?color= catalog URL. Families
     * whose filter would land on an empty catalog page are dropped - a chip
     * is a promise of results.
     *
     * @param mixed $sQuery raw search input
     * @param mixed $sLocale active locale code
     * @return array [['slug','name','hex','count','url'], ...]
     */
    public static function chips($sQuery, $sLocale = ColorFamilyMatcher::LOCALE_DEFAULT): array
    {
        if (!is_string($sQuery) || $sQuery === '') {
            return [];
        }

        $arMatchList = (new ColorFamilyMatcher())->match($sQuery, is_string($sLocale) ? $sLocale : ColorFamilyMatcher::LOCALE_DEFAULT);
        if (empty($arMatchList)) {
            return [];
        }

        $iPropertyId = static::getPropertyId();
        if ($iPropertyId === null) {
            return [];
        }

        $sCatalogUrl = \Lovata\Shopaholic\Classes\Item\CategoryItem::make(self::CATALOG_ROOT_CATEGORY_ID)
            ->getPageUrl(self::CATALOG_PAGE_CODE);
        if (empty($sCatalogUrl)) {
            return [];
        }

        $arChipList = [];
        foreach ($arMatchList as $sSlug => $arMatch) {
            $arOfferIdList = \Lovata\FilterShopaholic\Classes\Store\FilterValueStore::instance()
                ->property
                ->getListByPropertyValue($iPropertyId, $sSlug, Offer::class, Offer::class);
            if (empty($arOfferIdList)) {
                continue;
            }

            $arChipList[] = [
                'slug'  => $sSlug,
                'name'  => $arMatch['name'],
                'hex'   => $arMatch['hex'],
                'count' => count($arOfferIdList),
                'url'   => $sCatalogUrl.'?color='.urlencode($sSlug),
            ];
        }

        return $arChipList;
    }

    /**
     * The color_family property id, resolved by code once per request.
     * null when FilterShopaholic/PropertiesShopaholic are absent or the
     * property has not been synced yet.
     *
     * @return int|null
     */
    protected static function getPropertyId(): ?int
    {
        if (static::$mPropertyId !== false) {
            return static::$mPropertyId;
        }

        static::$mPropertyId = null;
        if (!class_exists(\Lovata\PropertiesShopaholic\Models\Property::class)
            || !class_exists(\Lovata\FilterShopaholic\Classes\Store\FilterValueStore::class)
        ) {
            return null;
        }

        $obProperty = \Lovata\PropertiesShopaholic\Models\Property::query()
            ->where('code', FamilyPropertySync::PROPERTY_CODE)
            ->first();
        if (!empty($obProperty)) {
            static::$mPropertyId = (int) $obProperty->id;
        }

        return static::$mPropertyId;
    }
}
