<?php declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cms\Classes\Page;
use Cms\Classes\Theme;
use Cms\Models\MaintenanceSetting;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Logingrupa\StoreExtender\Classes\Event\Device\DeviceLayoutHandler;
use Logingrupa\StoreExtender\Classes\Helper\DeviceHint;

/**
 * The device branch decides one thing on the real cms.page.beforeDisplay
 * seam: a page whose base file name is product2 loads the lean layout on a
 * phone, and nothing else on the shop changes.
 *
 * Every fire is asserted to return null. Controller.php:205-212 returns any
 * truthy non-Page value as the whole HTTP response body, so "returns nothing"
 * is the requirement, not a detail. The complementary proof that a real page
 * still renders as HTML is a curl against nc.test: this suite cannot drive a
 * full request, because the Shopaholic schema does not build on SQLite and
 * the resulting 500 debug page starts with <html itself.
 *
 * DeviceHint memoizes in a process-wide static that has no reset by design,
 * so the one method that turns wasConsulted() true is isolated. The other
 * three read the flag as false whatever order the suite runs in.
 */
class DeviceLayoutHandlerTest extends StoreExtenderPluginTestCase
{
    /** @var string iPhone Safari 17, measured against MobileDetect 4.11.0 */
    const UA_IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    /** @var string Desktop Chrome on Windows 11, measured the same way */
    const UA_DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    /**
     * @var bool No PLUGIN schema is needed here, and Shopaholic's does not
     * build on SQLite anyway. The core modules are still migrated, by
     * StoreExtenderPluginTestCase inside createApplication(), because the
     * maintenance case reads a setting, which reads system_settings.
     */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        // Deliberate, and per test application: Plugin::boot() runs in the
        // test app, so PrimeCategoryTreeHandler and ExtendCurrencyConversion
        // are already listening on this hook and both reach for Shopaholic
        // tables that SQLite :memory: does not have. Not restored in
        // tearDown - the application is refreshed per test.
        Event::forget('cms.page.beforeDisplay');

        DeviceLayoutHandler::switchLayoutOnPageDisplay();
    }

    /**
     * October prepends the Controller to the listener arguments
     * (EventEmitter.php:42). The listener never reads it, so null keeps the
     * fixture free of a theme-resolving constructor.
     *
     * @param string|null            $sUrl
     * @param \Cms\Classes\Page|null $obPage
     * @return mixed
     */
    protected function fireBeforeDisplay(?string $sUrl, $obPage)
    {
        return Event::fire('cms.page.beforeDisplay', [null, $sUrl, $obPage], true);
    }

    /**
     * @param string $sFileName
     * @param string $sLayout
     * @return \Cms\Classes\Page
     */
    protected function makePage(string $sFileName, string $sLayout): Page
    {
        $obPage = Page::inTheme(Theme::getActiveTheme());
        $obPage->fileName = $sFileName;
        $obPage->layout = $sLayout;

        return $obPage;
    }

    public function testFireWithANullPageReturnsNullAndDoesNotConsultTheDevice()
    {
        $this->assertNull($this->fireBeforeDisplay('/404', null));
        $this->assertFalse(DeviceHint::wasConsulted());
    }

    public function testFireOnAForeignPageNameLeavesTheLayoutAlone()
    {
        $obPage = $this->makePage('product.htm', 'shop');

        $this->assertNull($this->fireBeforeDisplay('/lv/p/some-slug/6405', $obPage));
        $this->assertSame('shop', $obPage->layout);
        $this->assertFalse(DeviceHint::wasConsulted());
    }

    /**
     * This theme ships no maintenance page, so Page::loadCached() returns
     * null for it and maintenance mode collapses into the null-page case.
     * That is what is asserted here.
     */
    public function testFireUnderMaintenanceModeIsTolerated()
    {
        MaintenanceSetting::set(['is_enabled' => true, 'cms_page' => 'maintenance']);

        $this->assertNull(Page::loadCached(Theme::getActiveTheme(), 'maintenance'));
        $this->assertNull($this->fireBeforeDisplay('/lv', null));
        $this->assertFalse(DeviceHint::wasConsulted());
    }

    /**
     * One ordered sequence by necessity: DeviceHint memoizes in a process-wide
     * static that D-13 deliberately gives no reset, so the false-to-true
     * transition of wasConsulted() and the "memo held across a changed
     * User-Agent" assertion can only be observed in one run. Its own process,
     * so the static it writes is never read by another method.
     */
    #[RunInSeparateProcess]
    public function testDeviceBranchLifecycleOnASharedStaticMemo()
    {
        request()->headers->set('User-Agent', self::UA_IPHONE_SAFARI);

        $this->assertFalse(DeviceHint::wasConsulted());

        $obForeignPage = $this->makePage('product.htm', 'shop');
        $this->assertNull($this->fireBeforeDisplay('/lv/p/some-slug/6405', $obForeignPage));
        $this->assertSame('shop', $obForeignPage->layout);
        $this->assertFalse(DeviceHint::wasConsulted());

        $obMobilePage = $this->makePage('product2.htm', 'shop');
        $this->assertNull($this->fireBeforeDisplay('/lv/p2/some-slug/6405', $obMobilePage));
        $this->assertSame('shop-lean', $obMobilePage->layout);
        $this->assertTrue(DeviceHint::wasConsulted());

        request()->headers->set('User-Agent', self::UA_DESKTOP_CHROME);

        $obSecondMobilePage = $this->makePage('product2.htm', 'shop');
        $this->assertNull($this->fireBeforeDisplay('/lv/p2/other-slug/6406', $obSecondMobilePage));
        $this->assertSame('shop-lean', $obSecondMobilePage->layout);
    }
}
