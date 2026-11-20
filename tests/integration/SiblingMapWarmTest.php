<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Db;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;
use Logingrupa\StoreExtender\Console\ForgetSiblingMaps;
use Logingrupa\StoreExtender\Console\WarmSiblingMaps;

/**
 * The sibling sitemap cache is filled out of band and read from cache only.
 *
 * The regression this file exists to prevent is a sibling fetch inside a page
 * render: that cost a measured 0.9 s cold and 10 s for a real visitor when a
 * sibling did not answer. So the request reader must send nothing, and
 * everything that does fetch must live in the warm command, fail loudly and
 * stay checkable for liveness.
 */
class SiblingMapWarmTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const SIBLING_LV = 'https://nailscosmetics.lv';
    const SIBLING_LT = 'https://nailscosmetics.lt';
    const SIBLING_NO = 'https://nailscosmetics.no';

    public function setUp(): void
    {
        parent::setUp();

        $this->defineSites([['lv', 1, 1, '/lv']]);
    }

    public function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testTheRequestReaderSendsNothingWhateverIsCached()
    {
        $this->fakeHttp();

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/p/polygel-uvled'), 'nothing cached');
        $this->assertSame([], SiblingShopSitemap::urlSet(self::SIBLING_LV));

        Cache::forever(SiblingShopSitemap::cacheKey(self::SIBLING_LV), []);

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/p/polygel-uvled'), 'a cached empty url set');

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

    public function testWarmFillsTheUrlSetAndWritesTheLease()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertStringContainsString('sitemap: 2 entries', $sOutput);
        $this->assertStringContainsString('id map: 1 entries', $sOutput);
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/lv/hjelp'));
        $this->assertNotNull(SiblingShopIdMap::lastSuccessAt(self::SIBLING_LV), 'the id map warms alongside the sitemap');
        $this->assertSame(SiblingShopSitemap::TTL_OK_MINUTES, $this->cacheTtlMinutes(SiblingShopSitemap::cacheKey(self::SIBLING_LV)));
        $this->assertTrue(SiblingShopSitemap::leaseIsFresh(self::SIBLING_LV));
        $this->assertNotNull(SiblingShopSitemap::lastSuccessAt(self::SIBLING_LV));
    }

    public function testASecondRunIsANoOpOnAFreshLeaseUnlessForced()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->fakeHealthySibling(self::SIBLING_LV);
        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::SUCCESS, $iExit);
        $this->assertStringContainsString('sitemap: lease still fresh, skipped', $sOutput);
        Http::assertNothingSent();

        $this->fakeHealthySibling(self::SIBLING_LV);
        [$iForcedExit, $sForcedOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--force' => true]);

        $this->assertSame(Command::SUCCESS, $iForcedExit);
        $this->assertStringContainsString('sitemap: 2 entries', $sForcedOutput);
        Http::assertSentCount(2, 'one fetch per published resource, the sitemap and the id map');
    }

    public function testAFailedSiblingIsLoudAndKeepsNoLease()
    {
        $this->fakeHttp([self::SIBLING_LV . '/sitemap.xml' => Http::response('', 404)]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame(Command::FAILURE, $iExit, 'a failed sibling must not exit zero');
        $this->assertStringContainsString('sitemap: FAILED', $sOutput);
        $this->assertFalse(SiblingShopSitemap::leaseIsFresh(self::SIBLING_LV), 'no lease, so the next tick retries');
    }

    public function testATimeoutAndASitemapWithNoUrlBothCountAsAFailure()
    {
        $this->fakeHttp([
            self::SIBLING_LV . '/sitemap.xml' => function () {
                throw new ConnectionException('cURL error 28');
            },
        ]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);
        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('sitemap: FAILED', $sOutput, 'a timeout');

        $this->fakeHttp([self::SIBLING_LV . '/sitemap.xml' => Http::response('<urlset></urlset>')]);

        [$iEmptyExit, $sEmptyOutput] = $this->runWarm(['--domain' => self::SIBLING_LV]);
        $this->assertSame(Command::FAILURE, $iEmptyExit);
        $this->assertStringContainsString('sitemap: FAILED', $sEmptyOutput, 'a sitemap listing no url');
    }

    public function testAFailureWithNothingCachedRemembersTheMissForAnHour()
    {
        $this->fakeHttp([self::SIBLING_LV . '/sitemap.xml' => Http::response('', 503)]);

        $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->assertSame([], Cache::get(SiblingShopSitemap::cacheKey(self::SIBLING_LV)));
        $this->assertSame(60, $this->cacheTtlMinutes(SiblingShopSitemap::cacheKey(self::SIBLING_LV)), 'the path fallback keeps its own hour');
    }

    public function testAFailureLeavesAGoodCachedValueAlone()
    {
        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);

        $this->fakeHttp([self::SIBLING_LV . '/sitemap.xml' => Http::response('', 503)]);

        [$iExit, $sOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--force' => true]);

        $this->assertSame(Command::FAILURE, $iExit, $sOutput);
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::SIBLING_LV, '/lv/hjelp'), 'a failed fetch never blanks a good url set');
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

    public function testStatusReportsEverySiblingAndFailsWhileOneIsStale()
    {
        $this->fakeHttp();

        [$iColdExit, $sColdOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--status' => true]);

        $this->assertSame(Command::FAILURE, $iColdExit, 'a sibling that never warmed reads as stale');
        $this->assertStringContainsString('sitemap: never warmed on this box', $sColdOutput);
        Http::assertNothingSent();

        $this->fakeHealthySibling(self::SIBLING_LV);
        $this->runWarm(['--domain' => self::SIBLING_LV]);

        [$iWarmExit, $sWarmOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--status' => true]);
        $this->assertSame(Command::SUCCESS, $iWarmExit, $sWarmOutput);
        $this->assertStringContainsString('sitemap: last success 0 min ago', $sWarmOutput);

        Carbon::setTestNow(Carbon::now()->addMinutes(WarmSiblingMaps::STATUS_THRESHOLD_MINUTES + 30));

        [$iStaleExit, $sStaleOutput] = $this->runWarm(['--domain' => self::SIBLING_LV, '--status' => true]);
        $this->assertSame(Command::FAILURE, $iStaleExit, 'a stopped scheduler is detectable, not silent');
        $this->assertStringContainsString('STALE', $sStaleOutput);
    }

    public function testForgetClearsTheUrlSetAndTheLeaseForEverySibling()
    {
        $this->fakeHealthyFamily([self::SIBLING_LV, self::SIBLING_LT, self::SIBLING_NO]);
        $this->runWarm();

        [$iExit, $sOutput] = $this->runCommand(new ForgetSiblingMaps);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        foreach ([self::SIBLING_LV, self::SIBLING_LT, self::SIBLING_NO] as $sDomain) {
            $this->assertSame([], SiblingShopSitemap::urlSet($sDomain), $sDomain);
            $this->assertFalse(SiblingShopSitemap::leaseIsFresh($sDomain));
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
            $arStubList[$sDomain . '/sitemap.xml'] = Http::response($this->sitemapBody($sDomain));
            // A sibling publishes two things now, and a warm that cannot read
            // the id map is a failed warm, so a healthy fake serves both
            $arStubList[$sDomain . SiblingShopIdMap::MAP_PATH] = Http::response([
                ShopIdMap::BUCKET_PRODUCTS => ['guid-polygel' => '/p/polygel-uvled'],
                ShopIdMap::BUCKET_CATEGORIES => [],
            ]);
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
     * TTL is part of the surface's contract, not an implementation detail.
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
