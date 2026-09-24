<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/ShopLayoutDoubles.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\DB;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
use Logingrupa\StoreExtender\Classes\Helper\ShopLayoutBinder;
use Logingrupa\StoreExtender\Classes\Helper\ThemeUserBinder;

/**
 * Counts setActivePriceType() calls. October\Rain\Support\Traits\Singleton has a
 * final protected constructor and a protected static instance, so the spy is
 * built without a constructor and pushed through Reflection.
 */
class SpyActivePriceHelper extends ActivePriceHelper
{
    public static $iCallCount = 0;

    public function setActivePriceType()
    {
        static::$iCallCount++;
    }
}

/**
 * ShopLayoutBinder is the single source of the layout variable bag that both
 * shop.htm and shop-lean.htm render from, and that Larajax re-runs on every
 * fragment capture. The pinned key list, the component list and the single
 * setActivePriceType() call are the contract: a second layout cannot drift from
 * this one without failing here, and a dropped setActivePriceType() would render
 * distributor prices to the public (CHROME-01, T-2-01).
 *
 * Stub cart, price type and currency tables on SQLite (CartStateReaderTest
 * pattern): the hermetic schema carries the core module tables only.
 */
class ShopLayoutBinderTest extends StoreExtenderPluginTestCase
{
    use ShopLayoutStubTables;

    protected $autoMigrate = false;

    /** @var array<string> the ten variables every product fragment reads (D-03) */
    const PINNED_KEY_LIST = [
        'cart_is_available',
        'showEditButton',
        'bSkipCartBuild',
        'arCartState',
        'seo_toolbox_is_available',
        'sLogoutHandler',
        'obUser',
        'activeCurrencyCode',
        'sPriceType',
        'bPriceIncludesVAT',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->createShopLayoutStubTables();

        // Both helpers memoize their row set for the life of the process
        PriceTypeHelper::forgetInstance();
        CurrencyHelper::forgetInstance();

        SpyActivePriceHelper::$iCallCount = 0;
        request()->setMethod('GET');
    }

    public function tearDown(): void
    {
        $this->restoreActivePriceHelper();

        PriceTypeHelper::forgetInstance();
        CurrencyHelper::forgetInstance();
        UserHelper::forgetInstance();
        CartProcessor::$iTestCartID = null;
        request()->setMethod('GET');

        parent::tearDown();
    }

    public function testWritesExactlyThePinnedKeyList()
    {
        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $arExpectedKeyList = self::PINNED_KEY_LIST;
        sort($arExpectedKeyList);

        $arActualKeyList = array_keys($obLayout->arBag);
        sort($arActualKeyList);

        $this->assertSame($arExpectedKeyList, $arActualKeyList, 'the layout variable bag is a pinned contract');
    }

    public function testRegistersCartSeoToolboxAndSession()
    {
        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $arAliasList = array_keys($obLayout->arComponentList);
        sort($arAliasList);

        $this->assertSame(['Cart', 'SeoToolbox', 'Session'], $arAliasList);
        $this->assertSame(3, $obLayout->iAddComponentCallCount);
        $this->assertSame(
            ShopLayoutBinder::CART_COMPONENT_CLASS,
            $obLayout->arComponentList[ShopLayoutBinder::CART_COMPONENT_ALIAS]
        );
        $this->assertSame(
            ShopLayoutBinder::SEO_COMPONENT_CLASS,
            $obLayout->arComponentList[ShopLayoutBinder::SEO_COMPONENT_ALIAS]
        );
        $this->assertSame(
            ThemeUserBinder::RAINLAB_COMPONENT_CLASS,
            $obLayout->arComponentList[ThemeUserBinder::RAINLAB_COMPONENT_ALIAS]
        );
        $this->assertTrue($obLayout['cart_is_available']);
        $this->assertTrue($obLayout['seo_toolbox_is_available']);
    }

    public function testReusesAnIniDeclaredComponentInstance()
    {
        $obLayout = new FakeShopLayout();
        $obLayout->arDeclaredList[ShopLayoutBinder::CART_COMPONENT_ALIAS] = new FakeShopComponent('Cart');

        ShopLayoutBinder::bind($obLayout);

        $this->assertSame(2, $obLayout->iAddComponentCallCount, 'a configured instance must not be shadowed by a bare one');
        $this->assertTrue($obLayout['cart_is_available'], 'the declared instance still makes the cart available');
    }

    public function testCallsSetActivePriceTypeExactlyOnce()
    {
        $this->installActivePriceHelperSpy();

        ShopLayoutBinder::bind(new FakeShopLayout());

        $this->assertSame(1, SpyActivePriceHelper::$iCallCount, 'price tier access control runs once per render');
    }

    public function testSkipsTheCartBuildOnAReadOnlyRequest()
    {
        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $this->assertTrue($obLayout['bSkipCartBuild']);
        $this->assertSame(['count' => 0, 'positions' => []], $obLayout['arCartState']);
    }

    public function testLeavesTheCartBuildToCartProcessorOnPost()
    {
        request()->setMethod('POST');
        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $this->assertFalse($obLayout['bSkipCartBuild']);
        $this->assertNull($obLayout['arCartState']);
    }

    public function testReadsTheActiveCurrencyCode()
    {
        $this->insertCurrency('EUR');
        CurrencyHelper::forgetInstance();

        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $this->assertSame('EUR', $obLayout['activeCurrencyCode']);
    }

    public function testPriceIncludesVatWithoutAnActivePriceType()
    {
        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $this->assertNull($obLayout['sPriceType']);
        $this->assertTrue($obLayout['bPriceIncludesVAT']);
    }

    /**
     * PHPUnit 12 reads test metadata from attributes, not docblocks
     * @param string $sPriceTypeCode
     * @param bool   $bExpectedVat
     */
    #[DataProvider('vatByPriceTypeProvider')]
    public function testPriceIncludesVatFollowsThePriceTypeCode($sPriceTypeCode, $bExpectedVat)
    {
        $this->insertPriceType($sPriceTypeCode);
        PriceTypeHelper::forgetInstance();
        PriceTypeHelper::instance()->switchActive($sPriceTypeCode);

        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $this->assertSame($sPriceTypeCode, $obLayout['sPriceType']);
        $this->assertSame($bExpectedVat, $obLayout['bPriceIncludesVAT']);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function vatByPriceTypeProvider()
    {
        return [
            'salon price includes VAT' => ['salona', true],
            'wholesale price includes VAT' => ['vairum', true],
            'distributor price excludes VAT' => ['izpl', false],
        ];
    }

    public function testRejectsAnEmptyLayout()
    {
        $this->expectException(InvalidArgumentException::class);

        ShopLayoutBinder::bind(null);
    }

    /**
     * Push the counting spy into the ActivePriceHelper singleton static
     * @return void
     */
    protected function installActivePriceHelperSpy()
    {
        $obSpy = (new ReflectionClass(SpyActivePriceHelper::class))->newInstanceWithoutConstructor();

        $obProperty = new ReflectionProperty(ActivePriceHelper::class, 'instance');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, $obSpy);
    }

    /**
     * @param string $sCode
     * @return void
     */
    protected function insertCurrency($sCode)
    {
        DB::table('lovata_shopaholic_currency')->insert([
            'active' => 1,
            'is_default' => 1,
            'name' => $sCode,
            'code' => $sCode,
            'symbol' => $sCode,
            'rate' => 1,
        ]);
    }

    /**
     * @param string $sCode
     * @return void
     */
    protected function insertPriceType($sCode)
    {
        DB::table('lovata_shopaholic_price_types')->insert([
            'active' => 1,
            'name' => $sCode,
            'code' => $sCode,
        ]);
    }
}
