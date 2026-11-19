<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Logingrupa\StoreExtender\Classes\Helper\ColorFamilyHelper;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;
use Logingrupa\StoreExtender\Models\Settings;
use Lovata\Shopaholic\Classes\Store\OfferListStore;

/**
 * Class ColorFamilyHubReader
 *
 * The read side of the collections hub: which families this shop publishes,
 * how many live shades each one carries and which of them is worth indexing.
 *
 * Membership is DERIVED on every read, never stored. The color classifier
 * re-syncs hourly, so a copied membership pivot gives two answers to "what is
 * in DISCO" within a day. The family -> offer link comes from the same
 * CCache-backed FilterShopaholic property store the catalog filter reads, and
 * the count is the intersect with the active offer list, because that
 * intersect is exactly what the grid draws (OfferCollection::applySorting
 * intersects as it reorders).
 *
 * Fail-safe like the rest of the color surface: an unsynced taxonomy, an
 * absent FilterShopaholic or an unknown slug answers an empty list or null,
 * never an exception. A public hub page must not 500 on missing data.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorFamilyHubReader
{
    /** First path segment of the hub and of every family page */
    const PATH_SEGMENT = 'collections';

    /** Shades a family needs before its page is worth indexing */
    const SHADE_THRESHOLD_DEFAULT = 8;

    /** @var array<string, array> per-request memo of the live shade ids, keyed by family slug */
    protected $arOfferIdListMemo = [];

    /** @var array|null per-request memo of the active offer id list */
    protected $arActiveOfferIdListMemo = null;

    /**
     * Every family this shop can show a page for, collections first in
     * taxonomy order, then colors. A family with no live shade is left out
     * entirely - it gets no chip and no page (Gold and Beige carry zero).
     *
     * @param string $sLocale active locale code
     * @return array [['slug','name','family','kind','hex','background','count','path','bIsIndexable']]
     */
    public function familyList(string $sLocale): array
    {
        $arFamilyMap = $this->resolveFamilyMap($sLocale);
        if (empty($arFamilyMap)) {
            return [];
        }

        $arOfferIdListBySlug = [];
        foreach (array_keys($arFamilyMap) as $sSlug) {
            $arOfferIdListBySlug[(string) $sSlug] = $this->offerIdList((string) $sSlug);
        }

        return static::assembleFamilyList($arFamilyMap, $arOfferIdListBySlug, $this->getShadeThreshold());
    }

    /**
     * The families split the way the hub renders them: the named effects
     * people search for first, the color wheel after.
     *
     * @param string $sLocale
     * @return array ['collections' => array, 'colors' => array]
     */
    public function sectionList(string $sLocale): array
    {
        $arFamilyList = $this->familyList($sLocale);

        return [
            'collections' => array_values(array_filter(
                $arFamilyList,
                fn (array $arFamily) => $arFamily['kind'] === ColorFamilyMeta::KIND_COLLECTION
            )),
            'colors' => array_values(array_filter(
                $arFamilyList,
                fn (array $arFamily) => $arFamily['kind'] !== ColorFamilyMeta::KIND_COLLECTION
            )),
        ];
    }

    /**
     * One family by its value slug, or null when the slug is not a synced
     * family or carries no live shade. This is the family page's 404 rule,
     * and it costs one property-store read rather than the whole hub list.
     *
     * @param string $sSlug
     * @param string $sLocale
     * @return array|null
     */
    public function family(string $sSlug, string $sLocale): ?array
    {
        $arFamilyMap = $this->resolveFamilyMap($sLocale);
        if (!isset($arFamilyMap[$sSlug])) {
            return null;
        }

        $arFamilyList = static::assembleFamilyList(
            [$sSlug => $arFamilyMap[$sSlug]],
            [$sSlug => $this->offerIdList($sSlug)],
            $this->getShadeThreshold()
        );

        return $arFamilyList[0] ?? null;
    }

    /**
     * Live shade ids of one family, in the order the grid draws them
     * (classification confidence first). Empty for an unknown slug.
     *
     * @param string $sSlug
     * @return array
     */
    public function offerIdList(string $sSlug): array
    {
        if (isset($this->arOfferIdListMemo[$sSlug])) {
            return $this->arOfferIdListMemo[$sSlug];
        }

        $this->arOfferIdListMemo[$sSlug] = static::intersectActive(
            (array) ColorFamilyHelper::offerIdsForFamily($sSlug),
            $this->getActiveOfferIdList()
        );

        return $this->arOfferIdListMemo[$sSlug];
    }

    /**
     * Path of a family page, relative to the site route prefix. The sitemap
     * builder prefixes it per site; a template builds its own URL from the
     * page code.
     *
     * @param string $sSlug
     * @return string
     */
    public static function familyPath(string $sSlug): string
    {
        return '/'.self::PATH_SEGMENT.'/'.$sSlug;
    }

    /**
     * Path of the hub itself.
     *
     * @return string
     */
    public static function hubPath(): string
    {
        return '/'.self::PATH_SEGMENT;
    }

    /**
     * Shades a family page needs before it is indexable. A setting rather
     * than a constant so the line can move without a release: six collections
     * sit at five shades or fewer and a thin page is a liability, but the
     * right cut-off is an SEO judgement, not a code one.
     *
     * @return int
     */
    public function getShadeThreshold(): int
    {
        $iThreshold = (int) Settings::get('collection_shade_threshold', self::SHADE_THRESHOLD_DEFAULT);

        return $iThreshold > 0 ? $iThreshold : self::SHADE_THRESHOLD_DEFAULT;
    }

    /**
     * Pure assembly - the unit-testable seam. Collections keep taxonomy order
     * and come first, colors follow in taxonomy order; a family whose live
     * shade count is zero is dropped, so a chip is always a promise of
     * results.
     *
     * @param array $arFamilyMap ['<valueSlug>' => ['name','family','hex','kind','gradient']]
     * @param array $arOfferIdListBySlug ['<valueSlug>' => [<liveOfferId>]]
     * @param int   $iShadeThreshold
     * @return array
     */
    public static function assembleFamilyList(array $arFamilyMap, array $arOfferIdListBySlug, int $iShadeThreshold): array
    {
        $arCollectionList = [];
        $arColorList = [];

        foreach ($arFamilyMap as $sSlug => $arFamily) {
            $sSlug = (string) $sSlug;
            $iCount = count((array) ($arOfferIdListBySlug[$sSlug] ?? []));
            if ($iCount === 0) {
                continue;
            }

            $bIsCollection = ($arFamily['kind'] ?? '') === ColorFamilyMeta::KIND_COLLECTION;
            $arEntry = [
                'slug'         => $sSlug,
                'name'         => (string) ($arFamily['name'] ?? $sSlug),
                'family'       => (string) ($arFamily['family'] ?? ''),
                'kind'         => $bIsCollection ? ColorFamilyMeta::KIND_COLLECTION : ColorFamilyMeta::KIND_COLOR,
                'hex'          => $arFamily['hex'] ?? null,
                'background'   => ColorFamilyHelper::buildSwatchBackground($arFamily['hex'] ?? null, $arFamily['gradient'] ?? null),
                'count'        => $iCount,
                'path'         => static::familyPath($sSlug),
                'bIsIndexable' => $iCount >= $iShadeThreshold,
            ];

            if ($bIsCollection) {
                $arCollectionList[] = $arEntry;
                continue;
            }
            $arColorList[] = $arEntry;
        }

        return array_merge($arCollectionList, $arColorList);
    }

    /**
     * Taxonomy entries for one locale, named by the theme message wherever
     * the shop has its own wording - the same names the offer sheet and the
     * swatch strip print.
     *
     * @param string $sLocale
     * @return array
     */
    protected function resolveFamilyMap(string $sLocale): array
    {
        $arFamilyMap = (new ColorFamilyMatcher())->families($sLocale);
        if (empty($arFamilyMap)) {
            return [];
        }

        return ColorFamilyHelper::applyThemeMessageNames($arFamilyMap, $sLocale);
    }

    /**
     * The family's offers that are live, order of the family list kept.
     *
     * @param array $arOfferIdList
     * @param array $arActiveOfferIdList
     * @return array
     */
    protected static function intersectActive(array $arOfferIdList, array $arActiveOfferIdList): array
    {
        if (empty($arOfferIdList) || empty($arActiveOfferIdList)) {
            return [];
        }

        return array_values(array_intersect($arOfferIdList, $arActiveOfferIdList));
    }

    /**
     * @return array every active offer id
     */
    protected function getActiveOfferIdList(): array
    {
        if ($this->arActiveOfferIdListMemo === null) {
            $this->arActiveOfferIdListMemo = (array) OfferListStore::instance()->active->get();
        }

        return $this->arActiveOfferIdListMemo;
    }
}
