<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Schema;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyMatcher;
use Logingrupa\StoreExtender\Classes\Color\FamilyPropertySync;
use Logingrupa\StoreExtender\Models\OfferColor;

/**
 * Class ColorFamilyHelper
 *
 * The storefront query surface of the Color Family property, exposed as the
 * Twig functions color_family_filter, color_family_chips and
 * color_family_list. Product id
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
     * for ?color= renders each shade as its own card, ordered by
     * classification confidence (most certainly-that-color first). Same
     * null/[] contract as filterProductIds.
     *
     * @param mixed $sSlug value slug from ?color=
     * @return array|null
     */
    public static function filterOfferIds($sSlug): ?array
    {
        $arOfferIdList = static::filterIds($sSlug, Offer::class);
        if (empty($arOfferIdList)) {
            return $arOfferIdList;
        }

        return static::orderOfferIdsByConfidence($arOfferIdList);
    }

    /**
     * Order offer ids by their synced color confidence, highest first:
     * inside one family the score says how certainly a shade IS that color,
     * so the reddest reds open the red page. Unscored offers sink to the
     * tail; ties and the tail keep the incoming order. Fail-safe: a missing
     * column (undeployed migration) or no scores at all returns the list
     * untouched.
     *
     * @param array $arOfferIdList
     * @return array
     */
    public static function orderOfferIdsByConfidence(array $arOfferIdList): array
    {
        if (count($arOfferIdList) < 2) {
            return $arOfferIdList;
        }
        if (!Schema::hasColumn('logingrupa_storeextender_offer_colors', 'confidence')) {
            return $arOfferIdList;
        }

        $arUuidByOfferId = Offer::query()
            ->whereIn('id', $arOfferIdList)
            ->pluck('external_id', 'id')
            ->all();
        if (empty($arUuidByOfferId)) {
            return $arOfferIdList;
        }

        $arConfidenceByUuid = OfferColor::query()
            ->whereIn('offer_uuid', array_values($arUuidByOfferId))
            ->whereNotNull('confidence')
            ->pluck('confidence', 'offer_uuid')
            ->all();
        if (empty($arConfidenceByUuid)) {
            return $arOfferIdList;
        }

        $arOfferIdList = array_values($arOfferIdList);
        $arPositionByOfferId = array_flip($arOfferIdList);
        usort(
            $arOfferIdList,
            function ($iFirstId, $iSecondId) use ($arUuidByOfferId, $arConfidenceByUuid, $arPositionByOfferId): int {
                $fFirst = static::resolveConfidence($iFirstId, $arUuidByOfferId, $arConfidenceByUuid);
                $fSecond = static::resolveConfidence($iSecondId, $arUuidByOfferId, $arConfidenceByUuid);

                if (($fFirst === null) !== ($fSecond === null)) {
                    return $fFirst === null ? 1 : -1;
                }
                if ($fFirst !== null && $fFirst !== $fSecond) {
                    return $fSecond <=> $fFirst;
                }

                return $arPositionByOfferId[$iFirstId] <=> $arPositionByOfferId[$iSecondId];
            }
        );

        return $arOfferIdList;
    }

    /**
     * The synced confidence for one offer id, or null when the offer or its
     * score is unknown.
     *
     * @param mixed $iOfferId
     * @param array $arUuidByOfferId
     * @param array $arConfidenceByUuid
     * @return float|null
     */
    protected static function resolveConfidence($iOfferId, array $arUuidByOfferId, array $arConfidenceByUuid): ?float
    {
        $sUuid = $arUuidByOfferId[$iOfferId] ?? null;
        if ($sUuid === null || !isset($arConfidenceByUuid[$sUuid])) {
            return null;
        }

        return (float) $arConfidenceByUuid[$sUuid];
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

        return static::buildChipList($arMatchList);
    }

    /**
     * Every synced family as a chip, exposed as the Twig function
     * color_family_list - the search sheet's all-family pill row. Same shape
     * and zero-count rule as chips(), without the query matching.
     *
     * @param mixed $sLocale active locale code
     * @return array [['slug','name','hex','count','url'], ...]
     */
    public static function familyList($sLocale = ColorFamilyMatcher::LOCALE_DEFAULT): array
    {
        $arFamilyMap = (new ColorFamilyMatcher())->families(is_string($sLocale) ? $sLocale : ColorFamilyMatcher::LOCALE_DEFAULT);

        return static::buildChipList($arFamilyMap);
    }

    /**
     * Resolve display entries to rendered chips: offer (shade) count from
     * the filter store and the ?color= catalog URL. Families whose filter
     * would land on an empty catalog page are dropped - a chip is a promise
     * of results. terms is the space-joined match vocabulary (all locales,
     * raw + transliterated) the theme's client-side pill filter reads from
     * data-terms.
     *
     * @param array $arFamilyMap ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array]]
     * @return array [['slug','name','hex','count','url','terms'], ...]
     */
    protected static function buildChipList(array $arFamilyMap): array
    {
        if (empty($arFamilyMap)) {
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
        foreach ($arFamilyMap as $sSlug => $arFamily) {
            $arOfferIdList = \Lovata\FilterShopaholic\Classes\Store\FilterValueStore::instance()
                ->property
                ->getListByPropertyValue($iPropertyId, $sSlug, Offer::class, Offer::class);
            if (empty($arOfferIdList)) {
                continue;
            }

            $arChipList[] = [
                'slug'  => $sSlug,
                'name'  => $arFamily['name'],
                'hex'   => $arFamily['hex'],
                'count' => count($arOfferIdList),
                'url'   => $sCatalogUrl.'?color='.urlencode($sSlug),
                'terms' => implode(' ', (array) ($arFamily['terms'] ?? [])),
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
