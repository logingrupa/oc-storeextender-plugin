<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Cache;
use Db;
use Schema;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * What the real hreflang partial emits, rendered through Twig.
 *
 * The partial lives in the theme and the two functions it calls live here, so
 * the contract spans both repos and only a render can hold it: the source scan
 * in themes/logingrupa-naisstore/tests/unit/hreflang-id-resolution.test.js
 * pins the call order and cannot see what comes out.
 *
 * The case that needs it is the shade URL. The partial prefers
 * sPageCanonicalPath, and a product page publishes its canonical with the slug
 * alone, so on /p/slug/:offer the classifier is handed /p/slug and the page
 * inherits its product's sibling alternates. That is hreflang agreeing with
 * the canonical and it is deliberate; it used to be an accident nobody had
 * written down.
 *
 * Only standard Twig is used by the partial, so a bare environment with the
 * two functions registered renders it as October would.
 */
class HreflangPartialRenderTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const PRODUCT_ID = 'a1b2c3d4-0000-0000-0000-000000000001';
    const SIBLING_LV = 'https://nailscosmetics.lv';

    /** The sibling's own slug, deliberately unlike ours, so a constructed URL cannot pass */
    const SIBLING_PUBLISHED_URL = 'https://nailscosmetics.lv/lv/p/stovborste-norsk';

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
            'id' => 1, 'name' => 'Foo', 'slug' => 'foo', 'external_id' => self::PRODUCT_ID,
            'active' => 1, 'deleted_at' => null, 'updated_at' => '2026-11-06 10:00:00',
        ]);

        $this->forgetResolvedPaths();
    }

    public function testAShadeUrlInheritsItsProductsSiblingAlternate()
    {
        Cache::forever(SiblingShopMap::cacheKey(self::SIBLING_LV), [self::PRODUCT_ID => self::SIBLING_PUBLISHED_URL]);

        $sOutput = $this->renderPartial('/lv/p/foo/123', '/p/foo');

        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="lv" href="' . self::SIBLING_PUBLISHED_URL . '" />',
            $sOutput,
            'the shade page carries its product alternate, at the URL the sibling published'
        );
        $this->assertStringNotContainsString('nailscosmetics.lv/lv/p/foo"', $sOutput, 'never a constructed URL');
        $this->assertStringNotContainsString('nailscosmetics.lv/lv/p/foo/123', $sOutput, 'and never the shade path');
    }

    public function testAProductPageWhoseSiblingHasNoEntryEmitsNoCrossDomainLink()
    {
        Cache::forever(SiblingShopMap::cacheKey(self::SIBLING_LV), ['another-id' => self::SIBLING_PUBLISHED_URL]);
        Cache::forever(SiblingShopSitemap::cacheKey(self::SIBLING_LV), [self::SIBLING_LV . '/lv/p/foo' => true]);

        $sOutput = $this->renderPartial('/lv/p/foo', '/p/foo');

        $this->assertStringNotContainsString('nailscosmetics.lv', $sOutput, 'the warm url set may not be used as a fallback for an entity');
    }

    public function testAPageThatIsNoCatalogueEntityStillUsesThePathCheck()
    {
        Cache::forever(SiblingShopSitemap::cacheKey(self::SIBLING_LV), [self::SIBLING_LV . '/lv/checkout/cart' => true]);

        $sOutput = $this->renderPartial('/lv/checkout/cart', null);

        $this->assertStringContainsString(
            '<link rel="alternate" hreflang="lv" href="https://nailscosmetics.lv/lv/checkout/cart" />',
            $sOutput,
            'home, cart and the static pages keep the unchanged path check'
        );
    }

    protected function renderPartial(string $sRequestPath, ?string $sCanonicalPath): string
    {
        $sSource = file_get_contents(base_path('themes/logingrupa-naisstore/partials/shared/seo/hreflang.htm'));
        $this->assertNotFalse($sSource, 'the theme partial is readable');

        $obTwig = new Environment(new ArrayLoader(['hreflang' => $sSource]), ['strict_variables' => false]);
        $obTwig->addFunction(new TwigFunction('shop_cross_alternates', [SiblingShopMap::class, 'crossAlternates']));
        $obTwig->addFunction(new TwigFunction('shop_has_url', [SiblingShopSitemap::class, 'hasUrl']));

        $sBaseUrl = ShopIdMap::primaryBaseUrl();

        return $obTwig->render('hreflang', [
            'sPageCanonicalPath' => $sCanonicalPath,
            'sitePicker' => ['sites' => [
                ['locale' => 'lv', 'base_url' => $sBaseUrl, 'url' => $sBaseUrl . $sRequestPath],
            ]],
            'this' => [
                'request' => new class ($sRequestPath) {
                    /** @var string */
                    protected $sPath;

                    public function __construct(string $sPath)
                    {
                        $this->sPath = $sPath;
                    }

                    public function getPathInfo(): string
                    {
                        return $this->sPath;
                    }
                },
                'site' => [
                    'base_url' => $sBaseUrl,
                    'is_prefixed' => true,
                    'route_prefix' => '/lv',
                ],
            ],
        ]);
    }

    /**
     * resolvePath() memoises per render; the static outlives one test.
     */
    protected function forgetResolvedPaths(): void
    {
        $obProperty = (new ReflectionClass(ShopIdMap::class))->getProperty('arResolvedPathList');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, []);
    }
}
