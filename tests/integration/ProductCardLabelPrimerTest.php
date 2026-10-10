<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/ShopItemDoubles.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\LabelsShopaholic\Classes\Store\LabelListStore;
use Lovata\LabelsShopaholic\Classes\Store\Label\ListByProductStore;
use Logingrupa\StoreExtender\Classes\Helper\ProductCardLabelPrimer;

/**
 * ProductCardLabelPrimer fills the ListByProductStore entries of a card list
 * with one pivot statement; the vendor store reads them and its clear() drops them.
 *
 * Stub pivot table on SQLite, raw inserts (ProductOfferMapTest pattern).
 */
class ProductCardLabelPrimerTest extends StoreExtenderPluginTestCase
{
    use ShopItemDoubles;

    const PIVOT_TABLE = 'lovata_labels_shopaholic_product_label';

    protected $autoMigrate = false;

    /** @var array<int, array{sql: string, bindings: array}> */
    protected $arPivotStatementList = [];

    public function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable(self::PIVOT_TABLE)) {
            Schema::create(self::PIVOT_TABLE, function (Blueprint $obTable) {
                $obTable->integer('label_id');
                $obTable->integer('product_id');
                $obTable->primary(['label_id', 'product_id']);
            });
        }

        DB::table(self::PIVOT_TABLE)->insert([
            ['product_id' => 7, 'label_id' => 1],
            ['product_id' => 7, 'label_id' => 2],
            ['product_id' => 8, 'label_id' => 3],
        ]);

        LabelListStore::forgetInstance();
        ListByProductStore::forgetInstance();

        DB::listen(function ($obQuery) {
            if (str_contains($obQuery->sql, self::PIVOT_TABLE)) {
                $this->arPivotStatementList[] = ['sql' => $obQuery->sql, 'bindings' => $obQuery->bindings];
            }
        });
    }

    public function tearDown(): void
    {
        LabelListStore::forgetInstance();
        ListByProductStore::forgetInstance();

        parent::tearDown();
    }

    public function testOneStatementFillsEveryCardOfTheList()
    {
        ProductCardLabelPrimer::prime($this->makeCardList([7, 8, 9]));

        $this->assertCount(1, $this->arPivotStatementList, 'a card list reads its labels in one statement');
        $this->assertStringContainsString(' in (', $this->arPivotStatementList[0]['sql']);
        $this->assertEqualsCanonicalizing([7, 8, 9], $this->arPivotStatementList[0]['bindings']);

        $obStore = LabelListStore::instance()->product;
        $arExpectedMap = [7 => [1, 2], 8 => [3], 9 => []];
        foreach ($arExpectedMap as $iProductID => $arExpectedLabelIDList) {
            $this->assertEqualsCanonicalizing($arExpectedLabelIDList, $obStore->get($iProductID));
        }
        $this->assertCount(1, $this->arPivotStatementList, 'the store answers from the primed entries');

        foreach (array_keys($arExpectedMap) as $iProductID) {
            $this->assertEqualsCanonicalizing(
                ListByProductStore::instance()->getNoCache($iProductID),
                $obStore->get($iProductID),
                'a primed entry equals the store\'s own read'
            );
        }
    }

    public function testCachedProductIsNotReadAgain()
    {
        ProductCardLabelPrimer::prime($this->makeCardList([7]));
        ProductCardLabelPrimer::prime($this->makeCardList([7]));
        $this->assertCount(1, $this->arPivotStatementList, 'a primed product is skipped');

        ProductCardLabelPrimer::prime($this->makeCardList([7, 8]));
        $this->assertCount(2, $this->arPivotStatementList);
        $this->assertSame([8], $this->arPivotStatementList[1]['bindings'], 'only the missing product is read');
    }

    public function testVendorClearDropsThePrimedEntry()
    {
        ProductCardLabelPrimer::prime($this->makeCardList([7]));
        DB::table(self::PIVOT_TABLE)->insert(['product_id' => 7, 'label_id' => 4]);

        LabelListStore::instance()->product->clear(7);
        $this->arPivotStatementList = [];

        $this->assertEqualsCanonicalizing([1, 2, 4], LabelListStore::instance()->product->get(7));
        $this->assertCount(1, $this->arPivotStatementList, 'clear() sends the store back to the DB');
        $this->assertSame([7], $this->arPivotStatementList[0]['bindings']);
        $this->assertStringContainsString('= ?', $this->arPivotStatementList[0]['sql'], 'a point read');
    }

    public function testNonListAndNonItemsPassThrough()
    {
        $this->assertNull(ProductCardLabelPrimer::prime(null));
        $this->assertSame([], ProductCardLabelPrimer::prime([]));

        $arMixedList = [$this->makeProduct([]), 'not an item'];
        $this->assertSame($arMixedList, ProductCardLabelPrimer::prime($arMixedList));
        $this->assertCount(0, $this->arPivotStatementList, 'nothing to read, no statement');
    }

    /**
     * @param array<int, int> $arProductIDList
     * @return array
     */
    protected function makeCardList(array $arProductIDList): array
    {
        return array_map(fn (int $iProductID) => $this->makeProduct(['id' => $iProductID]), $arProductIDList);
    }
}
