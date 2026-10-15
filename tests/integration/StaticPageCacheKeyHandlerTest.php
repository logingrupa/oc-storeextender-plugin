<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Classes\Event\Seo\StaticPageCacheKeyHandler;
use RainLab\Translate\Classes\Translator;

/**
 * robots.txt and sitemap.xml run outside every site: the app locale stays on config app.locale
 * (en) while RainLab Translate falls back to the primary site (lt), so the static page map they
 * build carries the lt URLs. These cases pin that such a request never writes the key the en site
 * reads, and that a backend save still clears the key the sites read.
 */
class StaticPageCacheKeyHandlerTest extends StoreExtenderPluginTestCase
{
    /** Cache keys need the site definitions only, which the base case migrates early */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->defineSites([['lt', true, true], ['en', false, true]]);
    }

    public function tearDown(): void
    {
        $this->app->forgetInstance('execution.context');

        parent::tearDown();
    }

    public function testARequestOutsideEverySiteDoesNotShareTheEnglishSiteKeys()
    {
        foreach (StaticPageCacheKeyHandler::EVENT_LIST as $sEvent) {
            $this->assertNotSame($this->cacheKey($sEvent, 'en', 'en'), $this->cacheKey($sEvent, 'lt', 'en'), $sEvent);
        }
    }

    public function testABackendSaveClearsTheKeysTheSitesRead()
    {
        foreach (StaticPageCacheKeyHandler::EVENT_LIST as $sEvent) {
            $this->app->instance('execution.context', 'backend');
            $sBackendKey = $this->cacheKey($sEvent, 'lt', 'en');

            $this->app->instance('execution.context', 'frontend');
            $this->assertSame($this->cacheKey($sEvent, 'en', 'en'), $sBackendKey, $sEvent);
        }
    }

    /**
     * cacheKey fires a RainLab Pages cache key event the way Pages builds its keys.
     * @param string $sEvent
     * @param string $sTranslateLocale
     * @param string $sAppLocale
     * @return string
     */
    protected function cacheKey($sEvent, $sTranslateLocale, $sAppLocale)
    {
        Translator::instance()->setLocale($sTranslateLocale, false);
        App::setLocale($sAppLocale);

        $sKey = 'theme-static-page-url-map';
        Event::fire($sEvent, [&$sKey]);

        return $sKey;
    }
}
