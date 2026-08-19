<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Schema;
use Lovata\Shopaholic\Models\Offer;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyMatcher;
use Logingrupa\StoreExtender\Classes\Color\FamilyPropertySync;
use Logingrupa\StoreExtender\Models\OfferColor;

/**
 * Class ColorFamilyHelper
 *
 * The storefront query surface of the Color Family property, exposed as the
 * Twig functions color_family_offer_filter and color_family_list. Offer id
 * lists come from FilterShopaholic's FilterByPropertyStore - the same
 * CCache-tagged store the filter panel uses, invalidated by the link model
 * events FamilyPropertySync writes through. ProductCollection's own
 * filterByProperty is deliberately NOT used: it depends on the PropertySet
 * pivot configuration, which this property has no rows in, and fails
 * silently.
 *
 * Fail-safe boundary (catalog render and search-sheet open must never
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

    /** @var bool|null request memo of the confidence column's existence;
     * null = unresolved (same pattern as $mPropertyId - one
     * information-schema query per request at most) */
    protected static $mHasConfidenceColumn = null;

    /**
     * Offer ids carrying the family value - the catalog's offer-card grid
     * for ?color= renders each shade as its own card, ordered by
     * classification confidence (most certainly-that-color first). null
     * when the filter cannot apply (the caller then renders unfiltered); an
     * empty array is a real answer: the slug is filterable but matches
     * nothing.
     *
     * @param mixed $sSlug value slug from ?color=
     * @return array|null
     */
    public static function filterOfferIds($sSlug): ?array
    {
        $arOfferIdList = static::filterIds($sSlug);
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
        if (static::$mHasConfidenceColumn === null) {
            static::$mHasConfidenceColumn = Schema::hasColumn((new OfferColor)->getTable(), 'confidence');
        }
        if (!static::$mHasConfidenceColumn) {
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
     * Shared store lookup behind the ?color= filter and the chip counts:
     * the offer ids linked to one family value, from FilterShopaholic's
     * property store. Offers are both the filtered model and the result
     * model - the product-id variant this once served is gone.
     *
     * @param mixed $sSlug
     * @return array|null
     */
    protected static function filterIds($sSlug): ?array
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
            ->getListByPropertyValue($iPropertyId, trim($sSlug), Offer::class, Offer::class);
    }

    /**
     * Every synced family as a chip, exposed as the Twig function
     * color_family_list - the search sheet's all-family pill row: slug,
     * localized name, swatch hex, active offer (shade) count, the ?color=
     * catalog URL and the match vocabulary for the client-side pill filter.
     *
     * @param mixed $sLocale active locale code
     * @return array [['slug','name','hex','count','url','terms'], ...]
     */
    public static function familyList($sLocale = ColorFamilyMatcher::LOCALE_DEFAULT): array
    {
        $arFamilyMap = (new ColorFamilyMatcher())->families(is_string($sLocale) ? $sLocale : ColorFamilyMatcher::LOCALE_DEFAULT);

        return static::buildChipList($arFamilyMap);
    }

    /**
     * Resolve display entries to rendered chips: per-family offer ids from
     * the filter store, the active offer list read once, and the ?color=
     * catalog URL, assembled by assembleChipList().
     *
     * @param array $arFamilyMap ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array]]
     * @return array [['slug','name','hex','count','url','terms'], ...]
     */
    protected static function buildChipList(array $arFamilyMap): array
    {
        if (empty($arFamilyMap)) {
            return [];
        }

        if (static::getPropertyId() === null) {
            return [];
        }

        $sCatalogUrl = \Lovata\Shopaholic\Classes\Item\CategoryItem::make(self::CATALOG_ROOT_CATEGORY_ID)
            ->getPageUrl(self::CATALOG_PAGE_CODE);
        if (empty($sCatalogUrl)) {
            return [];
        }

        $arOfferIdListBySlug = [];
        foreach (array_keys($arFamilyMap) as $sSlug) {
            $arOfferIdListBySlug[$sSlug] = (array) static::filterIds($sSlug);
        }

        $arActiveOfferIdList = (array) \Lovata\Shopaholic\Classes\Store\OfferListStore::instance()->active->get();

        return static::assembleChipList($arFamilyMap, $arOfferIdListBySlug, $arActiveOfferIdList, $sCatalogUrl);
    }

    /**
     * Pure chip assembly - the unit-testable seam behind buildChipList().
     * The count is the intersect of the family's offer ids with the active
     * offer list, because the theme grid intersects with active offers
     * before it draws: a chip must promise exactly the shades that render.
     * Families whose intersect is empty are dropped AFTER the intersect, so
     * an all-inactive family cannot survive on its raw link count - a chip
     * is a promise of results. terms is the space-joined match vocabulary
     * (all locales, raw + transliterated) the theme's client-side pill
     * filter reads from data-terms.
     *
     * @param array  $arFamilyMap ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array]]
     * @param array  $arOfferIdListBySlug ['<valueSlug>' => [<offerId>, ...]]
     * @param array  $arActiveOfferIdList
     * @param string $sCatalogUrl
     * @return array [['slug','name','hex','count','url','terms'], ...]
     */
    public static function assembleChipList(array $arFamilyMap, array $arOfferIdListBySlug, array $arActiveOfferIdList, string $sCatalogUrl): array
    {
        $arActiveOfferIdMap = array_flip($arActiveOfferIdList);

        $arChipList = [];
        foreach ($arFamilyMap as $sSlug => $arFamily) {
            $arFamilyOfferIdList = (array) ($arOfferIdListBySlug[$sSlug] ?? []);
            $iActiveCount = count(array_intersect_key(array_flip($arFamilyOfferIdList), $arActiveOfferIdMap));
            if ($iActiveCount === 0) {
                continue;
            }

            $arChipList[] = [
                'slug'  => $sSlug,
                'name'  => $arFamily['name'],
                'hex'   => $arFamily['hex'],
                'count' => $iActiveCount,
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
