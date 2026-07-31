<?php namespace Logingrupa\StoreExtender\Tests\Unit\Color;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\StoreExtender\Classes\Color\ColorApiClient;

/**
 * Uses the framework's own Http::fake stub layer (no mock framework added).
 * Needs the booted app for Cache/Http facades, hence PluginTestCase. Guarded
 * like XmlImportPriceFlickerTest: environments that cannot migrate the Lovata
 * plugins on SQLite skip instead of going red.
 */
class ColorApiClientTest extends \PluginTestCase
{
    /** @var array Valid payload fixture matching the upstream contract */
    protected $arValidBody = [
        'version' => 'abc123',
        'count'   => 1,
        'offers'  => [
            'offer-uuid' => ['family' => 'Pink', 'hex' => '#EC407A', 'hue' => 354.2, 'lightness' => 0.64],
        ],
    ];

    public function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\Throwable $obException) {
            $this->markTestSkipped('Plugin migrations failed on this environment: '.$obException->getMessage());
        }
        Cache::flush();
    }

    public function testFreshFetchStoresBodyAndEtag()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200, ['ETag' => '"v1"'])]);

        $arColorMap = (new ColorApiClient())->getColorMap();

        $this->assertArrayHasKey('offer-uuid', $arColorMap);
        $this->assertSame($this->arValidBody, Cache::get(ColorApiClient::CACHE_KEY_BODY));
        $this->assertSame('"v1"', Cache::get(ColorApiClient::CACHE_KEY_ETAG));
        $this->assertTrue(Cache::has(ColorApiClient::CACHE_KEY_FRESH));
    }

    public function testStaleRefreshSendsIfNoneMatchAnd304ExtendsTtl()
    {
        // cached body + etag present, fresh flag expired -> refresh path
        Cache::forever(ColorApiClient::CACHE_KEY_BODY, $this->arValidBody);
        Cache::forever(ColorApiClient::CACHE_KEY_ETAG, '"v1"');
        Http::fake(['*' => Http::response(null, 304)]);

        $arColorMap = (new ColorApiClient())->getColorMap();

        Http::assertSent(function ($obRequest) {
            return $obRequest->header('If-None-Match') === ['"v1"'];
        });
        $this->assertArrayHasKey('offer-uuid', $arColorMap);
        $this->assertTrue(Cache::has(ColorApiClient::CACHE_KEY_FRESH));
    }

    public function testMalformedJsonFallsBackToLastCachedBody()
    {
        Cache::forever(ColorApiClient::CACHE_KEY_BODY, $this->arValidBody);
        Http::fake(['*' => Http::response('not json at all', 200)]);

        $arColorMap = (new ColorApiClient())->getColorMap();

        $this->assertArrayHasKey('offer-uuid', $arColorMap);
        // last known good body must survive the malformed response
        $this->assertSame($this->arValidBody, Cache::get(ColorApiClient::CACHE_KEY_BODY));
    }

    public function testMalformedJsonWithEmptyCacheReturnsEmptyMap()
    {
        Http::fake(['*' => Http::response('{"nope": true}', 200)]);

        $this->assertSame([], (new ColorApiClient())->getColorMap());
    }

    public function testNetworkErrorWithEmptyCacheReturnsEmptyMapAndNeverThrows()
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('host unreachable');
        });

        $this->assertSame([], (new ColorApiClient())->getColorMap());
        // short retry window set so the storefront is not slowed down per request
        $this->assertTrue(Cache::has(ColorApiClient::CACHE_KEY_FRESH));
    }
}
