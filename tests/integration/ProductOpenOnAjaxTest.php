<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Event;
use Lovata\Shopaholic\Models\Product;
use Logingrupa\Storeextender\Components\CustomProductPage;

/**
 * A product page counts a view on every request it always counted, and stops
 * throwing the shop's popularity sorting away on the ones that only redraw a
 * fragment.
 *
 * Lovata's PopularityHandler does two jobs in one listener on
 * shopaholic.product.open: increment THIS product's popularity, then clear
 * ProductListStore's SORT_POPULARITY_DESC list for the ENTIRE catalogue. This
 * component's page cycle re-runs on every AJAX postback the product page makes
 * - a shade switch, a strip refresh, a sheet page, a batched shade window - so
 * one visitor flipping through 230 shades dropped that sorted list once per
 * interaction, and the next catalogue page rebuilt it from scratch.
 *
 * Both halves are asserted, because either alone is the wrong fix: dropping
 * the event on postbacks must not drop the count, and keeping the count must
 * not bring the clear back with it.
 *
 * Core modules only, no plugin schema. The decision under test is which of two
 * paths a request takes, and the path that writes is Lovata's own three lines;
 * standing up a Shopaholic schema to watch Eloquent increment a column would
 * test Eloquent. It also cannot be done here - Lovata's
 * update_table_offers_remove_price_field migration does not run on SQLite, see
 * XmlImportPriceFlickerTest.
 */
class ProductOpenOnAjaxTest extends StoreExtenderPluginTestCase
{
    /**
     * @var bool No PLUGIN schema is needed here, and Shopaholic's does not
     * build on SQLite anyway. The core modules are still migrated, by
     * StoreExtenderPluginTestCase inside createApplication(), because merely
     * constructing a Product wakes Shopaholic's model handler, which reads a
     * setting, which reads system_settings.
     */
    protected $autoMigrate = false;

    /** @var CustomProductPage|\PHPUnit\Framework\MockObject\MockObject */
    protected $obComponent;

    /** @var int */
    protected $iEventFireCount;

    public function setUp(): void
    {
        parent::setUp();

        $this->iEventFireCount = 0;
        Event::listen('shopaholic.product.open', function () {
            $this->iEventFireCount++;
        });
    }

    /**
     * The component with only its write stubbed, so the routing decision can
     * be asserted without a schema. The expectation is declared up front
     * because PHPUnit verifies it on tearDown.
     */
    protected function expectIncrementCount(int $iCount): void
    {
        $this->obComponent = $this->getMockBuilder(CustomProductPage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['incrementPopularity'])
            ->getMock();
        $this->obComponent->expects($this->exactly($iCount))->method('incrementPopularity');
    }

    protected function registerProductOpen(): void
    {
        $obMethod = new ReflectionMethod(CustomProductPage::class, 'registerProductOpen');
        $obMethod->setAccessible(true);
        $obMethod->invoke($this->obComponent, new Product(['id' => 42]));
    }

    protected function makeRequestAjax(): void
    {
        request()->headers->set('X-Requested-With', 'XMLHttpRequest');
    }

    public function testPageRenderAnnouncesTheViewToEveryListener()
    {
        // the listener behind the event owns the count on this path
        $this->expectIncrementCount(0);

        $this->registerProductOpen();

        $this->assertSame(1, $this->iEventFireCount);
    }

    public function testAjaxPostbackDoesNotFireTheProductOpenEvent()
    {
        $this->expectIncrementCount(1);
        $this->makeRequestAjax();
        $this->registerProductOpen();

        $this->assertSame(
            0,
            $this->iEventFireCount,
            'the event carries a catalogue-wide popularity sorting clear, which a fragment redraw must not trigger'
        );
    }

    public function testAjaxPostbackStillCountsTheView()
    {
        // the count is the half that stays: a postback that counted before
        // must still count, and PHPUnit fails the test on tearDown if it did not
        $this->expectIncrementCount(1);
        $this->makeRequestAjax();

        $this->registerProductOpen();
    }

    public function testEveryAjaxPostbackCounts()
    {
        $this->expectIncrementCount(3);
        $this->makeRequestAjax();

        $this->registerProductOpen();
        $this->registerProductOpen();
        $this->registerProductOpen();

        $this->assertSame(0, $this->iEventFireCount);
    }
}
