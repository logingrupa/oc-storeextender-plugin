<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * A cross-domain hreflang link is emitted only for a URL the other shop
 * lists in its sitemap, the sitemap is read once and cached, and a shop that
 * cannot be read contributes no links rather than guessed ones.
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

    public function testOnlyListedUrlsExistAndTheSitemapIsFetchedOnce()
    {
        Http::fake([
            self::DOMAIN . '/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset><url><loc>https://sibling.test</loc></url>'
                . '<url><loc>https://sibling.test/p/polygel-uvled</loc></url>'
                . '<url><loc>https://sibling.test/lt/gels/</loc></url></urlset>'
            ),
        ]);

        $this->assertTrue(SiblingShopSitemap::hasUrl(self::DOMAIN, ''), 'home');
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/polygel-uvled'));
        $this->assertTrue(SiblingShopSitemap::hasUrl(self::DOMAIN, '/lt/gels'), 'trailing slash in the sitemap is ignored');
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/only-here'));
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/hjelp/vilkar'));

        Http::assertSentCount(1);
    }

    public function testUnreachableSitemapMeansNoUrlsAndIsNotHammered()
    {
        Http::fake([self::DOMAIN . '/sitemap.xml' => Http::response('', 503)]);

        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, ''));
        $this->assertFalse(SiblingShopSitemap::hasUrl(self::DOMAIN, '/p/polygel-uvled'));

        Http::assertSentCount(1);
        $this->assertSame([], Cache::get(SiblingShopSitemap::CACHE_KEY . md5(self::DOMAIN)));
    }
}
