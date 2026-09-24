<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopLayoutDoubles.php';

use Kharanenka\Helper\CCache;
use Logingrupa\Storeextender\Components\OfferSheet;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * The render-context dimensions, which decide what cached offer markup the
 * next request may reuse.
 *
 * The page joined that list when offer URLs became page-relative: a sheet row
 * or a swatch rendered under /p carries a /p link and one rendered under /p2
 * carries a /p2 link, so the two may never share an entry. buildCacheKey (the
 * server file cache) and getClientCacheEpochToken (the visitor's localStorage)
 * both read the ONE list, which is what keeps the two stores from disagreeing
 * about what is reusable.
 *
 * Integration, not unit: getRenderContextKeyParts() reads the site, the locale,
 * the active currency, the active price type and the colour data version, and
 * the last of those goes through System\Models\Parameter, so a bare TestCase
 * raises "A facade root has not been set" before the first assertion. The app
 * is booted with the core module schema only (autoMigrate off, the
 * ProductStructuredDataTest precedent) and the currency and price type tables
 * come from the shared stub-table trait, so both helpers initialise against an
 * empty row set instead of a missing one.
 *
 * The component itself is still built without its constructor, and the page is
 * written straight onto the protected property the method reads.
 */
class OfferSheetCacheKeyTest extends StoreExtenderPluginTestCase
{
    use ShopLayoutStubTables;

    /** @var bool core module schema only, see class comment */
    protected $autoMigrate = false;

    /** @var int Dimensions the list carried before the page joined it */
    const PRE_EXISTING_DIMENSION_COUNT = 6;

    /** @var string What a render outside any page keys itself as */
    const NO_PAGE_SENTINEL = 'nopage';

    /** @var string What the controller stub renders in place of the strip partial */
    const RENDER_MARKER = '<!-- strip -->';

    protected OfferSheet $obComponent;

    protected \ReflectionClass $obReflection;

    public function setUp(): void
    {
        parent::setUp();

        $this->createShopLayoutStubTables();
        // the window render materializes its rows through OfferItem::make, which
        // queries; an empty table answers "no such offer" instead of erroring
        $this->createStubTable('lovata_shopaholic_offers', function (\October\Rain\Database\Schema\Blueprint $obTable) {
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

        // Both helpers memoize their row set for the life of the process
        CurrencyHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();

        $this->obReflection = new \ReflectionClass(OfferSheet::class);
        $this->obComponent = $this->obReflection->newInstanceWithoutConstructor();
    }

    public function tearDown(): void
    {
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
     * Stand a page in front of the component. Only the id is read, so a bare
     * object carrying one is the whole fixture.
     */
    protected function setPageId(?string $sPageId): void
    {
        $obProperty = $this->obReflection->getProperty('page');
        $obProperty->setAccessible(true);
        $obProperty->setValue(
            $this->obComponent,
            $sPageId === null ? null : new class ($sPageId) {
                public string $id;

                public function __construct(string $sPageId)
                {
                    $this->id = $sPageId;
                }
            }
        );
    }

    /**
     * Stand a controller in front of the component. Only renderPartial is
     * called, and what it returns is opaque to the caching decision, so a
     * marker string is the whole fixture.
     */
    protected function setControllerStub(): void
    {
        $obProperty = $this->obReflection->getProperty('controller');
        $obProperty->setAccessible(true);
        $obProperty->setValue($this->obComponent, new class {
            public function renderPartial(string $sPartial, array $arData = []): string
            {
                return OfferSheetCacheKeyTest::RENDER_MARKER;
            }
        });
    }

    /**
     * The swatch data shape getInlineSwatchData answers with. No items: the
     * partial is stubbed out, so nothing reads them.
     */
    protected function makeSwatchData(): array
    {
        return [
            'arOfferItemList' => [],
            'iTotalCount' => 230,
            'bUseSheet' => true,
            'iFirstIndex' => 0,
        ];
    }

    /**
     * A strip rendered with sold-out shades hidden is keyed nowhere.
     *
     * Only an import rotates the render epoch, so a filtered strip kept for ten
     * minutes keeps drawing a shade an order drained minutes ago - the exact
     * thing getVisibleRowList refuses to cache and the reason its list stays on
     * the slow path. The strip has to keep the same promise (T-3-71).
     */
    public function testAStripWithSoldOutShadesHiddenIsNeverCached()
    {
        $this->setPageId('product2');
        $this->setControllerStub();
        $obProductItem = ProductItem::make(0);

        $sHtml = $this->callProtected(
            'getSwatchStripHtml',
            [$obProductItem, '', true, $this->makeSwatchData()]
        );

        $this->assertSame(self::RENDER_MARKER, $sHtml);
        $sCacheKey = $this->callProtected(
            'buildCacheKey',
            [['hr.strip', $obProductItem->id, 'head', 1]]
        );
        $this->assertEmpty(CCache::get([OfferSheet::CACHE_TAG_SHEET], $sCacheKey));
    }

    /**
     * And the unfiltered strip is still cached, under the key it always had -
     * the flag is still in the list, at the only value it can now carry.
     */
    public function testAnUnfilteredStripIsStillCachedUnderTheSameKey()
    {
        $this->setPageId('product2');
        $this->setControllerStub();
        $obProductItem = ProductItem::make(0);

        $this->callProtected(
            'getSwatchStripHtml',
            [$obProductItem, '', false, $this->makeSwatchData()]
        );

        $sCacheKey = $this->callProtected(
            'buildCacheKey',
            [['hr.strip', $obProductItem->id, 'head', 0]]
        );
        $this->assertSame(self::RENDER_MARKER, CCache::get([OfferSheet::CACHE_TAG_SHEET], $sCacheKey));
    }

    /**
     * The forward window keeps the same rule: a page rendered under the filter
     * would append shades that sold while it sat in the cache.
     */
    public function testAWindowWithSoldOutShadesHiddenIsNeverCached()
    {
        $this->setPageId('product2');
        $this->setControllerStub();
        $obProductItem = ProductItem::make(0);
        $arWindowData = [
            'arRowList' => [['iOfferId' => 4152, 'sFamily' => null]],
            'iTotalCount' => 230,
            'iFirstIndex' => 12,
        ];

        $sHtml = $this->callProtected(
            'getSwatchWindowHtml',
            [$obProductItem, 4151, true, true, $arWindowData]
        );

        $this->assertSame(self::RENDER_MARKER, $sHtml);
        $sCacheKey = $this->callProtected(
            'buildCacheKey',
            [['hr.window', $obProductItem->id, 4151, 1, 1]]
        );
        $this->assertEmpty(CCache::get([OfferSheet::CACHE_TAG_SHEET], $sCacheKey));
    }

    public function testThePageIsTheLastRenderContextDimension()
    {
        $this->setPageId('product2');

        $arPartList = $this->callProtected('getRenderContextKeyParts', []);

        $this->assertGreaterThan(self::PRE_EXISTING_DIMENSION_COUNT, count($arPartList));
        $this->assertSame('product2', $arPartList[count($arPartList) - 1]);
    }

    public function testTwoPagesKeyTheServerCacheDifferently()
    {
        $arCallerPartList = ['hr.strip', 397, 'head', 0];

        $this->setPageId('product');
        $sProductKey = $this->callProtected('buildCacheKey', [$arCallerPartList]);

        $this->setPageId('product2');
        $sProduct2Key = $this->callProtected('buildCacheKey', [$arCallerPartList]);

        $this->assertNotSame($sProductKey, $sProduct2Key);
        $this->assertStringEndsWith('.product', $sProductKey);
        $this->assertStringEndsWith('.product2', $sProduct2Key);
    }

    /**
     * The rest page and the 12-shade page continue from the SAME shade, and
     * they are different markup: without the flag in the key the first of the
     * two served would be handed to the other caller for ten minutes.
     */
    public function testARestWindowKeysApartFromATwelveShadeWindow()
    {
        $this->setPageId('product2');

        $sPageKey = $this->callProtected('buildCacheKey', [['hr.window', 366, 4151, 0, 0]]);
        $sRestKey = $this->callProtected('buildCacheKey', [['hr.window', 366, 4151, 0, 1]]);

        $this->assertNotSame($sPageKey, $sRestKey);
    }

    public function testTwoPagesKeyTheClientCacheDifferently()
    {
        $this->setPageId('product');
        $sProductToken = $this->callProtected('getClientCacheEpochToken', []);

        $this->setPageId('product2');
        $sProduct2Token = $this->callProtected('getClientCacheEpochToken', []);

        $this->assertNotSame($sProductToken, $sProduct2Token);
    }

    /**
     * An empty last part would collapse into the separator and let a keyless
     * render share an entry with the /p one, so the absence of a page is a
     * value of its own.
     */
    public function testARenderWithNoPageTakesTheSentinelAndNotAnEmptyString()
    {
        $this->setPageId(null);

        $arPartList = $this->callProtected('getRenderContextKeyParts', []);

        $this->assertSame(self::NO_PAGE_SENTINEL, $arPartList[count($arPartList) - 1]);
        $this->assertNotSame('', $arPartList[count($arPartList) - 1]);
    }

    /**
     * The two stores read ONE list, so anything appended to it reaches both.
     * A second key builder is what the method's own docblock forbids.
     */
    public function testTheServerKeyAndTheClientTokenShareTheSameDimensions()
    {
        $this->setPageId('product2');

        $sToken = $this->callProtected('getClientCacheEpochToken', []);
        $sKey = $this->callProtected('buildCacheKey', [['hr.sheet', 397, 0]]);

        $this->assertSame('hr.sheet.397.0.'.$sToken, $sKey);
    }
}
