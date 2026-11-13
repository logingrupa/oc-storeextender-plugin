<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * A cross-domain hreflang link is emitted only for a URL the other shop lists
 * in its sitemap, and a shop that cannot be read contributes no links rather
 * than guessed ones.
 *
 * The url set is now filled by storeextender:warm-sibling-maps and read from
 * cache only, so these cases enter through warm() and leave through hasUrl().
 * The reader fetching nothing is the point: hreflang.htm calls it once per
 * cross-domain site, and a miss used to fetch a sibling sitemap inline.
 */
class SiblingShopSitemapTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const DOMAIN = 'https://sibling.test';

    public function setUp(): void
    {
        parent::setUp();
        SiblingShopSitemap::forget(self::DOMAIN);
    }

    public function testOnlyWarmedUrlsExistAndTheReaderFetchesNothing()
    {
        Http::fake([
            self::DOMAIN . '/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://sibling.test</loc></url>'
                . '<url><loc>https://sibling.test/p/polygel-uvled</loc></url>'
                . '<url><loc>https://sibling.test/lt/gels/</loc></url></urlset>'
            ),
        ]);

        $this->assertSame(3, SiblingShopSitemap::warm(self::DOMAIN));
        Http::assertSentCount(1);

        $this->assertTrue(SiblingShopSitemap::hasUrl(self::DOMAIN, ''), 'home');
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/polygel-uvled'));
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::DOMAIN, '/lt/gels'), 'trailing slash in the sitemap is ignored');
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/only-here'));
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/hjelp/vilkar'));

        Http::assertSentCount(1, 'the reader adds nothing to the warm fetch');
    }

    public function testAnUnwarmedDomainIsNeverFetchedDuringARender()
    {
        Http::fake();

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/polygel-uvled'));
        $this->assertSame([], SiblingShopSitemap::urlSet(self::DOMAIN));

        Http::assertNothingSent();
    }

    public function testAnUnreachableSitemapMeansNoUrlsAndIsRememberedForAnHour()
    {
        Http::fake([self::DOMAIN . '/sitemap.xml' => Http::response('', 503)]);

        $this->assertNull(SiblingShopSitemap::warm(self::DOMAIN));
        Http::assertSentCount(1);

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, ''));
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/polygel-uvled'));

        Http::assertSentCount(1, 'a failed warm is not retried by a render');
        $this->assertSame([], Cache::get(SiblingShopSitemap::cacheKey(self::DOMAIN)));
        $this->assertSame(60, SiblingShopSitemap::TTL_FAILED_MINUTES, 'the path fallback keeps its own hour');
    }
}
