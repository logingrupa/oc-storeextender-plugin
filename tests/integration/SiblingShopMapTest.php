<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Db;
use Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopMap;

/**
 * This shop publishes which 1C id lives at which of its URLs, and reads the
 * same from its siblings to name them in its hreflang block.
 *
 * Two contracts are pinned here. The publish side lists exactly what the
 * sitemap lists, id or no id, and only the projection drops a row without one.
 * The lookup side tells a page that is no catalogue entity apart from an
 * entity this shop cannot publish, because the first may fall back to a path
 * check and the second must emit nothing.
 *
 * Shopaholic's schema does not build on SQLite (see LegacyUrlResolverTest), so
 * the two tables these helpers read are created here with the columns they
 * touch. The sibling maps are seeded through the helper's own cache key, so the
 * production read path is what runs.
 */
class SiblingShopMapTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const PRODUCT_ID = 'a1b2c3d4-0000-0000-0000-000000000001';
    const INACTIVE_PRODUCT_ID = 'a1b2c3d4-0000-0000-0000-000000000002';
    const DELETED_PRODUCT_ID = 'a1b2c3d4-0000-0000-0000-000000000003';
    const ROOT_CATEGORY_ID = 'c1b2c3d4-0000-0000-0000-000000000001';
    const TOOLS_CATEGORY_ID = 'c1b2c3d4-0000-0000-0000-000000000002';
    const FILES_CATEGORY_ID = 'c1b2c3d4-0000-0000-0000-000000000003';
    const RETIRED_CATEGORY_ID = 'c1b2c3d4-0000-0000-0000-000000000004';
    const LEAF_CATEGORY_ID = 'c1b2c3d4-0000-0000-0000-000000000007';

    const SIBLING_LV = 'https://nailscosmetics.lv';
    const SIBLING_LT = 'https://nailscosmetics.lt';
    const SIBLING_NO = 'https://nailscosmetics.no';

    public function setUp(): void
    {
        parent::setUp();

        $this->defineSites([['lv', 1, 1, '/lv']]);

        Schema::create('lovata_shopaholic_categories', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->string('external_id')->nullable();
            $obTable->integer('parent_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('nest_left')->nullable();
            $obTable->integer('nest_right')->nullable();
            $obTable->integer('nest_depth')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_shopaholic_products', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->string('external_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        // root(1) > tools(2) > files(3) ; root(1) > retired(4, off) ; root(1) > noidcat(5)
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 1, 'name' => 'Root', 'slug' => 'root', 'external_id' => self::ROOT_CATEGORY_ID, 'parent_id' => null, 'active' => 1, 'nest_left' => 1, 'updated_at' => '2026-11-01 10:00:00'],
            ['id' => 2, 'name' => 'Tools', 'slug' => 'tools', 'external_id' => self::TOOLS_CATEGORY_ID, 'parent_id' => 1, 'active' => 1, 'nest_left' => 2, 'updated_at' => '2026-11-02 10:00:00'],
            ['id' => 3, 'name' => 'Files', 'slug' => 'files', 'external_id' => self::FILES_CATEGORY_ID, 'parent_id' => 2, 'active' => 1, 'nest_left' => 3, 'updated_at' => '2026-11-03 10:00:00'],
            ['id' => 4, 'name' => 'Retired', 'slug' => 'retired', 'external_id' => self::RETIRED_CATEGORY_ID, 'parent_id' => 1, 'active' => 0, 'nest_left' => 4, 'updated_at' => '2026-11-04 10:00:00'],
            ['id' => 5, 'name' => 'No id', 'slug' => 'noidcat', 'external_id' => null, 'parent_id' => 1, 'active' => 1, 'nest_left' => 5, 'updated_at' => '2026-11-05 10:00:00'],
        ]);
        Db::table('lovata_shopaholic_products')->insert([
            ['id' => 1, 'name' => 'Polygel', 'slug' => 'polygel-uvled', 'external_id' => self::PRODUCT_ID, 'active' => 1, 'deleted_at' => null, 'updated_at' => '2026-11-06 10:00:00'],
            ['id' => 2, 'name' => 'Old gel', 'slug' => 'old-gel', 'external_id' => self::INACTIVE_PRODUCT_ID, 'active' => 0, 'deleted_at' => null, 'updated_at' => '2026-11-07 10:00:00'],
            ['id' => 3, 'name' => 'Gone gel', 'slug' => 'gone-gel', 'external_id' => self::DELETED_PRODUCT_ID, 'active' => 1, 'deleted_at' => '2026-11-08 10:00:00', 'updated_at' => '2026-11-08 10:00:00'],
            ['id' => 4, 'name' => 'No id gel', 'slug' => 'no-id-gel', 'external_id' => null, 'active' => 1, 'deleted_at' => null, 'updated_at' => '2026-11-09 10:00:00'],
        ]);

        $this->forgetResolvedPaths();
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testCatalogueRowsListWhatTheSitemapListsIncludingARowWithoutAnId()
    {
        $arPathList = array_column(ShopIdMap::catalogueRows(), 'path');

        $this->assertSame([
            '/root',
            '/root/tools',
            '/root/tools/files',
            '/root/noidcat',
            '/p/polygel-uvled',
            '/p/no-id-gel',
        ], $arPathList, 'categories first, then products, nested paths root first');

        $this->assertNotContains('/p/old-gel', $arPathList, 'inactive product');
        $this->assertNotContains('/p/gone-gel', $arPathList, 'soft-deleted product');
        $this->assertNotContains('/root/retired', $arPathList, 'inactive category');
    }

    public function testCatalogueRowsCarryTheKindAndTheLastmodTheSitemapPrints()
    {
        $arRowList = ShopIdMap::catalogueRows();

        $this->assertSame(ShopIdMap::KIND_CATEGORY, $arRowList[0]['kind']);
        $this->assertSame('2026-11-01', $arRowList[0]['lastmod']);
        $this->assertSame(self::ROOT_CATEGORY_ID, $arRowList[0]['external_id']);

        $arProductRow = end($arRowList);
        $this->assertSame(ShopIdMap::KIND_PRODUCT, $arProductRow['kind']);
        $this->assertSame('/p/no-id-gel', $arProductRow['path']);
        $this->assertNull($arProductRow['external_id']);
    }

    public function testPublishMapDropsOnlyTheRowsWithoutAnId()
    {
        $arMap = ShopIdMap::publishMap();
        $sBaseUrl = ShopIdMap::primaryBaseUrl();

        $this->assertSame([
            self::ROOT_CATEGORY_ID => $sBaseUrl . '/root',
            self::TOOLS_CATEGORY_ID => $sBaseUrl . '/root/tools',
            self::FILES_CATEGORY_ID => $sBaseUrl . '/root/tools/files',
            self::PRODUCT_ID => $sBaseUrl . '/p/polygel-uvled',
        ], $arMap);

        $this->assertStringEndsWith('/lv', $sBaseUrl, 'base_url carries the route prefix');
    }

    public function testADuplicateExternalIdKeepsTheFirstRowAndIsReported()
    {
        Db::table('lovata_shopaholic_products')->insert([
            'id' => 9, 'name' => 'Duplicate', 'slug' => 'duplicate-gel',
            'external_id' => self::ROOT_CATEGORY_ID, 'active' => 1, 'deleted_at' => null,
            'updated_at' => '2026-11-10 10:00:00',
        ]);
        Log::spy();

        $arMap = ShopIdMap::publishMap();

        $this->assertSame(ShopIdMap::primaryBaseUrl() . '/root', $arMap[self::ROOT_CATEGORY_ID], 'the first row keeps the id');
        $this->assertNotContains(ShopIdMap::primaryBaseUrl() . '/p/duplicate-gel', $arMap);
        Log::shouldHaveReceived('warning')->once();
    }

    public function testTheCatalogueStampIsStableUntilSomethingMoves()
    {
        $sStamp = ShopIdMap::catalogueStamp();

        $this->assertSame($sStamp, ShopIdMap::catalogueStamp(), 'stable on unchanged data');
    }

    /**
     * An Eloquent save writes the new value AND a fresh updated_at; the raw
     * updates here stand in for that pair, because the stamp reads only the row
     * count and max(updated_at). updated_at has one-second granularity, so the
     * assertion is that the stamp moves in a LATER second, not instantly.
     */
    public function testTheCatalogueStampMovesOnEverythingTheMapPublishes()
    {
        $sBefore = ShopIdMap::catalogueStamp();

        $this->touchProduct(1, ['slug' => 'polygel-renamed'], '2026-11-20 10:00:01');
        $sAfterRename = ShopIdMap::catalogueStamp();
        $this->assertNotSame($sBefore, $sAfterRename, 'a slug change');

        $this->touchProduct(1, ['active' => 0], '2026-11-20 10:00:02');
        $sAfterActive = ShopIdMap::catalogueStamp();
        $this->assertNotSame($sAfterRename, $sAfterActive, 'an active flip');

        $this->touchProduct(1, ['external_id' => 'moved-id'], '2026-11-20 10:00:03');
        $sAfterId = ShopIdMap::catalogueStamp();
        $this->assertNotSame($sAfterActive, $sAfterId, 'an external_id change');

        $this->touchProduct(1, ['deleted_at' => '2026-11-20 10:00:04'], '2026-11-20 10:00:04');
        $sAfterTrash = ShopIdMap::catalogueStamp();
        $this->assertNotSame($sAfterId, $sAfterTrash, 'a soft delete');

        Db::table('lovata_shopaholic_products')->where('id', 1)->delete();
        $this->assertNotSame($sAfterTrash, ShopIdMap::catalogueStamp(), 'a hard delete drops the row count');

        Db::table('lovata_shopaholic_categories')->where('id', 3)->delete();
        $this->assertNotSame($sAfterTrash, ShopIdMap::catalogueStamp(), 'the category table counts too');
    }

    public function testThePublishedBodyIsRebuiltWhenTheStampMoves()
    {
        $arBody = ShopIdMap::publishedBody();

        $this->assertSame(ShopIdMap::ownOrigin(), $arBody['shop']);
        $this->assertArrayHasKey('generated_at', $arBody);
        $this->assertArrayHasKey(self::PRODUCT_ID, $arBody['map']);

        $this->touchProduct(1, ['slug' => 'polygel-renamed'], '2026-11-20 10:00:01');

        $this->assertSame(
            ShopIdMap::primaryBaseUrl() . '/p/polygel-renamed',
            ShopIdMap::publishedBody()['map'][self::PRODUCT_ID],
            'the stamped key makes the old body unreachable, no invalidation call'
        );
    }

    public function testAPublishableProductPathResolvesToItsIdWhateverTheSlashes()
    {
        $arExpected = ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => self::PRODUCT_ID];

        $this->assertSame($arExpected, ShopIdMap::resolvePath('/p/polygel-uvled'));
        $this->assertSame($arExpected, ShopIdMap::resolvePath('p/polygel-uvled'));
        $this->assertSame($arExpected, ShopIdMap::resolvePath('/p/polygel-uvled/'));
    }

    public function testANonPublishableEntityPathIsStillAnEntityWithNoId()
    {
        foreach ([
            '/p/old-gel' => 'inactive product, which .no serves through the non-active branch',
            '/p/gone-gel' => 'soft-deleted product',
            '/p/no-id-gel' => 'the import left no external_id',
            '/p/polygel-uvled/shade-5' => 'a shade URL, extra segment',
            '/root/retired' => 'inactive category',
        ] as $sPath => $sReason) {
            $this->assertSame(
                ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => null],
                ShopIdMap::resolvePath($sPath),
                $sReason . ' - entity, so it emits nothing and never reaches the path check'
            );
        }
    }

    public function testAPathThatIsNoCatalogueEntityKeepsThePathCheckLeg()
    {
        $arExpected = ['kind' => ShopIdMap::PAGE_NONE, 'external_id' => null];

        $this->assertSame($arExpected, ShopIdMap::resolvePath(''), 'home');
        $this->assertSame($arExpected, ShopIdMap::resolvePath('/'), 'home');
        $this->assertSame($arExpected, ShopIdMap::resolvePath('/cart'));
        $this->assertSame($arExpected, ShopIdMap::resolvePath('/tools/files'), 'the leaf is a category but its ancestry is not this path');
        $this->assertSame($arExpected, ShopIdMap::resolvePath('/hjelp/vilkar'));
    }

    public function testANestedCategoryPathResolvesToItsId()
    {
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => self::FILES_CATEGORY_ID],
            ShopIdMap::resolvePath('/root/tools/files')
        );
    }

    /**
     * The publish side walks only the parents its predicate keeps, so an
     * active child of an inactive parent is published at its own slug. The
     * lookup must use the SAME rule, or that published path would classify as
     * no-entity on its own page and fall through to the sibling path check,
     * where another shop listing the same path for a different category hands
     * it the wrong alternate.
     */
    public function testAnActiveChildOfAnInactiveParentHasOnePathOnBothSides()
    {
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 6, 'name' => 'Branch', 'slug' => 'branch', 'external_id' => 'c1b2c3d4-0000-0000-0000-000000000006', 'parent_id' => 1, 'active' => 0, 'nest_left' => 6, 'updated_at' => '2026-11-06 10:00:00'],
            ['id' => 7, 'name' => 'Leaf', 'slug' => 'leaf', 'external_id' => self::LEAF_CATEGORY_ID, 'parent_id' => 6, 'active' => 1, 'nest_left' => 7, 'updated_at' => '2026-11-07 10:00:00'],
        ]);
        $this->forgetResolvedPaths();

        $this->assertContains('/leaf', array_column(ShopIdMap::catalogueRows(), 'path'), 'the inactive parent is not in the path');
        $this->assertSame(ShopIdMap::primaryBaseUrl() . '/leaf', ShopIdMap::publishMap()[self::LEAF_CATEGORY_ID]);

        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => self::LEAF_CATEGORY_ID],
            ShopIdMap::resolvePath('/leaf'),
            'the published path classifies as the row that published it'
        );
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_NONE, 'external_id' => null],
            ShopIdMap::resolvePath('/root/branch/leaf'),
            'a path the publish side never produces is no catalogue path'
        );
    }

    /**
     * Overlapping imports have reparented this tree before, so a chain that
     * revisits an id must be reported and dropped rather than spun on inside
     * the sitemap render and this map's build.
     */
    public function testALoopingParentChainIsDroppedInsteadOfHanging()
    {
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 8, 'name' => 'Loop A', 'slug' => 'loop-a', 'external_id' => 'c1b2c3d4-0000-0000-0000-000000000008', 'parent_id' => 9, 'active' => 1, 'nest_left' => 8, 'updated_at' => '2026-11-08 10:00:00'],
            ['id' => 9, 'name' => 'Loop B', 'slug' => 'loop-b', 'external_id' => 'c1b2c3d4-0000-0000-0000-000000000009', 'parent_id' => 8, 'active' => 1, 'nest_left' => 9, 'updated_at' => '2026-11-09 10:00:00'],
        ]);
        $this->forgetResolvedPaths();
        Log::spy();

        $arPathList = array_column(ShopIdMap::catalogueRows(), 'path');

        $this->assertContains('/root', $arPathList, 'the healthy rows are still published');
        $this->assertNotContains('/loop-a/loop-b', $arPathList);
        $this->assertNotContains('/loop-b/loop-a', $arPathList);
        $this->assertArrayNotHasKey('c1b2c3d4-0000-0000-0000-000000000008', ShopIdMap::publishMap());
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_NONE, 'external_id' => null],
            ShopIdMap::resolvePath('/loop-a'),
            'the lookup side is bounded too'
        );
        Log::shouldHaveReceived('warning');
    }

    /**
     * Routing hands a page decoded parameters while getPathInfo() stays raw,
     * so the lookup decodes per segment - and an encoded slash stays inside
     * its segment instead of opening a boundary routing never matched.
     */
    public function testAnEncodedPathClassifiesTheWayRoutingResolvedIt()
    {
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => self::FILES_CATEGORY_ID],
            ShopIdMap::resolvePath('/%72oot/tools/files'),
            'the same category as its decoded path'
        );
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => self::PRODUCT_ID],
            ShopIdMap::resolvePath('/p/polygel%2Duvled')
        );
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_NONE, 'external_id' => null],
            ShopIdMap::resolvePath('/root/tools%2Ffiles'),
            'an encoded slash is one segment, which no slug can be'
        );
        $this->assertSame(
            ['kind' => ShopIdMap::PAGE_ENTITY, 'external_id' => self::FILES_CATEGORY_ID],
            ShopIdMap::resolvePath('/root/tools/files'),
            'and the real path is not poisoned by the memo of the encoded one'
        );
    }

    public function testCrossAlternatesEmitsOnlyWhatASiblingPublishedForOurId()
    {
        Http::fake();
        $this->seedSiblingMap(self::SIBLING_LV, [self::PRODUCT_ID => 'https://nailscosmetics.lv/lv/p/polygel-uvled']);
        $this->seedSiblingMap(self::SIBLING_LT, ['some-other-id' => 'https://nailscosmetics.lt/lt/p/kitas']);

        $arResolved = SiblingShopMap::crossAlternates('/p/polygel-uvled');

        $this->assertTrue($arResolved['has_identity']);
        $this->assertSame(
            [self::SIBLING_LV => 'https://nailscosmetics.lv/lv/p/polygel-uvled'],
            $arResolved['urls'],
            'a sibling without our id contributes no url, never a guessed path'
        );
        Http::assertNothingSent();
    }

    public function testCrossAlternatesOnANoEntityPathCarriesTheNoIdentityMarker()
    {
        Http::fake();
        $this->seedSiblingMap(self::SIBLING_LV, [self::PRODUCT_ID => 'https://nailscosmetics.lv/lv/p/polygel-uvled']);

        $this->assertSame(
            ['has_identity' => false, 'urls' => []],
            SiblingShopMap::crossAlternates('/cart'),
            'the partial takes the unchanged path-check leg'
        );
        Http::assertNothingSent();
    }

    public function testEveryColdSiblingStateYieldsNoUrlAndFetchesNothing()
    {
        Http::fake();

        $this->assertSame([], SiblingShopMap::crossAlternates('/p/polygel-uvled')['urls'], 'nothing cached');

        $this->seedSiblingMap(self::SIBLING_LV, []);
        $this->forgetResolvedPaths();
        $this->assertSame([], SiblingShopMap::crossAlternates('/p/polygel-uvled')['urls'], 'a cached empty map');

        $this->seedSiblingMap(self::SIBLING_LV, ['another-id' => 'https://nailscosmetics.lv/lv/p/kaut-kas']);
        $this->forgetResolvedPaths();
        $this->assertSame([], SiblingShopMap::crossAlternates('/p/polygel-uvled')['urls'], 'a map without our id');

        $this->assertSame([], SiblingShopMap::read(self::SIBLING_NO), 'the reader is a cache read and nothing else');
        Http::assertNothingSent();
    }

    public function testAnEntityWithNoPublishableIdEmitsNothingWithEverySiblingWarm()
    {
        Http::fake();
        $this->seedSiblingMap(self::SIBLING_LV, [self::INACTIVE_PRODUCT_ID => 'https://nailscosmetics.lv/lv/p/old-gel']);

        $arResolved = SiblingShopMap::crossAlternates('/p/old-gel');

        $this->assertTrue($arResolved['has_identity'], 'an entity, so the path check is not reachable');
        $this->assertSame([], $arResolved['urls']);
        Http::assertNothingSent();
    }

    /**
     * @param array<string,string> $arMap
     */
    protected function seedSiblingMap(string $sDomain, array $arMap): void
    {
        Cache::put(SiblingShopMap::cacheKey($sDomain), $arMap, now()->addMinutes(SiblingShopMap::TTL_OK_MINUTES));
    }

    /**
     * @param array<string,mixed> $arValueList
     */
    protected function touchProduct(int $iProductID, array $arValueList, string $sUpdatedAt): void
    {
        Db::table('lovata_shopaholic_products')
            ->where('id', $iProductID)
            ->update($arValueList + ['updated_at' => $sUpdatedAt]);
    }

    /**
     * resolvePath() memoises per render, and the static outlives one test, so
     * the memo is dropped between fixtures the way root CLAUDE.md resets
     * Lovata's TraitInitActiveLang statics.
     */
    protected function forgetResolvedPaths(): void
    {
        $obProperty = (new ReflectionClass(ShopIdMap::class))->getProperty('arResolvedPathList');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, []);
    }
}
