<?php

declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopItemDoubles.php';
require_once __DIR__ . '/../doubles/ShopLayoutDoubles.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
use Logingrupa\StoreExtender\Classes\Helper\ItemCacheResetter;
use Logingrupa\StoreExtender\Classes\Helper\LlmsTxtBuilder;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use Logingrupa\StoreExtender\Components\OfferSheet;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Toolbox\Classes\Item\ItemStorage;

/**
 * Covers Phase 1 discovery helpers: /llms.txt builder, OfferSheet selected-offer primary color
 * resolution, non-resizing swatchIfWarm, and ActivePriceHelper guest retail reset with 2-arg ItemStorage clear.
 */
class AgentDiscoveryPhase1Test extends StoreExtenderPluginTestCase
{
    use ShopItemDoubles;
    use ShopLayoutStubTables;

    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->createShopLayoutStubTables();
        PriceTypeHelper::forgetInstance();
        ActivePriceHelper::forgetInstance();
    }

    public function tearDown(): void
    {
        ActivePriceHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();
        Cache::flush();

        parent::tearDown();
    }

    public function testLlmsTxtRouteReturnsPlainTextWithCacheControlAndNoRedirect(): void
    {
        $obResponse = $this->get('/llms.txt');

        $obResponse->assertStatus(200);
        $this->assertStringStartsWith('text/plain', (string) $obResponse->headers->get('Content-Type'));
        $sCacheControl = (string) $obResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $sCacheControl);
        $this->assertStringContainsString('max-age=900', $sCacheControl);
        $this->assertStringContainsString('# NAILS Cosmetics', (string) $obResponse->getContent());
    }

    public function testLlmsTxtBuilderEmitsDiscoveryMapAndAcceptsExtensionSections(): void
    {
        Event::listen(LlmsTxtBuilder::EVENT_EXTEND_SECTIONS, static fn (): string => "## Agent API\n- MCP: /mcp");

        $sBody = LlmsTxtBuilder::build();

        $this->assertStringContainsString('# NAILS Cosmetics', $sBody);
        $this->assertStringContainsString('Sitemap:', $sBody);
        $this->assertStringContainsString('SKU-{product_id}-{offer_id}', $sBody);
        $this->assertStringContainsString('## Agent API', $sBody);
        $this->assertStringContainsString('- MCP: /mcp', $sBody);
    }

    public function testOfferSheetResolvesPrimaryColorFamilyOverCollectionGroup(): void
    {
        $obSheet = new OfferSheet();
        $obMethod = new ReflectionMethod(OfferSheet::class, 'resolveSelectedOfferFamily');
        $obMethod->setAccessible(true);

        $arGroupedList = [
            ['iOfferId' => 101, 'sFamily' => 'disco', 'bPrimary' => false],
            ['iOfferId' => 101, 'sFamily' => 'red', 'bPrimary' => true],
            ['iOfferId' => 102, 'sFamily' => 'pink', 'bPrimary' => true],
        ];

        $this->assertSame('red', $obMethod->invoke($obSheet, $arGroupedList, 101, true));
        $this->assertNull($obMethod->invoke($obSheet, $arGroupedList, 101, false));
        $this->assertNull($obMethod->invoke($obSheet, $arGroupedList, 999, true));
    }

    public function testSwatchIfWarmReturnsEmptyStringForNullImage(): void
    {
        $this->assertSame('', OfferImageHelper::swatchIfWarm(null));
    }

    public function testActivePriceHelperResetsGuestToRetailAndClearsCachedOfferItemsOnTierChange(): void
    {
        $iIzplId = (int) DB::table('lovata_shopaholic_price_types')->insertGetId([
            'active' => 1,
            'name' => 'izpl',
            'code' => 'izpl',
        ]);
        PriceTypeHelper::forgetInstance();
        PriceTypeHelper::instance()->switchActive('izpl');
        $this->assertSame($iIzplId, PriceTypeHelper::instance()->getActivePriceTypeID());

        // Seed the offer while izpl tier is active in the same process.
        $obOffer = $this->makeOffer(['id' => 777, 'product_id' => 55, 'name' => 'Shade 777', 'quantity' => 5], 12.9, 'EUR');
        ItemStorage::set(OfferItem::class, 777, $obOffer);

        // Running setActivePriceType() as a guest must reset active tier to null and evict OfferItem 777.
        ActivePriceHelper::instance()->setActivePriceType();

        $this->assertNull(PriceTypeHelper::instance()->getActivePriceTypeID());
        $this->assertNull(ItemStorage::get(OfferItem::class, 777));

        // Explicit 2-arg clearOffers handles empty/invalid entries cleanly without throwing.
        ItemCacheResetter::clearOffers([0, -1, 'bad', 777]);
    }
}
