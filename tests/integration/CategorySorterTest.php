<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use October\Rain\Database\Schema\Blueprint;
use System\Classes\UpdateManager;
use Lovata\Shopaholic\Models\Category;
use Logingrupa\StoreExtender\Classes\Helper\CategorySorter;

/**
 * CategorySorter reorders sibling categories by the 1C `code` column through
 * real NestedTree moveBefore/moveAfter calls. Coded siblings sort naturally
 * and case-insensitively, uncoded ones keep their relative order at the end,
 * a second run is a no-op and no move may ever change a node's parent.
 *
 * Selective migration (EqualOldPriceHandlerTest pattern): the full
 * Lovata.Shopaholic chain is SQLite-incompatible, so only Toolbox migrates
 * and the categories table comes from its real migration class. The two
 * pivot tables the booted save handlers touch are stubs.
 */
class CategorySorterTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        UpdateManager::instance()->migratePlugin('Lovata.Toolbox');

        require_once __DIR__.'/../../../../lovata/shopaholic/updates/create_table_categories.php';
        (new \Lovata\Shopaholic\Updates\CreateTableCategories())->up();

        $this->createStubTable('lovata_shopaholic_entity_site_relation', function (Blueprint $obTable) {
            $obTable->integer('entity_id')->nullable();
            $obTable->string('entity_type')->nullable();
            $obTable->integer('site_id')->nullable();
        });

        $this->createStubTable('lovata_discounts_shopaholic_discount_category', function (Blueprint $obTable) {
            $obTable->integer('discount_id')->nullable();
            $obTable->integer('category_id')->nullable();
        });
    }

    public function testRootsSortByCodeAndStayRoots()
    {
        $this->makeCategory('Vaksācija', 'Vaksācija');
        $this->makeCategory('Manikīrs/Pedikīrs', 'Manikīrs/Pedikīrs');
        $this->makeCategory('Komplekti', 'Komplekti');
        $this->makeCategory('Apmācības un semināri', 'Apmācības un semināri');
        $this->makeCategory('Bez koda', null);

        $iMoves = CategorySorter::sort();

        $this->assertGreaterThan(0, $iMoves);
        $this->assertSame(
            ['Apmācības un semināri', 'Komplekti', 'Manikīrs/Pedikīrs', 'Vaksācija', 'Bez koda'],
            $this->getSiblingNames(null)
        );
        $this->assertSame(0, Category::whereNotNull('parent_id')->count());
    }

    public function testChildrenSortByCodeWithinTheirOwnParent()
    {
        $obParent = $this->makeCategory('Nagi', 'A');
        $obOther = $this->makeCategory('Kosmētika', 'B');

        foreach (['JNagu dizains', 'ABāzes', 'OUTLET', 'Pedikīrs', 'BBūvejošie'] as $sCode) {
            $this->makeCategory($sCode, $sCode, $obParent);
        }
        $this->makeCategory('Zeta', null, $obOther);
        $this->makeCategory('Alpha', null, $obOther);

        CategorySorter::sort();

        $this->assertSame(
            ['ABāzes', 'BBūvejošie', 'JNagu dizains', 'OUTLET', 'Pedikīrs'],
            $this->getSiblingNames($obParent->id)
        );
        $this->assertSame(5, Category::where('parent_id', $obParent->id)->count());
        $this->assertSame(['Zeta', 'Alpha'], $this->getSiblingNames($obOther->id));
        $this->assertSame(['Nagi', 'Kosmētika'], $this->getSiblingNames(null));
    }

    public function testSecondSortIsNoop()
    {
        $this->makeTwoLevelTree();

        $this->assertGreaterThan(0, CategorySorter::sort());
        $arNestLeftBefore = Category::orderBy('id')->pluck('nest_left', 'id')->all();

        $this->assertSame(0, CategorySorter::sort());
        $this->assertSame($arNestLeftBefore, Category::orderBy('id')->pluck('nest_left', 'id')->all());
    }

    public function testSortNeverChangesParents()
    {
        $this->makeTwoLevelTree();
        $arParentBefore = $this->getParentMap();

        CategorySorter::sort();

        $this->assertSame($arParentBefore, $this->getParentMap());
    }

    /**
     * Two unsorted roots, each with two unsorted children.
     */
    protected function makeTwoLevelTree(): void
    {
        $obRootB = $this->makeCategory('Root B', 'B');
        $obRootA = $this->makeCategory('Root A', 'A');
        $this->makeCategory('B2', '2', $obRootB);
        $this->makeCategory('B1', '1', $obRootB);
        $this->makeCategory('A2', '2', $obRootA);
        $this->makeCategory('A1', '1', $obRootA);
    }

    protected function makeCategory(string $sName, ?string $sCode, ?Category $obParent = null): Category
    {
        $obCategory = new Category();
        $obCategory->active = true;
        $obCategory->name = $sName;
        $obCategory->slug = Str::slug($sName);
        $obCategory->code = $sCode;
        $obCategory->external_id = Str::slug($sName);
        if ($obParent !== null) {
            $obCategory->parent_id = $obParent->id;
        }
        $obCategory->save();

        return $obCategory;
    }

    /**
     * @return array sibling names in nest_left order
     */
    protected function getSiblingNames(?int $iParentID): array
    {
        return DB::table('lovata_shopaholic_categories')
            ->when($iParentID === null, fn($obQuery) => $obQuery->whereNull('parent_id'))
            ->when($iParentID !== null, fn($obQuery) => $obQuery->where('parent_id', $iParentID))
            ->orderBy('nest_left')
            ->pluck('name')
            ->all();
    }

    /**
     * @return array [id => parent_id]
     */
    protected function getParentMap(): array
    {
        return DB::table('lovata_shopaholic_categories')->orderBy('id')->pluck('parent_id', 'id')->all();
    }

    protected function createStubTable(string $sTableName, callable $fnDefineTable): void
    {
        if (Schema::hasTable($sTableName)) {
            return;
        }
        Schema::create($sTableName, $fnDefineTable);
    }
}
