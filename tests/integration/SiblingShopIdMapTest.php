<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\StoreExtender\Classes\Helper\CanonicalPageSuffix;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopIdMap;

/**
 * A cross-domain pair is proven by the 1C external_id, so it survives a shop
 * translating its slugs, and it fails closed everywhere else.
 *
 * The slug guess this replaces broke on 2026-11-15 when the .no slugs became
 * Norwegian: pairing fell to 4 of 261 .lv product pages against 250 products
 * that exist on both shops. The cases below are the ones that made it fall and
 * the ones that must still refuse to print a link.
 */
class SiblingShopIdMapTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const DOMAIN = 'https://nailscosmetics.no';
    const OWN_PATH = '/p/acetona-zimulis';
    const EXTERNAL_ID = '4eade3d6-b909-11e5-8282-b4719e4f6bc1';

    public function setUp(): void
    {
        parent::setUp();
        SiblingShopIdMap::forget(self::DOMAIN);
        SiblingShopIdMap::forgetOwn();
    }

    /**
     * The whole point: the sibling serves the same row under a different slug
     * and the pair is still found.
     */
    public function testADifferentSlugOnTheSiblingStillPairs()
    {
        $this->seedOwnMap();
        $this->seedSiblingMap([ShopIdMap::BUCKET_PRODUCTS => [self::EXTERNAL_ID => '/p/aceton-penn']]);

        $this->assertSame(
            'https://nailscosmetics.no/p/aceton-penn',
            SiblingShopIdMap::siblingUrl(self::DOMAIN, self::OWN_PATH)
        );
    }

    /** The route prefix is the sibling's, not this shop's, and .no has none */
    public function testTheSiblingsOwnRoutePrefixIsUsedAndNeverDoubled()
    {
        $this->seedOwnMap();
        $this->seedSiblingMap(
            [ShopIdMap::BUCKET_PRODUCTS => [self::EXTERNAL_ID => '/p/bazes-topi']],
            'https://nailscosmetics.lt'
        );

        $sUrl = SiblingShopIdMap::siblingUrl('https://nailscosmetics.lt', self::OWN_PATH);

        $this->assertSame('https://nailscosmetics.lt/lt/p/bazes-topi', $sUrl);
        $this->assertStringNotContainsString('//p/', $sUrl, 'a bare prefix must not double the slash');
    }

    /** Fails closed: no sibling entry for this id */
    public function testASiblingThatDoesNotCarryTheRowEmitsNothing()
    {
        $this->seedOwnMap();
        $this->seedSiblingMap([ShopIdMap::BUCKET_PRODUCTS => ['some-other-guid' => '/p/annet']]);

        $this->assertNull(SiblingShopIdMap::siblingUrl(self::DOMAIN, self::OWN_PATH));
    }

    /** Fails closed: this row has no external_id, so it has no cross-shop identity */
    public function testARowWithoutAnExternalIdEmitsNothing()
    {
        $this->seedOwnMap();
        $this->seedSiblingMap([ShopIdMap::BUCKET_PRODUCTS => [self::EXTERNAL_ID => '/p/aceton-penn']]);

        $this->assertNull(SiblingShopIdMap::siblingUrl(self::DOMAIN, '/p/not-in-the-own-map'));
    }

    /** Fails closed: an unwarmed sibling contributes no links, never a stale guess */
    public function testAnUnwarmedSiblingEmitsNothingAndFetchesNothing()
    {
        Http::fake();
        $this->seedOwnMap();

        $this->assertNull(SiblingShopIdMap::siblingUrl(self::DOMAIN, self::OWN_PATH));
        Http::assertNothingSent();
    }

    /** A poisoned cache entry must read as empty inside a render, not throw */
    public function testAPoisonedCacheEntryIsNotFatal()
    {
        $this->seedOwnMap();
        Cache::put(SiblingShopIdMap::cacheKey(self::DOMAIN), 'not-an-array', now()->addMinutes(5));

        $this->assertNull(SiblingShopIdMap::siblingUrl(self::DOMAIN, self::OWN_PATH));
    }

    /** Products and categories are separate buckets, so a path cannot cross over */
    public function testAProductIdIsNotReadFromTheCategoryBucket()
    {
        $this->seedOwnMap();
        $this->seedSiblingMap([ShopIdMap::BUCKET_CATEGORIES => [self::EXTERNAL_ID => '/kategori']]);

        $this->assertNull(SiblingShopIdMap::siblingUrl(self::DOMAIN, self::OWN_PATH));
    }

    /**
     * A warm reads the sibling's published map once and the reader adds nothing
     * to it, which is the no-HTTP-during-render contract.
     */
    public function testWarmFetchesOnceAndTheReaderFetchesNothing()
    {
        Http::fake([
            self::DOMAIN . SiblingShopIdMap::MAP_PATH => Http::response([
                ShopIdMap::BUCKET_PRODUCTS => [self::EXTERNAL_ID => '/p/aceton-penn'],
                ShopIdMap::BUCKET_CATEGORIES => ['cat-guid' => '/manikur-pedikyr'],
            ]),
        ]);

        $this->assertSame(2, SiblingShopIdMap::warm(self::DOMAIN));
        Http::assertSentCount(1);

        $this->seedOwnMap();
        $this->assertSame(
            'https://nailscosmetics.no/p/aceton-penn',
            SiblingShopIdMap::siblingUrl(self::DOMAIN, self::OWN_PATH)
        );
        Http::assertSentCount(1, 'the reader adds nothing to the warm fetch');
    }

    /** A sibling answering an empty map must not replace a good one */
    public function testAnEmptyPublishedMapIsAFailedWarm()
    {
        Http::fake([
            self::DOMAIN . SiblingShopIdMap::MAP_PATH => Http::response([
                ShopIdMap::BUCKET_PRODUCTS => [],
                ShopIdMap::BUCKET_CATEGORIES => [],
            ]),
        ]);

        $this->assertNull(SiblingShopIdMap::warm(self::DOMAIN));
    }

    /**
     * The pagination suffix reaches an href, so only a page this shop would
     * paginate to may get there.
     */
    public function testOnlyAValidPageAboveOneBecomesACanonicalSuffix()
    {
        $this->assertSame('?page=2', $this->suffixFor('2'));
        $this->assertSame('?page=37', $this->suffixFor('37'));

        $this->assertSame('', $this->suffixFor('1'), 'page 1 is the bare URL');
        $this->assertSame('', $this->suffixFor('0'));
        $this->assertSame('', $this->suffixFor('-3'));
        $this->assertSame('', $this->suffixFor('2abc'));
        $this->assertSame('', $this->suffixFor('2.0'));
        $this->assertSame('', $this->suffixFor('1e3'));
        $this->assertSame('', $this->suffixFor('"><script>'));
        $this->assertSame('', $this->suffixFor(''));
    }

    /** A page past the last real one canonicals to the clean URL, not to itself */
    public function testAnEmptyListingDropsTheSuffix()
    {
        request()->merge([CanonicalPageSuffix::QUERY_FIELD => '900']);

        $this->assertSame('?page=900', CanonicalPageSuffix::get(true));
        $this->assertSame('', CanonicalPageSuffix::get(false));
    }

    protected function suffixFor(string $sValue): string
    {
        request()->merge([CanonicalPageSuffix::QUERY_FIELD => $sValue]);

        return CanonicalPageSuffix::get();
    }

    protected function seedOwnMap(): void
    {
        Cache::put(SiblingShopIdMap::OWN_CACHE_KEY, [
            ShopIdMap::BUCKET_PRODUCTS => [self::OWN_PATH => self::EXTERNAL_ID],
            ShopIdMap::BUCKET_CATEGORIES => [],
        ], now()->addMinutes(5));
    }

    protected function seedSiblingMap(array $arMap, string $sDomain = self::DOMAIN): void
    {
        Cache::put(SiblingShopIdMap::cacheKey($sDomain), $arMap + [
            ShopIdMap::BUCKET_PRODUCTS => [],
            ShopIdMap::BUCKET_CATEGORIES => [],
        ], now()->addMinutes(5));
    }
}
