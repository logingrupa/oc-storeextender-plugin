<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Db;
use Schema;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;

/**
 * The rows sitemap.xml publishes, and the parent walk that builds a category
 * path.
 *
 * The walk is why this lives in the plugin rather than in the page's PHP
 * section: the 1C import has reparented this tree through overlapping runs,
 * and an unbounded walk over two categories parented to each other spins
 * inside a public page render. The guard is here because it can be tested
 * here.
 *
 * Shopaholic's schema does not build on SQLite (see LegacyUrlResolverTest), so
 * the two tables the builder reads are created here with the columns it
 * touches.
 */
class ShopIdMapTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        Schema::create('lovata_shopaholic_categories', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
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
            $obTable->boolean('active')->default(true);
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        // root(1) > tools(2) > files(3) ; root(1) > retired(4, off)
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 1, 'name' => 'Root', 'slug' => 'root', 'parent_id' => null, 'active' => 1, 'nest_left' => 1, 'updated_at' => '2026-11-01 10:00:00'],
            ['id' => 2, 'name' => 'Tools', 'slug' => 'tools', 'parent_id' => 1, 'active' => 1, 'nest_left' => 2, 'updated_at' => '2026-11-02 10:00:00'],
            ['id' => 3, 'name' => 'Files', 'slug' => 'files', 'parent_id' => 2, 'active' => 1, 'nest_left' => 3, 'updated_at' => '2026-11-03 10:00:00'],
            ['id' => 4, 'name' => 'Retired', 'slug' => 'retired', 'parent_id' => 1, 'active' => 0, 'nest_left' => 4, 'updated_at' => '2026-11-04 10:00:00'],
        ]);
        Db::table('lovata_shopaholic_products')->insert([
            ['id' => 1, 'name' => 'Polygel', 'slug' => 'polygel-uvled', 'active' => 1, 'deleted_at' => null, 'updated_at' => '2026-11-06 10:00:00'],
            ['id' => 2, 'name' => 'Old gel', 'slug' => 'old-gel', 'active' => 0, 'deleted_at' => null, 'updated_at' => '2026-11-07 10:00:00'],
            ['id' => 3, 'name' => 'Gone gel', 'slug' => 'gone-gel', 'active' => 1, 'deleted_at' => '2026-11-08 10:00:00', 'updated_at' => '2026-11-08 10:00:00'],
        ]);
    }

    public function testTheRowsAreTheActiveCategoriesThenTheActiveProducts()
    {
        $arPathList = array_column(ShopIdMap::catalogueRows(), 'path');

        $this->assertSame([
            '/root',
            '/root/tools',
            '/root/tools/files',
            '/p/polygel-uvled',
        ], $arPathList, 'categories first, then products, nested paths root first');

        $this->assertNotContains('/p/old-gel', $arPathList, 'inactive product');
        $this->assertNotContains('/p/gone-gel', $arPathList, 'soft-deleted product');
        $this->assertNotContains('/root/retired', $arPathList, 'inactive category');
    }

    public function testEachRowCarriesTheKindAndTheLastmodTheSitemapPrints()
    {
        $arRowList = ShopIdMap::catalogueRows();

        $this->assertSame(ShopIdMap::KIND_CATEGORY, $arRowList[0]['kind']);
        $this->assertSame('2026-11-01', $arRowList[0]['lastmod']);

        $arProductRow = end($arRowList);
        $this->assertSame(ShopIdMap::KIND_PRODUCT, $arProductRow['kind']);
        $this->assertSame('/p/polygel-uvled', $arProductRow['path']);
        $this->assertSame('2026-11-06', $arProductRow['lastmod']);
    }

    /**
     * The parent walk only follows the parents the active predicate keeps, so
     * an active child of an inactive parent is listed at its own slug - which
     * is how the sitemap has always published it.
     */
    public function testAnActiveChildOfAnInactiveParentIsListedAtItsOwnSlug()
    {
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 6, 'name' => 'Branch', 'slug' => 'branch', 'parent_id' => 1, 'active' => 0, 'nest_left' => 6, 'updated_at' => '2026-11-06 10:00:00'],
            ['id' => 7, 'name' => 'Leaf', 'slug' => 'leaf', 'parent_id' => 6, 'active' => 1, 'nest_left' => 7, 'updated_at' => '2026-11-07 10:00:00'],
        ]);

        $arPathList = array_column(ShopIdMap::catalogueRows(), 'path');

        $this->assertContains('/leaf', $arPathList, 'the inactive parent is not in the path');
        $this->assertNotContains('/root/branch/leaf', $arPathList);
    }

    /**
     * Overlapping imports have reparented this tree before, so a chain that
     * revisits an id must be reported and dropped rather than spun on inside
     * the sitemap render.
     */
    public function testALoopingParentChainIsDroppedInsteadOfHanging()
    {
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 8, 'name' => 'Loop A', 'slug' => 'loop-a', 'parent_id' => 9, 'active' => 1, 'nest_left' => 8, 'updated_at' => '2026-11-08 10:00:00'],
            ['id' => 9, 'name' => 'Loop B', 'slug' => 'loop-b', 'parent_id' => 8, 'active' => 1, 'nest_left' => 9, 'updated_at' => '2026-11-09 10:00:00'],
        ]);
        Log::spy();

        $arPathList = array_column(ShopIdMap::catalogueRows(), 'path');

        $this->assertContains('/root', $arPathList, 'the healthy rows are still published');
        $this->assertContains('/p/polygel-uvled', $arPathList);
        $this->assertNotContains('/loop-a/loop-b', $arPathList);
        $this->assertNotContains('/loop-b/loop-a', $arPathList);
        Log::shouldHaveReceived('warning');
    }

    public function testADeepChainIsBoundedToo()
    {
        $iParentID = 3;
        for ($iRowID = 20; $iRowID < 20 + ShopIdMap::ANCESTRY_CEILING + 2; $iRowID++) {
            Db::table('lovata_shopaholic_categories')->insert([
                'id' => $iRowID, 'name' => 'Deep ' . $iRowID, 'slug' => 'deep-' . $iRowID,
                'parent_id' => $iParentID, 'active' => 1, 'nest_left' => $iRowID,
                'updated_at' => '2026-11-10 10:00:00',
            ]);
            $iParentID = $iRowID;
        }
        Log::spy();

        $arPathList = array_column(ShopIdMap::catalogueRows(), 'path');

        $this->assertContains('/root/tools/files/deep-20', $arPathList, 'a normal depth is untouched');
        $this->assertNotContains('/root/tools/files/deep-20/deep-21/deep-22/deep-23/deep-24/deep-25/deep-26/deep-27/deep-28/deep-29/deep-30', $arPathList);
        Log::shouldHaveReceived('warning');
    }

    public function testTheOwnOriginIsTheSchemeAndHostOfThePrimarySite()
    {
        $this->defineSites([['lv', 1, 1, '/lv']]);

        $sOrigin = ShopIdMap::ownOrigin();

        $this->assertStringStartsWith('http', $sOrigin);
        $this->assertStringNotContainsString('/lv', $sOrigin, 'the route prefix is not part of an origin');
        $this->assertSame(rtrim($sOrigin, '/'), $sOrigin);
    }
}
