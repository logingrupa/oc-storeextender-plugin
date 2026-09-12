<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopLayoutDoubles.php';

use Logingrupa\Storeextender\Components\OfferSheet;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;

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

    protected OfferSheet $obComponent;

    protected \ReflectionClass $obReflection;

    public function setUp(): void
    {
        parent::setUp();

        $this->createShopLayoutStubTables();

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
