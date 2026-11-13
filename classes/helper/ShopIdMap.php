<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cache;
use Db;
use Illuminate\Support\Facades\Log;
use Lovata\Shopaholic\Models\Category;
use Lovata\Shopaholic\Models\Product;
use System\Models\SiteDefinition;

/**
 * This shop's own catalogue, in both directions, for the family of shops.
 *
 * Publish side: which 1C external_id lives at which absolute URL here. The
 * sibling shops read that over /api/shop-id-map and use it to name this shop
 * in their hreflang block, so a product keeps its cross-language links even
 * when the three shops carry slugs in three different languages.
 *
 * Lookup side: resolvePath() classifies the page being rendered. The consumer
 * needs "this path is no catalogue entity" apart from "an entity this shop
 * cannot publish": the first may fall back to a path check, the second must
 * emit nothing, because another shop's sitemap may hold the same path for a
 * DIFFERENT product.
 *
 * One builder for both, and the sitemap page consumes catalogueRows() too, so
 * the path contract and the predicate cannot drift between the three. A path
 * classifies as an entity only when it equals the path the publish side would
 * produce for that row, ancestry rule included.
 *
 * The lookup runs on the page's CANONICAL path by design: hreflang.htm prefers
 * sPageCanonicalPath, and a product page publishes its canonical with the slug
 * alone. So a shade URL (/p/slug/:offer) is classified as its product and
 * INHERITS the product's sibling alternates, which is what agreeing with the
 * canonical means. resolvePath() still answers "entity, nothing publishable"
 * for a raw path carrying extra segments, for any caller that hands it one.
 */
class ShopIdMap
{
    /** catalogueRows() row kinds, which the sitemap maps to its priorities */
    const KIND_CATEGORY = 'category';
    const KIND_PRODUCT = 'product';

    /** resolvePath() page classifications */
    const PAGE_ENTITY = 'entity';
    const PAGE_NONE = 'none';

    /** First path segment of a product page */
    const PRODUCT_SEGMENT = 'p';

    /** How deep a category ancestry walk may go before it is treated as a loop */
    const ANCESTRY_CEILING = 10;

    const BODY_CACHE_KEY = 'storeextender.shop_id_map.body.';
    const BODY_TTL_MINUTES = 10;

    /** @var array<string,array> resolvePath() answers, per normalised path */
    protected static $arResolvedPathList = [];

    /**
     * Every URL this shop publishes, categories first then products, in the
     * order the sitemap lists them.
     *
     * Rows with no external_id are INCLUDED: presence here is the sitemap's
     * predicate and nothing else. Only publishMap() filters on the id.
     *
     * @return array<int,array{kind:string,path:string,external_id:?string,lastmod:?string}>
     */
    public static function catalogueRows(): array
    {
        $obCategoryList = Category::where('active', true)
            ->select('id', 'slug', 'parent_id', 'updated_at', 'external_id')
            ->get()
            ->keyBy('id');

        $arRowList = [];
        foreach ($obCategoryList as $obCategory) {
            $sPath = static::categoryPath($obCategory, function ($iParentID) use ($obCategoryList) {
                return $obCategoryList[$iParentID] ?? null;
            });

            // A looping parent chain is a data fault, not a page: skip the row
            // rather than hang the sitemap and this map on a public route
            if ($sPath === null) {
                continue;
            }

            $arRowList[] = [
                'kind' => self::KIND_CATEGORY,
                'path' => $sPath,
                'external_id' => $obCategory->external_id,
                'lastmod' => optional($obCategory->updated_at)->toDateString(),
            ];
        }

        $obProductList = Product::where('active', true)
            ->whereNull('deleted_at')
            ->get(['slug', 'updated_at', 'external_id']);

        foreach ($obProductList as $obProduct) {
            $arRowList[] = [
                'kind' => self::KIND_PRODUCT,
                'path' => static::productPath($obProduct->slug),
                'external_id' => $obProduct->external_id,
                'lastmod' => optional($obProduct->updated_at)->toDateString(),
            ];
        }

        return $arRowList;
    }

    /**
     * What /api/shop-id-map publishes: the raw 1C GUID of every publishable
     * row against the absolute URL it serves here.
     *
     * @return array<string,string>
     */
    public static function publishMap(): array
    {
        $sBaseUrl = static::primaryBaseUrl();

        $arMap = [];
        foreach (static::catalogueRows() as $arRow) {
            $sExternalId = trim((string) $arRow['external_id']);
            if ($sExternalId === '') {
                continue;
            }

            // A 1C GUID names one object, so a second row holding it is a data
            // fault: keep the first and say so, never overwrite silently.
            if (isset($arMap[$sExternalId])) {
                Log::warning('ShopIdMap: external_id ' . $sExternalId . ' is on more than one row, kept ' . $arMap[$sExternalId]);

                continue;
            }

            $arMap[$sExternalId] = $sBaseUrl . $arRow['path'];
        }

        return $arMap;
    }

    /**
     * The response body of /api/shop-id-map, cached under a key carrying the
     * catalogue stamp. The warm command builds it on the schedule so neither a
     * visitor nor a waiting sibling pays the cold build.
     *
     * @return array{shop:string,generated_at:string,map:array<string,string>}
     */
    public static function publishedBody(): array
    {
        $sKey = self::BODY_CACHE_KEY . md5(static::catalogueStamp());

        return Cache::remember($sKey, now()->addMinutes(self::BODY_TTL_MINUTES), function () {
            return [
                'shop' => static::ownOrigin(),
                'generated_at' => now()->toIso8601String(),
                'map' => static::publishMap(),
            ];
        });
    }

    /**
     * How fresh the catalogue is, as the published body's cache key, so no
     * writer needs an invalidation step: row count plus max(updated_at) per
     * table moves on every Eloquent save, insert and delete, which makes the
     * old body unreachable after the next second.
     *
     * Two UNINDEXED aggregates - neither table indexes updated_at - run on the
     * route only, machine traffic, never a page render. A raw DB::table()
     * update moves neither, and such a body stays reachable for the rest of
     * its 10-minute TTL. updated_at has one-second granularity, so a write
     * inside the same second as a build is bounded by that TTL too.
     */
    public static function catalogueStamp(): string
    {
        return 'p' . static::tableStamp('lovata_shopaholic_products')
            . '|c' . static::tableStamp('lovata_shopaholic_categories');
    }

    /**
     * What this page is, resolved from its path, and the 1C id it may publish.
     *
     * Three states collapse into two keys. A catalogue path always classifies
     * as PAGE_ENTITY, with external_id null unless the row is publishable, so
     * an inactive product, a soft-deleted one, one the import left without an
     * id, and a shade URL all emit nothing instead of falling back to a path
     * that may belong to another shop's different product. PAGE_NONE is the
     * home page, the cart and the static pages, the leg that keeps the path
     * check.
     *
     * @return array{kind:string,external_id:?string}
     */
    public static function resolvePath(string $sPath): array
    {
        // Memoised on the RAW path: two different raw paths can decode to the
        // same string, so a decoded key would let one answer the other's page
        $sKey = trim($sPath, '/');
        if (isset(static::$arResolvedPathList[$sKey])) {
            return static::$arResolvedPathList[$sKey];
        }

        $arSegmentList = static::segmentList($sPath);
        $arResolved = static::classifyPath($arSegmentList, static::joinSegments($arSegmentList));
        static::$arResolvedPathList[$sKey] = $arResolved;

        return $arResolved;
    }

    /**
     * The origin this shop serves, scheme and host of its primary site.
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
    public static function primaryBaseUrl(): string
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
     * The request path as the segments routing sees.
     *
     * Routing hands a page DECODED parameters while getPathInfo() stays raw,
     * so a path is decoded per segment and kept as a list: decoding the whole
     * string would let an encoded %2F open a path boundary that routing never
     * saw.
     *
     * @return string[]
     */
    protected static function segmentList(string $sPath): array
    {
        $sPath = trim($sPath, '/');

        return $sPath === '' ? [] : array_map('rawurldecode', explode('/', $sPath));
    }

    /**
     * @param string[] $arSegmentList
     */
    protected static function joinSegments(array $arSegmentList): string
    {
        return '/' . implode('/', $arSegmentList);
    }

    /**
     * @param string[] $arSegmentList
     */
    protected static function classifyPath(array $arSegmentList, string $sPath): array
    {
        if ($arSegmentList === []) {
            return static::noEntity();
        }

        // An encoded slash is not a path boundary: routing matched it inside
        // one segment, and no slug can contain it, so this is no catalogue path
        foreach ($arSegmentList as $sSegment) {
            if (strpos($sSegment, '/') !== false) {
                return static::noEntity();
            }
        }

        if ($arSegmentList[0] === self::PRODUCT_SEGMENT) {
            return static::entity(static::publishableProductId($arSegmentList));
        }

        return static::classifyCategoryPath($sPath, $arSegmentList);
    }

    /**
     * The external_id of the product this path names, or null when the path
     * names no publishable product - an extra segment (a shade URL), an
     * inactive or soft-deleted row, or a row the import left without an id.
     *
     * @param string[] $arSegmentList
     */
    protected static function publishableProductId(array $arSegmentList): ?string
    {
        if (count($arSegmentList) !== 2) {
            return null;
        }

        $obProduct = Product::where('slug', $arSegmentList[1])
            ->where('active', true)
            ->whereNull('deleted_at')
            ->first(['id', 'external_id']);

        return empty($obProduct) ? null : static::publishableId($obProduct->external_id);
    }

    /**
     * A category page is an entity whether or not it is active, but only an
     * active one can be published. The leaf slug is looked up without the
     * active filter and accepted only when the SHARED path method rebuilds
     * exactly the requested path, so a static page cannot be mistaken for a
     * category and a published path always classifies as the row that
     * published it.
     *
     * @param string[] $arSegmentList
     */
    protected static function classifyCategoryPath(string $sPath, array $arSegmentList): array
    {
        $obCategory = Category::where('slug', end($arSegmentList))
            ->orderBy('active', 'desc')
            ->first(['id', 'slug', 'parent_id', 'active', 'external_id']);

        if (empty($obCategory)) {
            return static::noEntity();
        }

        if (static::categoryPath($obCategory, [static::class, 'publishableParent']) !== $sPath) {
            return static::noEntity();
        }

        return static::entity($obCategory->active ? static::publishableId($obCategory->external_id) : null);
    }

    /**
     * The one ancestry rule both sides use: the parents the publish predicate
     * keeps, root first. The publish side resolves a parent from the active
     * collection it already loaded, the lookup side queries it, and the rule
     * is the same - an active child of an inactive parent therefore has the
     * same path on both sides.
     *
     * Bounded twice on purpose. Overlapping imports have reparented this tree
     * before, so a parent chain that revisits an id, or that runs past the
     * depth the catalogue uses, is reported and the row dropped rather than
     * looping inside a public route.
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

    /**
     * The lookup side's parent resolver, the publish predicate as a query.
     */
    protected static function publishableParent(int $iCategoryID)
    {
        return Category::where('id', $iCategoryID)
            ->where('active', true)
            ->first(['id', 'slug', 'parent_id']);
    }

    protected static function productPath(string $sSlug): string
    {
        return '/' . self::PRODUCT_SEGMENT . '/' . $sSlug;
    }

    protected static function publishableId($sExternalId): ?string
    {
        $sExternalId = trim((string) $sExternalId);

        return $sExternalId === '' ? null : $sExternalId;
    }

    protected static function entity(?string $sExternalId): array
    {
        return ['kind' => self::PAGE_ENTITY, 'external_id' => $sExternalId];
    }

    protected static function noEntity(): array
    {
        return ['kind' => self::PAGE_NONE, 'external_id' => null];
    }

    protected static function tableStamp(string $sTable): string
    {
        $obRow = Db::table($sTable)
            ->selectRaw('count(*) as row_count, max(updated_at) as last_update')
            ->first();

        return (string) ($obRow->row_count ?? 0) . ':' . (string) ($obRow->last_update ?? '');
    }
}
