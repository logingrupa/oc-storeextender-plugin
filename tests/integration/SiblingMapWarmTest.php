<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Db;
use Schema;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;
use Logingrupa\StoreExtender\Console\ForgetSiblingMaps;
use Logingrupa\StoreExtender\Console\WarmSiblingMaps;

/**
 * Both sibling surfaces are filled out of band and read from cache only.
 *
 * The regression this file exists to prevent is a sibling fetch inside a page
 * render: that cost a measured 0.9 s cold and 10 s for a real visitor when a
 * sibling did not answer. So the two request readers must send nothing, and
 * everything that does fetch must live in the warm command, fail loudly and
 * stay checkable for liveness.
 */
class SiblingMapWarmTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const SIBLING_LV = 'https://nailscosmetics.lv';
    const SIBLING_LT = 'https://nailscosmetics.lt';
    const SIBLING_NO = 'https://nailscosmetics.no';

    const PRODUCT_ID = 'a1b2c3d4-0000-0000-0000-000000000001';

    public function setUp(): void
    {
        parent::setUp();

        $this->defineSites([['lv', 1, 1, '/lv']]);

        Schema::create('lovata_shopaholic_categories', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->string('external_id')->nullable();
            $obTable->integer('parent_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('nest_left')->nullable();
            $obTable->integer('nest_right')->nullable();
            $obTable->integer('nest_depth')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_shopaholic_products', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->string('external_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });
        Db::table('lovata_shopaholic_products')->insert([
            'id' => 1, 'name' => 'Polygel', 'slug' => 'polygel-uvled',
            'external_id' => self::PRODUCT_ID, 'active' => 1, 'deleted_at' => null,
            'updated_at' => '2026-11-06 10:00:00',
        ]);
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testBothRequestReadersSendNothingWhateverIsCached()
    {
        $this->fakeHttp();

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/p/polygel-uvled'), 'nothing cached');
        $this->assertSame([], SiblingShopMap::read(self::SIBLING_LV));

        Cache::forever(SiblingShopSitemap::cacheKey(self::SIBLING_LV), []);
        Cache::forever(SiblingShopMap::cacheKey(self::SIBLING_LV), []);

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/p/polygel-uvled'), 'a cached empty url set');
        $this->assertSame([], SiblingShopMap::read(self::SIBLING_LV));

        Http::assertNothingSent();
    }

    public function testACachedUrlSetStillAnswersTheTwigCheck()
    {
        $this->fakeHttp();
        Cache::forever(SiblingShopSitemap::cacheKey(self::SIBLING_LV), [self::SIBLING_LV . '/lv/p/polygel-uvled' => true]);

        $this->assertTrue(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/lv/p/polygel-uvled'));
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/lv/p/only-here'));

        Http::assertNothingSent();
    }

    public function testWarmFillsBothSurfacesAndWritesTheLease()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertStringContainsString('id-map: 1 entries', $sOutput);
        $this->assertStringContainsString('sitemap: 2 entries', $sOutput);

        $this->assertSame(
            [self::PRODUCT_ID => self::SIBLING_LV . '/lv/p/polygel-uvled'],
            SiblingShopMap::read(self::SIBLING_LV)
        );
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/lv/hjelp'));

        $this->assertSame(SiblingShopMap::TTL_OK_MINUTES, $this->cacheTtlMinutes(SiblingShopMap::cacheKey(self::SIBLING_LV)));
        $this->assertSame(SiblingShopSitemap::TTL_OK_MINUTES, $this->cacheTtlMinutes(SiblingShopSitemap::cacheKey(self::SIBLING_LV)));

        $this->assertTrue(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_ID_MAP, self::SIBLING_LV));
        $this->assertTrue(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_SITEMAP, self::SIBLING_LV));
        $this->assertNotNull(SiblingShopMap::lastSuccessAt(SiblingShopMap::SURFACE_ID_MAP, self::SIBLING_LV));
    }

    public function testASecondRunIsANoOpOnAFreshLeaseUnlessForced()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->fakeHealthySibling(self::SIBLING_LV);
        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::SUCCESS, $iExit);
        $this->assertStringContainsString('id-map: lease still fresh, skipped', $sOutput);
        $this->assertStringContainsString('sitemap: lease still fresh, skipped', $sOutput);
        Http::assertNothingSent();

        $this->fakeHealthySibling(self::SIBLING_LV);
        [$iForcedExit, $sForcedOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $iForcedExit);
        $this->assertStringContainsString('id-map: 1 entries', $sForcedOutput);
        Http::assertSentCount(2);
    }

    public function testAFailedSurfaceIsLoudAndKeepsNoLease()
    {
        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response('', 404),
            self::SIBLING_LV . '/sitemap.xml' => Http::response($this->sitemapBody(self::SIBLING_LV)),
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::FAILURE, $iExit, 'a failed surface must not exit zero');
        $this->assertStringContainsString('id-map: FAILED', $sOutput);
        $this->assertStringContainsString('sitemap: 2 entries', $sOutput);
        $this->assertFalse(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_ID_MAP, self::SIBLING_LV), 'no lease, so the next tick retries');
        $this->assertTrue(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_SITEMAP, self::SIBLING_LV));
    }

    public function testATimeoutAndAMalformedBodyBothCountAsAFailedSurface()
    {
        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => function () {
                throw new ConnectionException('cURL error 28');
            },
            self::SIBLING_LV . '/sitemap.xml' => Http::response('<urlset></urlset>'),
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('id-map: FAILED', $sOutput, 'a timeout');
        $this->assertStringContainsString('sitemap: FAILED', $sOutput, 'a sitemap listing no url');

        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response(['shop' => self::SIBLING_LV]),
            self::SIBLING_LV . '/sitemap.xml' => Http::response($this->sitemapBody(self::SIBLING_LV)),
        ]);

        [$iMalformedExit, $sMalformedOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::FAILURE, $iMalformedExit);
        $this->assertStringContainsString('id-map: FAILED', $sMalformedOutput, 'a body with no map key');
    }

    public function testAFailureWithNothingCachedRemembersTheMissPerSurfaceTtl()
    {
        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response('', 503),
            self::SIBLING_LV . '/sitemap.xml' => Http::response('', 503),
        ]);

        $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame([], Cache::get(SiblingShopMap::cacheKey(self::SIBLING_LV)));
        $this->assertSame([], Cache::get(SiblingShopSitemap::cacheKey(self::SIBLING_LV)));
        $this->assertSame(5, $this->cacheTtlMinutes(SiblingShopMap::cacheKey(self::SIBLING_LV)), 'the deploy race wants a short retry');
        $this->assertSame(60, $this->cacheTtlMinutes(SiblingShopSitemap::cacheKey(self::SIBLING_LV)), 'the path fallback keeps its own hour');
    }

    public function testAFailureLeavesAGoodCachedValueAloneOnBothSurfaces()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response('', 503),
            self::SIBLING_LV . '/sitemap.xml' => Http::response('', 503),
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--force' => true]);

        $this->assertSame(Command::FAILURE, $iExit, $sOutput);
        $this->assertSame(
            [self::PRODUCT_ID => self::SIBLING_LV . '/lv/p/polygel-uvled'],
            SiblingShopMap::read(self::SIBLING_LV),
            'a failed fetch never blanks a good map'
        );
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/lv/hjelp'));
    }

    public function testAPublishedUrlOffTheSiblingOriginIsDropped()
    {
        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response([
                'shop' => self::SIBLING_LV,
                'map' => [
                    self::PRODUCT_ID => self::SIBLING_LV . '/lv/p/polygel-uvled',
                    'poisoned-id' => 'https://evil.example/lv/p/polygel-uvled',
                    'relative-id' => '/lv/p/polygel-uvled',
                ],
            ]),
            self::SIBLING_LV . '/sitemap.xml' => Http::response($this->sitemapBody(self::SIBLING_LV)),
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::SUCCESS, $iExit);
        $this->assertStringContainsString('id-map: 1 entries', $sOutput, 'two of the three rows were dropped');
        $this->assertSame(
            [self::PRODUCT_ID => self::SIBLING_LV . '/lv/p/polygel-uvled'],
            SiblingShopMap::read(self::SIBLING_LV),
            'no foreign href can reach our head tag'
        );
    }

    /**
     * A sibling that deactivated its last publishable row answers 200 with an
     * empty map. Treating that as a failure would keep the previous map until
     * its 24 h expiry and go on advertising entities the sibling stopped
     * serving.
     */
    public function testAValidEmptyMapReplacesWhatWeHold()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);
        $this->assertNotSame([], SiblingShopMap::read(self::SIBLING_LV), 'a map is held first');

        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response([
                'shop' => self::SIBLING_LV,
                'map' => [],
            ]),
            self::SIBLING_LV . '/sitemap.xml' => Http::response($this->sitemapBody(self::SIBLING_LV)),
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertStringContainsString('id-map: 0 entries', $sOutput);
        $this->assertSame([], Cache::get(SiblingShopMap::cacheKey(self::SIBLING_LV)), 'the stale map is gone');
        $this->assertSame(SiblingShopMap::TTL_OK_MINUTES, $this->cacheTtlMinutes(SiblingShopMap::cacheKey(self::SIBLING_LV)), 'stored as a good answer, not as a failure');
        $this->assertTrue(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_ID_MAP, self::SIBLING_LV));
    }

    public function testAMapWithNothingOnTheSiblingOriginIsAFailure()
    {
        $this->fakeHttp([
            self::SIBLING_LV . SiblingShopMap::ROUTE_PATH => Http::response([
                'shop' => self::SIBLING_LV,
                'map' => ['poisoned-id' => 'https://evil.example/lv/p/polygel-uvled'],
            ]),
            self::SIBLING_LV . '/sitemap.xml' => Http::response($this->sitemapBody(self::SIBLING_LV)),
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('id-map: FAILED', $sOutput);
    }

    public function testTheRunSkipsTheShopsOwnOrigin()
    {
        $this->pinOwnOrigin(self::SIBLING_NO);
        $this->fakeHealthyFamily([self::SIBLING_LV, self::SIBLING_LT]);

        [, $sOutput] = $this->runWarm();

        $this->assertStringContainsString(self::SIBLING_LV, $sOutput);
        $this->assertStringContainsString(self::SIBLING_LT, $sOutput);
        $this->assertStringNotContainsString(self::SIBLING_NO . ' ', $sOutput, 'a shop does not fetch itself');
    }

    public function testTheRunPrimesTheShopsOwnPublishedBody()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);

        [, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertStringContainsString('own published body: 1 entries', $sOutput);
        $this->assertSame(
            [self::PRODUCT_ID => ShopIdMap::primaryBaseUrl() . '/p/polygel-uvled'],
            Cache::get(ShopIdMap::BODY_CACHE_KEY . md5(ShopIdMap::catalogueStamp()))['map'],
            'the next sibling fetch and the next visitor both hit a warm body'
        );
    }

    public function testStatusReportsEverySurfaceAndFailsWhileOneIsStale()
    {
        $this->fakeHttp();

        [$iColdExit, $sColdOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--status' => true]);

        $this->assertSame(Command::FAILURE, $iColdExit, 'a surface that never warmed reads as stale');
        $this->assertStringContainsString('id-map: never warmed on this box', $sColdOutput);
        $this->assertStringContainsString('sitemap: never warmed on this box', $sColdOutput);
        Http::assertNothingSent();

        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);

        [$iWarmExit, $sWarmOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--status' => true]);
        $this->assertSame(Command::SUCCESS, $iWarmExit, $sWarmOutput);
        $this->assertStringContainsString('id-map: last success 0 min ago', $sWarmOutput);
        $this->assertStringContainsString('sitemap: last success 0 min ago', $sWarmOutput);

        Carbon::setTestNow(Carbon::now()->addMinutes(WarmSiblingMaps::STATUS_THRESHOLD_MINUTES + 30));

        [$iStaleExit, $sStaleOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--status' => true]);
        $this->assertSame(Command::FAILURE, $iStaleExit, 'a stopped scheduler is detectable, not silent');
        $this->assertStringContainsString('STALE', $sStaleOutput);
    }

    public function testForgetClearsBothSurfacesAndBothLeasesForEverySibling()
    {
        $this->fakeHealthyFamily([self::SIBLING_LV, self::SIBLING_LT, self::SIBLING_NO]);
        $this->runWarm();

        [$iExit, $sOutput] = $this->runCommand(new ForgetSiblingMaps);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        foreach ([self::SIBLING_LV, self::SIBLING_LT, self::SIBLING_NO] as $sDomain) {
            $this->assertSame([], SiblingShopMap::read($sDomain), $sDomain);
            $this->assertSame([], SiblingShopSitemap::urlSet($sDomain), $sDomain);
            $this->assertFalse(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_ID_MAP, $sDomain));
            $this->assertFalse(SiblingShopMap::leaseIsFresh(SiblingShopMap::SURFACE_SITEMAP, $sDomain));
        }
    }

    protected function fakeHealthySibling(string $sDomain): void
    {
        $this->fakeHealthyFamily([$sDomain]);
    }

    /**
     * @param string[] $arDomainList
     */
    protected function fakeHealthyFamily(array $arDomainList): void
    {
        $arStubList = [];
        foreach ($arDomainList as $sDomain) {
            $arStubList[$sDomain . SiblingShopMap::ROUTE_PATH] = Http::response([
                'shop' => $sDomain,
                'generated_at' => '2026-11-13T10:00:00+02:00',
                'map' => [self::PRODUCT_ID => $sDomain . '/lv/p/polygel-uvled'],
            ]);
            $arStubList[$sDomain . '/sitemap.xml'] = Http::response($this->sitemapBody($sDomain));
        }

        $this->fakeHttp($arStubList);
    }

    /**
     * Http::fake() MERGES its stubs into the ones already registered and the
     * first match wins, so a test that changes what a sibling answers needs a
     * fresh client factory rather than a second fake.
     *
     * @param array<string,mixed>|null $arStubList null fakes every host
     */
    protected function fakeHttp($arStubList = null): void
    {
        Http::swap(new HttpFactory);
        Http::fake($arStubList);
    }

    protected function sitemapBody(string $sDomain): string
    {
        return '<?xml version="1.0"?><urlset>'
            . '<url><loc>' . $sDomain . '/lv/p/polygel-uvled</loc></url>'
            . '<url><loc>' . $sDomain . '/lv/hjelp/</loc></url>'
            . '</urlset>';
    }

    /**
     * Makes the shop's own origin one of the three cross domains, which is only
     * true on a real box.
     */
    protected function pinOwnOrigin(string $sOrigin): void
    {
        Db::table('system_site_definitions')->update([
            'is_custom_url' => 1,
            'app_url' => $sOrigin,
            'is_prefixed' => 0,
            'route_prefix' => '',
        ]);

        $this->assertSame($sOrigin, ShopIdMap::ownOrigin());
    }

    /**
     * @return array{0:int,1:string}
     */
    protected function runWarm(array $arInput = []): array
    {
        return $this->runCommand(new WarmSiblingMaps, $arInput);
    }

    /**
     * @return array{0:int,1:string}
     */
    protected function runCommand(Command $obCommand, array $arInput = []): array
    {
        $obCommand->setLaravel($this->app);
        $obOutput = new BufferedOutput;
        $iExit = $obCommand->run(new ArrayInput($arInput), $obOutput);

        return [$iExit, $obOutput->fetch()];
    }

    /**
     * How long the array cache store will keep an entry, in whole minutes: the
     * TTL is part of each surface's contract, not an implementation detail.
     */
    protected function cacheTtlMinutes(string $sKey): int
    {
        $obStore = Cache::getStore();
        $obProperty = (new ReflectionClass($obStore))->getProperty('storage');
        $obProperty->setAccessible(true);
        $arStorage = $obProperty->getValue($obStore);

        $this->assertArrayHasKey($sKey, $arStorage, 'nothing is cached under ' . $sKey);

        return (int) round(($arStorage[$sKey]['expiresAt'] - Carbon::now()->getTimestamp()) / 60);
    }
}
