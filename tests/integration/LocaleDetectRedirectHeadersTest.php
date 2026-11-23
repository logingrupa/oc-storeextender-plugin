<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cms\Classes\CmsController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Logingrupa\StoreExtender\Classes\Middleware\LocaleDetectRedirectHeaders;

/**
 * Bare-URL language-detected redirects on multi-language prefixed shops (.lv, .lt) must
 * return 302 with Vary: Accept-Language and Cache-Control: private, no-cache.
 * Deterministic 301s (already-prefixed legacy category redirects, single-site shops,
 * policy != detect, and fallback paths whose Location is not a prefix attachment) and
 * non-redirect responses must pass through untouched.
 */
class LocaleDetectRedirectHeadersTest extends StoreExtenderPluginTestCase
{
    /** Core module schema only */
    protected $autoMigrate = false;

    const APP_URL = 'https://nailscosmetics.test';

    public function setUp(): void
    {
        parent::setUp();

        Config::set('app.url', self::APP_URL);
        Config::set('cms.redirect_policy', 'detect');
        URL::forceRootUrl(self::APP_URL);
        URL::forceScheme('https');
    }

    /**
     * Dispatch a request through LocaleDetectRedirectHeaders wrapping CmsController::run().
     *
     * @param string      $sUri
     * @param string|null $sAcceptLanguage
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function runThroughCmsController(string $sUri, ?string $sAcceptLanguage = null)
    {
        $arServer = [];
        if ($sAcceptLanguage !== null) {
            $arServer['HTTP_ACCEPT_LANGUAGE'] = $sAcceptLanguage;
        }

        $obRequest = Request::create(self::APP_URL . $sUri, 'GET', [], [], [], $arServer);
        if ($sAcceptLanguage === null) {
            // Symfony Request::create() seeds HTTP_ACCEPT_LANGUAGE=en-us,en;q=0.5 by default
            $obRequest->server->remove('HTTP_ACCEPT_LANGUAGE');
            $obRequest->headers->remove('Accept-Language');
        }
        $this->app->instance('request', $obRequest);

        $sPath = ltrim($obRequest->getPathInfo(), '/');
        if ($sPath === '') {
            $sPath = '/';
        }

        $obMiddleware = new LocaleDetectRedirectHeaders();

        return $obMiddleware->handle($obRequest, function () use ($sPath) {
            return (new CmsController())->run($sPath);
        });
    }

    /**
     * Split every Vary header line into trimmed header names.
     *
     * @param \Symfony\Component\HttpFoundation\Response $obResponse
     * @return array<string>
     */
    protected function getVaryValueList($obResponse): array
    {
        $arValueList = [];
        foreach ($obResponse->headers->all('Vary') as $sHeaderValue) {
            foreach (explode(',', $sHeaderValue) as $sValue) {
                $arValueList[] = trim($sValue);
            }
        }

        return $arValueList;
    }

    public function testBareDeepLinkUses302WithVaryAcceptLanguageForBrowserLocale()
    {
        $this->defineSites([
            ['lv', true, true, '/lv'],
            ['en', false, true, '/en'],
            ['ru', false, true, '/ru'],
        ]);

        $obResponse = $this->runThroughCmsController('/p/acetona-zimulis/1040?utm_source=ig', 'ru-RU,ru;q=0.9');

        $this->assertInstanceOf(RedirectResponse::class, $obResponse);
        $this->assertSame(302, $obResponse->getStatusCode());
        $this->assertSame(self::APP_URL . '/ru/p/acetona-zimulis/1040?utm_source=ig', $obResponse->headers->get('Location'));
        $this->assertContains('Accept-Language', $this->getVaryValueList($obResponse));
        $this->assertTrue($obResponse->headers->hasCacheControlDirective('private'));
        $this->assertTrue($obResponse->headers->hasCacheControlDirective('no-cache'));
    }

    public function testBareRootWithoutAcceptLanguageFallsBackToPrimaryWith302AndVary()
    {
        $this->defineSites([
            ['lv', true, true, '/lv'],
            ['en', false, true, '/en'],
            ['ru', false, true, '/ru'],
        ]);

        $obResponse = $this->runThroughCmsController('/', null);

        $this->assertInstanceOf(RedirectResponse::class, $obResponse);
        $this->assertSame(302, $obResponse->getStatusCode());
        $this->assertSame(self::APP_URL . '/lv', $obResponse->headers->get('Location'));
        $this->assertContains('Accept-Language', $this->getVaryValueList($obResponse));
    }

    public function testPrefixedLegacy301Stays301WithoutVaryAcceptLanguage()
    {
        $this->defineSites([
            ['lv', true, true, '/lv'],
            ['en', false, true, '/en'],
            ['ru', false, true, '/ru'],
        ]);

        $obRequest = Request::create(self::APP_URL . '/lv/manikirspedikirs/gellakas/quick-gellakas', 'GET');
        $this->app->instance('request', $obRequest);

        $obSite = Site::getSiteFromRequest(self::APP_URL, 'lv/manikirspedikirs/gellakas/quick-gellakas');
        Site::applyActiveSite($obSite);

        $obResponse = (new LocaleDetectRedirectHeaders())->handle($obRequest, function () {
            return new RedirectResponse(self::APP_URL . '/lv/manikirspedikirs/gellakas', 301);
        });

        $this->assertSame(301, $obResponse->getStatusCode());
        $this->assertNotContains('Accept-Language', $this->getVaryValueList($obResponse));
    }

    public function testFallbackPathThatFellThroughToDifferentTargetStays301()
    {
        // October's raw-substr removeRoutePrefix() strips "lv" from "lvx", skipping
        // redirectWithoutPrefix() and letting Controller::run('x') issue its own 301.
        $this->defineSites([
            ['lv', true, true, '/lv'],
            ['ru', false, true, '/ru'],
        ]);

        $obRequest = Request::create(self::APP_URL . '/lvx', 'GET');
        $this->app->instance('request', $obRequest);

        $obSite = Site::getSiteFromRequest(self::APP_URL, 'lvx');
        Site::applyActiveSite($obSite);

        $obResponse = (new LocaleDetectRedirectHeaders())->handle($obRequest, function () {
            return new RedirectResponse(self::APP_URL . '/lv/some-canonical-target', 301);
        });

        $this->assertSame(301, $obResponse->getStatusCode());
        $this->assertNotContains('Accept-Language', $this->getVaryValueList($obResponse));
    }

    public function testPrimaryRedirectPolicyStays301WithoutVaryAcceptLanguage()
    {
        Config::set('cms.redirect_policy', 'primary');

        $this->defineSites([
            ['lv', true, true, '/lv'],
            ['en', false, true, '/en'],
            ['ru', false, true, '/ru'],
        ]);

        $obResponse = $this->runThroughCmsController('/', 'ru-RU,ru;q=0.9');

        $this->assertInstanceOf(RedirectResponse::class, $obResponse);
        $this->assertSame(301, $obResponse->getStatusCode());
        $this->assertNotContains('Accept-Language', $this->getVaryValueList($obResponse));
    }

    public function testSingleEnabledPrefixedSiteKeepsDeterministic301()
    {
        $this->defineSites([
            ['lv', true, true, '/lv'],
        ]);

        $obResponse = $this->runThroughCmsController('/p/acetona-zimulis/1040', 'en-US,en;q=0.9');

        $this->assertInstanceOf(RedirectResponse::class, $obResponse);
        $this->assertSame(301, $obResponse->getStatusCode());
        $this->assertSame(self::APP_URL . '/lv/p/acetona-zimulis/1040', $obResponse->headers->get('Location'));
        $this->assertNotContains('Accept-Language', $this->getVaryValueList($obResponse));
    }

    public function testUnprefixedNorwegianShapeDoesNotTriggerLocaleRedirectRewrite()
    {
        // .no shape: primary lv is disabled, single enabled unprefixed nb-no serves directly
        $this->defineSites([
            ['lv', true, false, '/lv'],
            ['nb-no', false, true, ''],
        ]);

        $obRequest = Request::create(self::APP_URL . '/p/aceton-penn', 'GET');
        $this->app->instance('request', $obRequest);

        $obSite = Site::getSiteFromRequest(self::APP_URL, 'p/aceton-penn');
        Site::applyActiveSite($obSite);

        $obResponse = (new LocaleDetectRedirectHeaders())->handle($obRequest, function () {
            return new RedirectResponse(self::APP_URL . '/p/aceton-penn-new', 301);
        });

        $this->assertSame(301, $obResponse->getStatusCode());
        $this->assertNotContains('Accept-Language', $this->getVaryValueList($obResponse));
    }

    public function testPreservesExistingVaryAndUpstreamNoStore()
    {
        $this->defineSites([
            ['lv', true, true, '/lv'],
            ['ru', false, true, '/ru'],
        ]);

        $obRequest = Request::create(self::APP_URL . '/p/acetona-zimulis', 'GET');
        $this->app->instance('request', $obRequest);

        $obSite = Site::getSiteFromRequest(self::APP_URL, 'p/acetona-zimulis');
        Site::applyActiveSite($obSite);

        $obResponse = (new LocaleDetectRedirectHeaders())->handle($obRequest, function () {
            $obRedirect = new RedirectResponse(self::APP_URL . '/lv/p/acetona-zimulis', 301);
            $obRedirect->setVary('Accept-Encoding');
            $obRedirect->headers->set('Cache-Control', 'no-store, private');

            return $obRedirect;
        });

        $this->assertSame(302, $obResponse->getStatusCode());
        $this->assertContains('Accept-Encoding', $this->getVaryValueList($obResponse));
        $this->assertContains('Accept-Language', $this->getVaryValueList($obResponse));
        $this->assertTrue($obResponse->headers->hasCacheControlDirective('no-store'));
    }

    public function testNonRedirectResponseIsUntouched()
    {
        $this->defineSites([
            ['nb-no', true, true, ''],
        ]);

        $obRequest = Request::create(self::APP_URL . '/p/aceton-penn', 'GET');
        $obOriginal = new Response('ok', 200);

        $obReturned = (new LocaleDetectRedirectHeaders())->handle($obRequest, fn () => $obOriginal);

        $this->assertSame($obOriginal, $obReturned);
        $this->assertSame(200, $obReturned->getStatusCode());
        $this->assertSame([], $this->getVaryValueList($obReturned));
    }
}
