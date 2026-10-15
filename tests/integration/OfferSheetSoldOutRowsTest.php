<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopLayoutDoubles.php';

use Illuminate\Support\Facades\DB;
use Kharanenka\Helper\CCache;
use Logingrupa\Storeextender\Components\OfferSheet;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use October\Rain\Database\Schema\Blueprint;

/**
 * The shade sheet on a product that hides its sold-out shades.
 *
 * The strip, its "+N" opener and the shade counter count the visible row list,
 * the ordered list with every quantity-0 shade dropped. The sheet has to list
 * that same list - its rows, its total and its family chips - or a shopper is
 * offered shades the strip says the product does not have, and tapping one
 * takes the out-of-stock path with no position to count.
 *
 * Integration, same harness as OfferSheetCacheKeyTest: the filter reads each
 * shade's quantity through OfferItem::make, which queries, and the render cache
 * key reads the site, locale, currency, price type and colour version. The
 * ordered row list is seeded straight into the component's request memo, so no
 * colour data is needed to order it.
 */
class OfferSheetSoldOutRowsTest extends StoreExtenderPluginTestCase
{
    use ShopLayoutStubTables;

    /** @var bool core module schema only, see OfferSheetCacheKeyTest */
    protected $autoMigrate = false;

    /** @var string What the controller stub renders in place of a partial */
    const RENDER_MARKER = '<!-- rows -->';

    /**
     * Shades in sheet order, with the stock each one has. Red loses its first
     * shade, Pink sells out whole, Other keeps its only one. Ids sit far above
     * the ones other tests seed, so no item cached by them can answer here.
     *
     * @var array<int, array{0: int, 1: string, 2: string|null, 3: int}> [id, family, hex, quantity]
     */
    const SHADE_LIST = [
        [9101, 'Red', '#c00', 0],
        [9102, 'Red', '#c00', 5],
        [9103, 'Red', '#c00', 2],
        [9201, 'Pink', '#f9c', 0],
        [9202, 'Pink', '#f9c', 0],
        [9301, 'Other', null, 1],
    ];

    /** @var int[] the shades the strip counts */
    const SELLABLE_ID_LIST = [9102, 9103, 9301];

    protected OfferSheet $obComponent;

    protected \ReflectionClass $obReflection;

    protected ProductItem $obProductItem;

    /** @var object the controller stub, its renders listed in $arRenderList */
    protected object $obControllerStub;

    /** @var array OfferItem eager loads as the process held them before this test */
    protected array $arOfferQueryWith = [];

    public function setUp(): void
    {
        parent::setUp();

        // Earlier plugin boots in this process leave eager loads behind
        // (property_value) whose relations this schema never defines, and the
        // quantity read needs none of them
        $this->arOfferQueryWith = OfferItem::$arQueryWith;
        OfferItem::$arQueryWith = [];

        $this->createShopLayoutStubTables();
        $this->createStubTable('lovata_shopaholic_offers', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('product_id')->default(0);
            $obTable->boolean('active')->default(0);
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->integer('quantity')->default(0);
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });
        // OfferItem reads its price through the price relation
        $this->createStubTable('lovata_shopaholic_prices', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->timestamps();
        });
        // and its translations, which RainLab Translate eager loads
        $this->createStubTable('rainlab_translate_attributes', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('locale')->nullable();
            $obTable->string('model_id')->nullable();
            $obTable->string('model_type')->nullable();
            $obTable->text('attribute_data')->nullable();
        });

        CurrencyHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();

        $this->obReflection = new \ReflectionClass(OfferSheet::class);
        $this->obComponent = $this->obReflection->newInstanceWithoutConstructor();
        $this->obProductItem = ProductItem::make(0);
        $this->setControllerStub();
        $this->seedShadeList();
    }

    public function tearDown(): void
    {
        OfferItem::$arQueryWith = $this->arOfferQueryWith;
        CurrencyHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();

        parent::tearDown();
    }

    /**
     * @return mixed
     */
    protected function callProtected(string $sMethod, array $arArgumentList)
    {
        $obMethod = $this->obReflection->getMethod($sMethod);
        $obMethod->setAccessible(true);

        return $obMethod->invokeArgs($this->obComponent, $arArgumentList);
    }

    /**
     * Stand a controller in front of the component, recording each render as
     * [partial, data]. getLayout answers the render context's layout part.
     */
    protected function setControllerStub(): void
    {
        $this->obControllerStub = new class {
            /** @var array<int, array{0: string, 1: array}> */
            public array $arRenderList = [];

            public function renderPartial(string $sPartial, array $arData = []): string
            {
                $this->arRenderList[] = [$sPartial, $arData];

                return OfferSheetSoldOutRowsTest::RENDER_MARKER;
            }

            public function getLayout(): object
            {
                return new class {
                    public function getBaseFileName(): string
                    {
                        return 'shop-lean';
                    }
                };
            }
        };
        $obProperty = $this->obReflection->getProperty('controller');
        $obProperty->setAccessible(true);
        $obProperty->setValue($this->obComponent, $this->obControllerStub);
    }

    /**
     * Offer rows for the quantities, and the ordered list in the shape
     * buildOrderedRowList gives it, held where getOrderedRowList reads first.
     */
    protected function seedShadeList(): void
    {
        $arRowList = [];
        $sPreviousFamily = null;
        foreach (self::SHADE_LIST as [$iOfferId, $sFamily, $sHex, $iQuantity]) {
            DB::table('lovata_shopaholic_offers')->insert([
                'id' => $iOfferId,
                'product_id' => 0,
                'active' => 1,
                'name' => 'Shade '.$iOfferId,
                'quantity' => $iQuantity,
            ]);
            $arRowList[] = [
                'iOfferId' => $iOfferId,
                'sFamily' => $sFamily,
                'sHex' => $sHex,
                'bGroupStart' => $sFamily !== $sPreviousFamily,
            ];
            $sPreviousFamily = $sFamily;
        }

        $sCacheKey = $this->callProtected('buildCacheKey', [['hr.rows', $this->obProductItem->id]]);
        $obProperty = $this->obReflection->getProperty('arOrderedRowListMemo');
        $obProperty->setAccessible(true);
        $obProperty->setValue($this->obComponent, [$sCacheKey => $arRowList]);
    }

    protected function getSheetPageKey(): string
    {
        return $this->callProtected('buildCacheKey', [['hr.sheet', $this->obProductItem->id, 0]]);
    }

    public function testTheSheetListsOnlyTheShadesTheStripCounts()
    {
        $arVisibleList = $this->callProtected('getVisibleRowList', [$this->obProductItem, true]);

        $this->assertSame(self::SELLABLE_ID_LIST, array_column($arVisibleList, 'iOfferId'));
    }

    public function testAFamilyWhoseFirstShadeSoldOutStartsAtItsFirstSellableOne()
    {
        $arVisibleList = $this->callProtected('getVisibleRowList', [$this->obProductItem, true]);

        $this->assertSame(
            [[9102, 'Red', true], [9103, 'Red', false], [9301, 'Other', true]],
            array_map(fn ($arRow) => [$arRow['iOfferId'], $arRow['sFamily'], $arRow['bGroupStart']], $arVisibleList)
        );
        $this->assertNotContains('Pink', array_column($arVisibleList, 'sFamily'));
    }

    /**
     * Unfiltered, the list is the ordered list row for row, so a product that
     * keeps its sold-out shades renders the sheet it always did.
     */
    public function testTheUnfilteredListIsTheOrderedListUnchanged()
    {
        $arOrderedList = $this->callProtected('getOrderedRowList', [$this->obProductItem]);

        $this->assertSame($arOrderedList, $this->callProtected('getVisibleRowList', [$this->obProductItem, false]));
    }

    /**
     * Only an import rotates the render epoch, so a filtered page kept for ten
     * minutes would list a shade an order drained since: the rule
     * getVisibleRowList states (T-3-71).
     */
    public function testAFilteredSheetPageIsRenderedAndKeyedNowhere()
    {
        $arVisibleList = $this->callProtected('getVisibleRowList', [$this->obProductItem, true]);

        $sHtml = $this->callProtected('getRowListHtml', [$this->obProductItem, $arVisibleList, 0, true]);

        $this->assertSame(self::RENDER_MARKER, $sHtml);
        $this->assertCount(1, $this->obControllerStub->arRenderList);
        [$sPartial, $arData] = $this->obControllerStub->arRenderList[0];
        $this->assertSame('home-redesign/shared/offers-sheet-rows', $sPartial);
        $this->assertSame(
            self::SELLABLE_ID_LIST,
            array_map(fn ($arRow) => (int) $arRow['obOffer']->id, $arData['arRowList'])
        );
        $this->assertEmpty(CCache::get([OfferSheet::CACHE_TAG_SHEET], $this->getSheetPageKey()));
    }

    public function testAnUnfilteredSheetPageIsStillCachedUnderItsKey()
    {
        $arVisibleList = $this->callProtected('getVisibleRowList', [$this->obProductItem, false]);

        $this->callProtected('getRowListHtml', [$this->obProductItem, $arVisibleList, 0, false]);

        $this->assertSame(self::RENDER_MARKER, CCache::get([OfferSheet::CACHE_TAG_SHEET], $this->getSheetPageKey()));
    }

    public function testFamilyChipsCountTheListedRows()
    {
        $arVisibleList = $this->callProtected('getVisibleRowList', [$this->obProductItem, true]);

        $arChipList = $this->callProtected('getFamilyChipList', [$arVisibleList]);

        $this->assertSame(
            [['sFamily' => 'Red', 'sHex' => '#c00', 'iCount' => 2], ['sFamily' => 'Other', 'sHex' => null, 'iCount' => 1]],
            $arChipList
        );
        $this->assertSame(count($arVisibleList), array_sum(array_column($arChipList, 'iCount')));
    }
}
