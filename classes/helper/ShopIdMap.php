<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyHubReader;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyMatcher;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;
use Lovata\Shopaholic\Models\Category;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use System\Models\SiteDefinition;

/**
 * The rows this shop publishes: every active category path and every active
 * product path, in the order sitemap.xml lists them.
 *
 * sitemap-products.htm is the one consumer, and the reason the walk lives here
 * rather than in that page is the parent chain. A category path is built by
 * walking parents, the 1C import has reparented this tree before through
 * overlapping runs, and the page's own `while` loop had no cycle bound - two
 * categories parented to each other would spin inside a public page render.
 * The ancestry rule, its visited-id guard and its depth ceiling are tested
 * here instead (tests/integration/ShopIdMapTest.php), which a PHP section in a
 * CMS page cannot be.
 *
 * Keep the predicate and the path shape identical to what the page published
 * before: sitemap.xml is the mechanism a slug rename depends on, because it is
 * what tells the other shops and the crawlers which URLs exist.
 */
class ShopIdMap
{
    /** Row kinds, which the sitemap maps to its priorities */
    const KIND_CATEGORY = 'category';
    const KIND_PRODUCT = 'product';
    const KIND_COLLECTION_HUB = 'collection_hub';
    const KIND_COLLECTION = 'collection';

    /** First path segment of a product page */
    const PRODUCT_SEGMENT = 'p';

    /** Bucket names of the published id map, which is also its JSON shape */
    const BUCKET_PRODUCTS = 'products';
    const BUCKET_CATEGORIES = 'categories';

    /** How deep a category ancestry may go before it is treated as a loop */
    const ANCESTRY_CEILING = 10;

    /**
     * Every URL this shop publishes, categories first then products.
     *
     * @return array<int,array{kind:string,path:string,lastmod:?string}>
     */
    public static function catalogueRows(): array
    {
        $obCategoryList = Category::where('active', true)
            ->select('id', 'slug', 'parent_id', 'updated_at')
            ->get()
            ->keyBy('id');

        $arRowList = [];
        foreach ($obCategoryList as $obCategory) {
            $sPath = static::categoryPath($obCategory, function ($iParentID) use ($obCategoryList) {
                return $obCategoryList[$iParentID] ?? null;
            });

            // A looping parent chain is a data fault, not a page: skip the row
            // rather than hang the sitemap render on a public route
            if ($sPath === null) {
                continue;
            }

            $arRowList[] = [
                'kind' => self::KIND_CATEGORY,
                'path' => $sPath,
                'lastmod' => optional($obCategory->updated_at)->toDateString(),
            ];
        }

        $obProductList = Product::where('active', true)
            ->whereNull('deleted_at')
            ->get(['slug', 'updated_at']);

        foreach ($obProductList as $obProduct) {
            $arRowList[] = [
                'kind' => self::KIND_PRODUCT,
                'path' => static::productPath($obProduct->slug),
                'lastmod' => optional($obProduct->updated_at)->toDateString(),
            ];
        }

        return $arRowList;
    }

    /**
     * The collections hub and every family page worth indexing, hub first.
     *
     * Only indexable families are listed: a page carrying fewer shades than
     * the threshold answers 200 with noindex, and a noindex URL in a sitemap
     * is a contradiction a crawler reports back. The hub itself is always
     * listed while it has at least one family to show.
     *
     * lastmod is the newest shade in the family, because the shade grid IS
     * the page: a family whose offers have not moved has not changed, and the
     * taxonomy row's own timestamp would miss a shade joining the family. It
     * falls back to the taxonomy row and then to today, because the sitemap
     * contract is one lastmod per loc and a row without one fails the suite.
     *
     * @return array<int,array{kind:string,path:string,lastmod:string}>
     */
    public static function collectionRows(): array
    {
        $obReader = new ColorFamilyHubReader();
        $arFamilyList = $obReader->familyList(ColorFamilyMatcher::LOCALE_DEFAULT);
        if (empty($arFamilyList)) {
            return [];
        }

        $arOfferIdListBySlug = [];
        $arAllOfferIdList = [];
        foreach ($arFamilyList as $arFamily) {
            if (!$arFamily['bIsIndexable']) {
                continue;
            }
            $arOfferIdList = $obReader->offerIdList($arFamily['slug']);
            $arOfferIdListBySlug[$arFamily['slug']] = $arOfferIdList;
            $arAllOfferIdList = array_merge($arAllOfferIdList, $arOfferIdList);
        }

        $arUpdatedAtByOfferId = static::offerUpdatedAtMap(array_unique($arAllOfferIdList));
        $arTaxonomyDateBySlug = static::familyUpdatedAtMap(array_keys($arOfferIdListBySlug));
        $sToday = now()->toDateString();

        $arRowList = [];
        $sHubLastmod = null;
        foreach ($arFamilyList as $arFamily) {
            $sSlug = (string) $arFamily['slug'];
            if (!isset($arOfferIdListBySlug[$sSlug])) {
                continue;
            }

            $sLastmod = null;
            foreach ($arOfferIdListBySlug[$sSlug] as $iOfferId) {
                $sOfferDate = $arUpdatedAtByOfferId[$iOfferId] ?? null;
                if ($sOfferDate !== null && $sOfferDate > (string) $sLastmod) {
                    $sLastmod = $sOfferDate;
                }
            }
            if ($sLastmod === null) {
                $sLastmod = $arTaxonomyDateBySlug[$sSlug] ?? $sToday;
            }
            if ($sLastmod > (string) $sHubLastmod) {
                $sHubLastmod = $sLastmod;
            }

            $arRowList[] = [
                'kind' => self::KIND_COLLECTION,
                'path' => ColorFamilyHubReader::familyPath($sSlug),
                'lastmod' => $sLastmod,
            ];
        }

        array_unshift($arRowList, [
            'kind' => self::KIND_COLLECTION_HUB,
            'path' => ColorFamilyHubReader::hubPath(),
            'lastmod' => $sHubLastmod ?? $sToday,
        ]);

        return $arRowList;
    }

    /**
     * Taxonomy row date per family value slug, the lastmod fallback.
     *
     * @param array $arSlugList
     * @return array [<valueSlug> => 'Y-m-d']
     */
    protected static function familyUpdatedAtMap(array $arSlugList): array
    {
        if (empty($arSlugList)) {
            return [];
        }

        $arDateBySlug = [];
        $obMetaList = ColorFamilyMeta::query()
            ->whereIn('value_slug', $arSlugList)
            ->get(['value_slug', 'updated_at']);
        foreach ($obMetaList as $obMeta) {
            $sDate = optional($obMeta->updated_at)->toDateString();
            if ($sDate !== null) {
                $arDateBySlug[(string) $obMeta->value_slug] = $sDate;
            }
        }

        return $arDateBySlug;
    }

    /**
     * Offer id to its updated_at date, rows without a timestamp left out.
     *
     * @param array $arOfferIdList
     * @return array [<offerId> => 'Y-m-d']
     */
    protected static function offerUpdatedAtMap(array $arOfferIdList): array
    {
        if (empty($arOfferIdList)) {
            return [];
        }

        $arUpdatedAtByOfferId = [];
        foreach (Offer::query()->whereIn('id', $arOfferIdList)->get(['id', 'updated_at']) as $obOffer) {
            $sDate = optional($obOffer->updated_at)->toDateString();
            if ($sDate !== null) {
                $arUpdatedAtByOfferId[(int) $obOffer->id] = $sDate;
            }
        }

        return $arUpdatedAtByOfferId;
    }

    /**
     * The 1C id of every row this shop publishes, against its own path.
     *
     * This is what the shops pair on. The hreflang block used to guess that a
     * sibling served the same slug, which stopped being true when the .no
     * slugs were translated on 2026-11-15 and was never true for .lt. The
     * external_id is the same GUID on every shop for the same row, so it is
     * the only cross-shop identity the catalogue has.
     *
     * A row without an external_id is left out, not guessed. Roughly 7% of
     * products carry none and those have no cross-shop identity at all, so
     * they must stay unpaired.
     *
     * @return array{products:array<string,string>,categories:array<string,string>}
     */
    public static function publishedIdMap(): array
    {
        $obCategoryList = Category::where('active', true)
            ->select('id', 'slug', 'parent_id', 'external_id')
            ->get()
            ->keyBy('id');

        $arCategoryMap = [];
        foreach ($obCategoryList as $obCategory) {
            $sExternalID = static::externalId($obCategory->external_id);
            if ($sExternalID === null) {
                continue;
            }

            $sPath = static::categoryPath($obCategory, function ($iParentID) use ($obCategoryList) {
                return $obCategoryList[$iParentID] ?? null;
            });

            // A looping parent chain is a data fault; the sitemap drops the row
            // and so does the pair, rather than publishing a path it invented
            if ($sPath === null) {
                continue;
            }

            $arCategoryMap[$sExternalID] = $sPath;
        }

        $arProductMap = [];
        $obProductList = Product::where('active', true)
            ->whereNull('deleted_at')
            ->get(['slug', 'external_id']);

        foreach ($obProductList as $obProduct) {
            $sExternalID = static::externalId($obProduct->external_id);
            if ($sExternalID === null) {
                continue;
            }

            $arProductMap[$sExternalID] = static::productPath($obProduct->slug);
        }

        return [
            self::BUCKET_PRODUCTS => $arProductMap,
            self::BUCKET_CATEGORIES => $arCategoryMap,
        ];
    }

    /**
     * This shop's own path against the 1C id, the inverse of publishedIdMap().
     *
     * The hreflang block knows the path it is rendering and nothing else, so
     * it needs this direction to find the id before it can ask a sibling for
     * its own path. Keeping both directions here means one definition of which
     * rows are publishable.
     *
     * @return array{products:array<string,string>,categories:array<string,string>}
     */
    public static function ownPathIdMap(): array
    {
        $arMap = static::publishedIdMap();

        return [
            self::BUCKET_PRODUCTS => array_flip($arMap[self::BUCKET_PRODUCTS]),
            self::BUCKET_CATEGORIES => array_flip($arMap[self::BUCKET_CATEGORIES]),
        ];
    }

    /**
     * Which bucket a path of this shop belongs to, read off the path shape so
     * the caller does not have to carry the row kind through the render.
     */
    public static function kindBucketForPath(string $sPath): string
    {
        return strpos($sPath, '/' . self::PRODUCT_SEGMENT . '/') === 0
            ? self::BUCKET_PRODUCTS
            : self::BUCKET_CATEGORIES;
    }

    /**
     * An external_id worth publishing, or null. Empty string and null are the
     * same thing here: no cross-shop identity.
     */
    protected static function externalId($sValue): ?string
    {
        $sExternalID = trim((string) $sValue);

        return $sExternalID === '' ? null : $sExternalID;
    }

    /**
     * The origin this shop serves, scheme and host of its primary site. The
     * warm and forget commands use it to leave their own shop out of the
     * sibling list.
     */
    public static function ownOrigin(): string
    {
        $sBaseUrl = static::primaryBaseUrl();
        $sScheme = (string) parse_url($sBaseUrl, PHP_URL_SCHEME);
        $sHost = (string) parse_url($sBaseUrl, PHP_URL_HOST);
        $iPort = parse_url($sBaseUrl, PHP_URL_PORT);

        return $sScheme . '://' . $sHost . ($iPort ? ':' . $iPort : '');
    }

    /**
     * The primary enabled site's base_url, which already carries the route
     * prefix (https://nailscosmetics.lv/lv), trailing slash removed.
     */
    protected static function primaryBaseUrl(): string
    {
        $obSite = SiteDefinition::where('is_enabled', true)
            ->orderBy('is_primary', 'desc')
            ->orderBy('sort_order')
            ->first();

        if (empty($obSite)) {
            throw new \RuntimeException('ShopIdMap: no enabled site definition, nothing can be published');
        }

        return rtrim((string) $obSite->base_url, '/');
    }

    /**
     * A category path, root slug first, built from the parents the publish
     * predicate keeps - so an active child of an inactive parent is published
     * at its own slug, the way the sitemap has always listed it.
     *
     * Bounded twice on purpose. Overlapping imports have reparented this tree
     * before, so a chain that revisits an id, or that runs past the depth the
     * catalogue uses, is reported and the row dropped rather than looping.
     *
     * @param callable $fnParentOf id -> parent row or null
     * @return string|null null when the chain loops or runs too deep
     */
    protected static function categoryPath($obCategory, callable $fnParentOf): ?string
    {
        $arSlugList = [$obCategory->slug];
        $arVisitedList = [(int) $obCategory->id => true];
        $iParentID = $obCategory->parent_id;

        while (!empty($iParentID)) {
            if (isset($arVisitedList[(int) $iParentID]) || count($arSlugList) > self::ANCESTRY_CEILING) {
                Log::warning('ShopIdMap: category ' . $obCategory->id . ' has a parent chain that loops at ' . $iParentID);

                return null;
            }

            $obParent = $fnParentOf((int) $iParentID);
            if (empty($obParent)) {
                break;
            }

            $arVisitedList[(int) $iParentID] = true;
            $arSlugList[] = $obParent->slug;
            $iParentID = $obParent->parent_id;
        }

        return '/' . implode('/', array_reverse($arSlugList));
    }

    protected static function productPath(string $sSlug): string
    {
        return '/' . self::PRODUCT_SEGMENT . '/' . $sSlug;
    }
}
