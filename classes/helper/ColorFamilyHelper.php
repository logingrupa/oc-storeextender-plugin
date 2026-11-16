<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Cache;
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

    const CACHE_KEY_PROPERTY_ID = 'storeextender.color_family.property_id';
    const CACHE_KEY_COLUMN_PREFIX = 'storeextender.color_family.';
    const CACHE_TTL_SECONDS = 3600;

    /** @var int|null|false request memo of the property id; false = unresolved */
    protected static $mPropertyId = false;

    /** @var array<string, bool> request memo of each offer color column's
     * existence (same pattern as $mPropertyId - one information-schema query
     * per column and request at most) */
    protected static $arHasColumnMemo = [];

    /**
     * Offer ids carrying the family value - the catalog's offer-card grid
     * for ?color= renders each shade as its own card. A color page orders by
     * classification confidence (most certainly-that-color first), a
     * collection page by shade code. null when the filter cannot apply (the
     * caller then renders unfiltered); an empty array is a real answer: the
     * slug is filterable but matches nothing.
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

        if ((new ColorFamilyMatcher())->isCollectionSlug(trim($sSlug))) {
            return static::orderOfferIdsByShadeCode($arOfferIdList);
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

        $arConfidenceByOfferId = static::readOfferColorColumn($arOfferIdList, 'confidence');
        if (empty($arConfidenceByOfferId)) {
            return $arOfferIdList;
        }

        $arOfferIdList = array_values($arOfferIdList);
        $arPositionByOfferId = array_flip($arOfferIdList);
        usort(
            $arOfferIdList,
            function ($iFirstId, $iSecondId) use ($arConfidenceByOfferId, $arPositionByOfferId): int {
                $fFirst = static::resolveConfidence($iFirstId, $arConfidenceByOfferId);
                $fSecond = static::resolveConfidence($iSecondId, $arConfidenceByOfferId);

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
     * @param array $arConfidenceByOfferId
     * @return float|null
     */
    protected static function resolveConfidence($iOfferId, array $arConfidenceByOfferId): ?float
    {
        if (!isset($arConfidenceByOfferId[$iOfferId])) {
            return null;
        }

        return (float) $arConfidenceByOfferId[$iOfferId];
    }

    /**
     * Order a collection's offer ids by shade code (1C shade number), then
     * confidence, then incoming order. Fail-safe: a missing shade_code
     * column leaves the confidence order a color page gets.
     *
     * @param array $arOfferIdList
     * @return array
     */
    public static function orderOfferIdsByShadeCode(array $arOfferIdList): array
    {
        $arShadeCodeByOfferId = static::readOfferColorColumn($arOfferIdList, 'shade_code');
        if (empty($arShadeCodeByOfferId)) {
            return static::orderOfferIdsByConfidence($arOfferIdList);
        }

        return static::sortOfferIdsByShadeCode(
            $arOfferIdList,
            $arShadeCodeByOfferId,
            static::readOfferColorColumn($arOfferIdList, 'confidence')
        );
    }

    /**
     * Pure order rule of a collection page: shade code by natural order with
     * unnumbered shades after every numbered one, equal codes by confidence
     * descending (unscored last), then the incoming order.
     *
     * @param array $arOfferIdList
     * @param array $arShadeCodeByOfferId [<offerId> => string]
     * @param array $arConfidenceByOfferId [<offerId> => float]
     * @return array
     */
    public static function sortOfferIdsByShadeCode(array $arOfferIdList, array $arShadeCodeByOfferId, array $arConfidenceByOfferId): array
    {
        $arOfferIdList = array_values($arOfferIdList);
        $arPositionByOfferId = array_flip($arOfferIdList);
        usort(
            $arOfferIdList,
            function ($iFirstId, $iSecondId) use ($arShadeCodeByOfferId, $arConfidenceByOfferId, $arPositionByOfferId): int {
                $iShadeOrder = static::compareShadeCodes($arShadeCodeByOfferId[$iFirstId] ?? null, $arShadeCodeByOfferId[$iSecondId] ?? null);
                if ($iShadeOrder !== 0) {
                    return $iShadeOrder;
                }

                $fFirst = static::resolveConfidence($iFirstId, $arConfidenceByOfferId);
                $fSecond = static::resolveConfidence($iSecondId, $arConfidenceByOfferId);
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
     * Natural, case-insensitive shade code order, null after every code.
     *
     * @param string|null $sFirst
     * @param string|null $sSecond
     * @return int
     */
    public static function compareShadeCodes(?string $sFirst, ?string $sSecond): int
    {
        if (($sFirst === null) !== ($sSecond === null)) {
            return $sFirst === null ? 1 : -1;
        }
        if ($sFirst === null) {
            return 0;
        }

        return strnatcasecmp($sFirst, $sSecond);
    }

    /**
     * One synced offer color column for a set of offer ids, rows without a
     * value left out. Fail-safe: a column that is not migrated yet answers
     * an empty array.
     *
     * @param array  $arOfferIdList
     * @param string $sColumn confidence or shade_code
     * @return array [<offerId> => value]
     */
    protected static function readOfferColorColumn(array $arOfferIdList, string $sColumn): array
    {
        if (!static::hasOfferColorColumn($sColumn)) {
            return [];
        }

        $sOfferTable = (new Offer)->getTable();
        $sColorTable = (new OfferColor)->getTable();

        return Offer::query()
            ->join($sColorTable, $sColorTable.'.offer_uuid', '=', $sOfferTable.'.external_id')
            ->whereIn($sOfferTable.'.id', $arOfferIdList)
            ->whereNotNull($sColorTable.'.'.$sColumn)
            ->pluck($sColorTable.'.'.$sColumn, $sOfferTable.'.id')
            ->all();
    }

    /**
     * Whether an offer color column is migrated. Cached: an
     * information-schema read per request buys nothing, and a late migration
     * heals within the TTL.
     *
     * @param string $sColumn
     * @return bool
     */
    protected static function hasOfferColorColumn(string $sColumn): bool
    {
        if (!isset(static::$arHasColumnMemo[$sColumn])) {
            static::$arHasColumnMemo[$sColumn] = (bool) Cache::remember(
                self::CACHE_KEY_COLUMN_PREFIX.$sColumn.'_column',
                self::CACHE_TTL_SECONDS,
                function () use ($sColumn) {
                    return Schema::hasColumn((new OfferColor)->getTable(), $sColumn);
                }
            );
        }

        return static::$arHasColumnMemo[$sColumn];
    }

    /**
     * The one swatch background builder: a gradient wins over the flat hex,
     * the hex over nothing. Only strict #rrggbb stops reach the string, so it
     * is safe inside an inline style.
     *
     * @param mixed $mHex      strict #rrggbb or anything else (ignored)
     * @param mixed $mGradient 2 or 3 strict #rrggbb stops or anything else (ignored)
     * @return string|null
     */
    public static function buildSwatchBackground($mHex, $mGradient): ?string
    {
        $bStrictHex = fn ($mValue) => is_string($mValue) && preg_match('/^#[0-9A-Fa-f]{6}$/', $mValue) === 1;

        if (is_array($mGradient) && count($mGradient) >= 2 && count($mGradient) <= 3
            && count(array_filter($mGradient, $bStrictHex)) === count($mGradient)
        ) {
            return 'linear-gradient(90deg, '.implode(', ', array_values($mGradient)).')';
        }

        return $bStrictHex($mHex) ? $mHex : null;
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
     * @return array [['slug','name','hex','background','count','url','terms'], ...]
     */
    public static function familyList($sLocale = ColorFamilyMatcher::LOCALE_DEFAULT): array
    {
        $sResolvedLocale = is_string($sLocale) ? $sLocale : ColorFamilyMatcher::LOCALE_DEFAULT;
        $arFamilyMap = (new ColorFamilyMatcher())->families($sResolvedLocale);

        return static::buildChipList(static::applyThemeMessageNames($arFamilyMap, $sResolvedLocale));
    }

    /**
     * Name each chip from the theme message keyed on the raw family name, the
     * same source the offer sheet and swatch strip render with ({{ sFamily|_ }}),
     * so the shop's own (plural) wording wins on every surface and locale. The
     * color-lab export name stays as the fallback for a family the theme has
     * no message for, such as a family newly created on nailolab.
     *
     * @param array  $arFamilyMap ['<valueSlug>' => ['name' => string, 'family' => string, ...]]
     * @param string $sLocale     locale the chip names are rendered in
     * @return array same shape, name replaced wherever a theme message exists
     */
    protected static function applyThemeMessageNames(array $arFamilyMap, string $sLocale): array
    {
        if (!class_exists(\RainLab\Translate\Models\Message::class)) {
            return $arFamilyMap;
        }

        foreach ($arFamilyMap as $sSlug => $arFamily) {
            $sFamily = (string) ($arFamily['family'] ?? '');
            if ($sFamily === '') {
                continue;
            }
            // Message::trans echoes the key back when nothing is translated
            $sMessage = (string) \RainLab\Translate\Models\Message::trans($sFamily, [], $sLocale);
            if ($sMessage === '' || $sMessage === $sFamily) {
                continue;
            }
            $arFamilyMap[$sSlug]['name'] = $sMessage;
        }

        return $arFamilyMap;
    }

    /**
     * Resolve display entries to rendered chips: per-family offer ids from
     * the filter store, the active offer list read once, and the ?color=
     * catalog URL, assembled by assembleChipList().
     *
     * @param array $arFamilyMap ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array]]
     * @return array [['slug','name','hex','background','count','url','terms'], ...]
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
     * @param array  $arFamilyMap ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array, 'gradient' => array|null]]
     * @param array  $arOfferIdListBySlug ['<valueSlug>' => [<offerId>, ...]]
     * @param array  $arActiveOfferIdList
     * @param string $sCatalogUrl
     * @return array [['slug','name','hex','background','count','url','terms'], ...]
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
                'slug'       => $sSlug,
                'name'       => $arFamily['name'],
                'hex'        => $arFamily['hex'],
                'background' => static::buildSwatchBackground($arFamily['hex'], $arFamily['gradient'] ?? null),
                'count'      => $iActiveCount,
                'url'        => $sCatalogUrl.'?color='.urlencode($sSlug),
                'terms'      => implode(' ', (array) ($arFamily['terms'] ?? [])),
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

        $iPropertyId = (int) Cache::remember(
            self::CACHE_KEY_PROPERTY_ID,
            self::CACHE_TTL_SECONDS,
            function () {
                // 0, not null: Cache reads a null back as a miss and would
                // re-query on every request until the property is synced
                return (int) \Lovata\PropertiesShopaholic\Models\Property::query()
                    ->where('code', FamilyPropertySync::PROPERTY_CODE)
                    ->value('id');
            }
        );
        if ($iPropertyId > 0) {
            static::$mPropertyId = $iPropertyId;
        }

        return static::$mPropertyId;
    }

    /**
     * Drop the memoized and cached property id. FamilyPropertySync calls
     * this after the property upsert, so the first sync does not leave the
     * storefront reading a cached "not synced yet".
     *
     * @return void
     */
    public static function forgetPropertyId(): void
    {
        static::$mPropertyId = false;
        Cache::forget(self::CACHE_KEY_PROPERTY_ID);
    }
}
