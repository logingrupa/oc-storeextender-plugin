<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Request;
use Illuminate\Http\Request as HttpRequest;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopPicker;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * The visible picker rows for the other shops of the family.
 *
 * The picker listed October's sitePicker alone, which is the sites of THIS
 * install: .lt offered lt, en and ru, and .no offered nb-no by itself, so
 * neither shop could reach Latvian at all. These cases cover the two things
 * that differ from the hreflang block reading the same maps: a row is never
 * dropped, because a shopper offered nothing is worse off than one landing on
 * a front page, and the shop being browsed is never offered back.
 */
class SiblingShopPickerTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const OWN_PATH = '/p/acetona-zimulis';
    const EXTERNAL_ID = '4eade3d6-b909-11e5-8282-b4719e4f6bc1';

    public function setUp(): void
    {
        parent::setUp();

        foreach (SiblingShopSitemap::CROSS_DOMAIN_LIST as $sDomain) {
            SiblingShopIdMap::forget($sDomain);
            SiblingShopSitemap::forget($sDomain);
        }
        SiblingShopIdMap::forgetOwn();
    }

    /** The shop being browsed is not offered as somewhere to go */
    public function testTheShopYouAreOnIsNotOfferedBack()
    {
        $this->browseAs('https://nailscosmetics.lt/lt/gellakas');

        $this->assertSame(
            ['nailscosmetics.no', 'nailscosmetics.lv'],
            array_column(SiblingShopPicker::links(), 'host')
        );
    }

    /** Latvian is the row .lt and .no never had, and it is named in Latvian */
    public function testTheLatvianRowNamesItselfInLatvian()
    {
        $this->browseAs('https://nailscosmetics.lt/lt/gellakas');

        $arLatvianRow = collect(SiblingShopPicker::links())->firstWhere('host', 'nailscosmetics.lv');

        $this->assertSame('Latviešu', $arLatvianRow['name']);
        $this->assertSame('lv', $arLatvianRow['locale']);
        $this->assertSame('lv', $arLatvianRow['flag'], 'the flag partial draws by this code');
    }

    /**
     * A host that is not one of the shops - every local and staging host -
     * keeps all three rows rather than guessing which one it stands in for.
     */
    public function testAHostOutsideTheFamilyKeepsEveryRow()
    {
        $this->browseAs('http://nc.test/lv/gellakas');

        $this->assertCount(3, SiblingShopPicker::links());
    }

    /** The row leads to this page on the other shop when the id map proves it */
    public function testAPairedPageIsWhereTheRowLeads()
    {
        $this->seedOwnMap();
        $this->seedSiblingMap('https://nailscosmetics.no', '/p/aceton-penn');

        $this->assertSame(
            'https://nailscosmetics.no/p/aceton-penn',
            SiblingShopPicker::url('https://nailscosmetics.no', self::OWN_PATH)
        );
    }

    /**
     * No pair, so the row still leads somewhere: that shop's front page. This
     * is the whole difference from the hreflang block, which must emit nothing
     * rather than pair a page with a front page.
     */
    public function testAnUnpairedPageLeadsToTheShopFrontPage()
    {
        $this->seedOwnMap();

        $this->assertSame(
            'https://nailscosmetics.lv/lv',
            SiblingShopPicker::url('https://nailscosmetics.lv', self::OWN_PATH)
        );
    }

    /**
     * .no serves its pages without a route prefix, and a joined empty prefix
     * is what produced '//path' on that shop once before.
     */
    public function testThePrefixlessShopGetsNoTrailingOrDoubledSlash()
    {
        $this->assertSame('https://nailscosmetics.no', SiblingShopPicker::url('https://nailscosmetics.no', '/'));

        $this->seedSitemap('https://nailscosmetics.no', ['https://nailscosmetics.no/hjelp']);

        $this->assertSame(
            'https://nailscosmetics.no/hjelp',
            SiblingShopPicker::url('https://nailscosmetics.no', '/hjelp')
        );
    }

    /** A static page carries no 1C id, so the sitemap is the only proof it exists */
    public function testAStaticPagePairsThroughTheSitemap()
    {
        $this->seedSitemap('https://nailscosmetics.lt', ['https://nailscosmetics.lt/lt/pagalba']);

        $this->assertSame(
            'https://nailscosmetics.lt/lt/pagalba',
            SiblingShopPicker::url('https://nailscosmetics.lt', '/pagalba')
        );
    }

    /** The front page of this shop asks for the front page of the other */
    public function testTheFrontPageAsksForTheFrontPage()
    {
        $this->assertSame('https://nailscosmetics.lv/lv', SiblingShopPicker::url('https://nailscosmetics.lv', '/'));
        $this->assertSame('https://nailscosmetics.lv/lv', SiblingShopPicker::url('https://nailscosmetics.lv', ''));
    }

    /** Every row has somewhere to go, with no query string and no trailing slash */
    public function testEveryRowHasACleanHref()
    {
        $this->browseAs('https://nailscosmetics.lv/lv/gellakas?page=8');

        foreach (SiblingShopPicker::links() as $arRow) {
            $this->assertNotEmpty($arRow['url']);
            $this->assertStringNotContainsString('?', $arRow['url']);
            $this->assertStringEndsNotWith('/', $arRow['url']);
        }
    }

    /**
     * The facade caches its resolved instance, so a container rebind alone
     * leaves the helper reading the old request. swap() does both.
     */
    protected function browseAs(string $sUrl): void
    {
        Request::swap(HttpRequest::create($sUrl));
    }

    protected function seedOwnMap(): void
    {
        Cache::put(SiblingShopIdMap::OWN_CACHE_KEY, [
            ShopIdMap::BUCKET_PRODUCTS => [self::OWN_PATH => self::EXTERNAL_ID],
            ShopIdMap::BUCKET_CATEGORIES => [],
        ], now()->addMinutes(5));
    }

    protected function seedSiblingMap(string $sDomain, string $sSiblingPath): void
    {
        Cache::put(SiblingShopIdMap::cacheKey($sDomain), [
            ShopIdMap::BUCKET_PRODUCTS => [self::EXTERNAL_ID => $sSiblingPath],
            ShopIdMap::BUCKET_CATEGORIES => [],
        ], now()->addMinutes(5));
    }

    /** @param array<int,string> $arUrlList */
    protected function seedSitemap(string $sDomain, array $arUrlList): void
    {
        Cache::put(
            SiblingShopSitemap::cacheKey($sDomain),
            array_fill_keys($arUrlList, true),
            now()->addMinutes(5)
        );
    }
}
