<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Cms\Classes\Controller;
use Cms\Classes\Theme;
use Illuminate\Support\Facades\URL;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailData;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailState;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * An order mail links the order page and the proforma on the site the mail is written for.
 * A bare /checkout/<key> redirects to the language of the reader's browser, so a Russian
 * mail opened in a Latvian browser showed the order in Latvian.
 *
 * The links resolve against the active theme's real order pages (/checkout/:slug and
 * /proforma/:slug/:print?).
 */
class OrderMailLinkTest extends StoreExtenderPluginTestCase
{
    /** Core module schema only, the order is never saved */
    protected $autoMigrate = false;

    const APP_URL = 'https://nailscosmetics.test';

    const SECRET_KEY = '60c1ff1a2aa66a9140f294f41893ae85';

    const LINK_KEY_LIST = ['order_url', 'proforma_url', 'proforma_print_url'];

    /** @var array site id per locale */
    protected $arSiteIdList = [];

    public function setUp(): void
    {
        parent::setUp();

        // a console run re-forces the root URL to app.url whenever the site changes
        Config::set('app.url', self::APP_URL);
        URL::forceRootUrl(self::APP_URL);
        URL::forceScheme('https');

        $this->arSiteIdList = $this->defineSites([['lv', true, true, '/lv'], ['en', false, true, '/en'], ['ru', false, true, '/ru']]);
    }

    /**
     * The controller is a process-wide static that outlives this test's application.
     * @return void
     */
    public function tearDown(): void
    {
        (new ReflectionProperty(Controller::class, 'instance'))->setValue(null, null);

        parent::tearDown();
    }

    public function testCustomerMailLinksOpenOnTheOrderSite()
    {
        foreach (['ru', 'en', 'lv'] as $sLocale) {
            $arData = OrderMailData::make($this->makeOrder($this->arSiteIdList[$sLocale]), OrderMailState::PAID);

            $this->assertSame($this->expectedLinkList('/'.$sLocale), $this->linkList($arData), $sLocale);
            $this->assertSame($sLocale, $arData['_current_locale'], $sLocale);
        }
    }

    public function testManagerMailLinksOpenOnTheShopSite()
    {
        $arData = OrderMailData::forManager($this->makeOrder($this->arSiteIdList['ru']), OrderMailState::PAID);

        $this->assertSame($this->expectedLinkList('/lv'), $this->linkList($arData));
        $this->assertSame('lv', $arData['_current_locale']);
    }

    /**
     * Orders ported from the old shop carry no site.
     */
    public function testOrderWithoutASiteLinksToTheShopSite()
    {
        $arData = OrderMailData::make($this->makeOrder(null), OrderMailState::PAID);

        $this->assertSame($this->expectedLinkList('/lv'), $this->linkList($arData));
        $this->assertSame('lv', $arData['_current_locale']);
    }

    /**
     * A shop page request: another site active, a CMS controller whose route carries
     * unrelated parameters, a gateway query string on the request.
     */
    public function testLinksIgnoreTheRequestTheyAreBuiltIn()
    {
        Site::applyActiveSiteId($this->arSiteIdList['lv']);
        $obController = new Controller(Theme::getActiveTheme());
        $obController->getRouter()->setParameters(['slug' => 'x', 'offer' => '1', 'print' => 'print']);
        $this->app['request']->query->replace(['data' => 'paysera-callback', 'ss1' => 'signature']);

        $arData = OrderMailData::make($this->makeOrder($this->arSiteIdList['ru']), OrderMailState::PAID);

        $this->assertSame($this->expectedLinkList('/ru'), $this->linkList($arData));
    }

    /**
     * Gateway webhooks, the scheduler and queue workers run with no CMS controller and no
     * shop site active.
     */
    public function testLinksWithoutAControllerOrAnActiveSite()
    {
        $this->assertNull(Controller::getController());
        $this->assertNull(Site::getActiveSiteId());

        $arData = OrderMailData::make($this->makeOrder($this->arSiteIdList['ru']), OrderMailState::PAID);

        $this->assertSame($this->expectedLinkList('/ru'), $this->linkList($arData));
    }

    /**
     * .no runs one site with the route prefix "/" and a disabled primary site; a shop may
     * also run an unprefixed site.
     */
    public function testUnprefixedSitesLinkWithoutADoubleSlash()
    {
        $arSiteIdList = $this->defineSites([['nb-no', false, true, '/'], ['en', false, true, ''], ['lv', true, false, '/lv']]);

        foreach (['nb-no', 'en'] as $sLocale) {
            $arData = OrderMailData::make($this->makeOrder($arSiteIdList[$sLocale]), OrderMailState::PAID);

            $this->assertSame($this->expectedLinkList(''), $this->linkList($arData), $sLocale);
        }
    }

    public function testOrderWithoutASecretKeyIsRejected()
    {
        $obOrder = $this->makeOrder($this->arSiteIdList['lv']);
        $obOrder->secret_key = '';

        $this->expectException(InvalidArgumentException::class);

        OrderMailData::make($obOrder, OrderMailState::PAID);
    }

    /**
     * @param int|null $iSiteId
     * @return Order
     */
    protected function makeOrder($iSiteId)
    {
        $obOrder = new Order();
        $obOrder->id = 35364;
        $obOrder->order_number = '261005-0002';
        $obOrder->secret_key = self::SECRET_KEY;
        $obOrder->site_id = $iSiteId;

        return $obOrder;
    }

    /**
     * @param string $sRoutePrefix
     * @return array
     */
    protected function expectedLinkList($sRoutePrefix)
    {
        $sBaseUrl = self::APP_URL.$sRoutePrefix;

        return [
            'order_url' => $sBaseUrl.'/checkout/'.self::SECRET_KEY,
            'proforma_url' => $sBaseUrl.'/proforma/'.self::SECRET_KEY,
            'proforma_print_url' => $sBaseUrl.'/proforma/'.self::SECRET_KEY.'/print',
        ];
    }

    /**
     * @param array $arData
     * @return array the link keys in a fixed order, null for a missing one
     */
    protected function linkList($arData)
    {
        return array_combine(self::LINK_KEY_LIST, array_map(fn ($sKey) => $arData[$sKey] ?? null, self::LINK_KEY_LIST));
    }
}
