<?php namespace Logingrupa\StoreExtender\Tests\Unit\Color;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Models\OfferColor;

/**
 * Integration test of the sync command + repository read side: real SQLite DB,
 * fake HTTP. Skips the full plugin migration chain (Shopaholic migrations are
 * SQLite-incompatible, see wishlistextender BaseWishlistExtenderTestCase);
 * only the offer color table is created, straight from its migration class.
 */
class SyncOfferColorsTest extends \PluginTestCase
{
    protected $autoMigrate = false;
    /** @var array Valid payload fixture matching the upstream contract */
    protected $arValidBody = [
        'version' => 'v42',
        'count'   => 2,
        'offers'  => [
            'uuid-pink'  => ['family' => 'Pink', 'hex' => '#EC407A', 'hue' => 354.2, 'lightness' => 0.64],
            'uuid-red'   => ['family' => 'Red', 'hex' => '#C62828', 'hue' => 2.1, 'lightness' => 0.45],
            'uuid-junk'  => ['family' => '', 'hex' => 'nope', 'hue' => 'x', 'lightness' => null],
        ],
    ];

    public function setUp(): void
    {
        try {
            parent::setUp();
            $this->migrateModules();
            // migration classes are not composer-autoloaded, October loads them by path
            require_once __DIR__.'/../../../updates/create_table_offer_colors.php';
            (new \Logingrupa\StoreExtender\Updates\CreateTableOfferColors())->up();
        } catch (\Throwable $obException) {
            $this->markTestSkipped('Test bootstrap failed on this environment: '.$obException->getMessage());
        }
        Cache::flush();
    }

    public function testSyncImportsValidEntriesAndSkipsInvalid()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);

        $iExitCode = Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(0, $iExitCode);
        $this->assertSame(2, OfferColor::count());

        $obPink = OfferColor::where('offer_uuid', 'uuid-pink')->first();
        $this->assertNotNull($obPink);
        $this->assertSame('Pink', $obPink->family);
        $this->assertSame('#EC407A', $obPink->hex);

        $obRepository = new ColorMapRepository();
        $this->assertSame('v42', $obRepository->getVersion());
        $arColorMap = $obRepository->getColorMap();
        $this->assertArrayHasKey('uuid-red', $arColorMap);
        $this->assertSame('Red', $arColorMap['uuid-red']['family']);
    }

    public function testSyncRemovesRowsMissingFromPayload()
    {
        OfferColor::create(['offer_uuid' => 'uuid-stale', 'family' => 'Green', 'hex' => null, 'hue' => 120, 'lightness' => 0.5]);
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);

        Artisan::call('storeextender:sync-offer-colors');

        $this->assertNull(OfferColor::where('offer_uuid', 'uuid-stale')->first());
        $this->assertSame(2, OfferColor::count());
    }

    public function testEmptyPayloadLeavesTableUntouched()
    {
        OfferColor::create(['offer_uuid' => 'uuid-keep', 'family' => 'Blue', 'hex' => '#1E88E5', 'hue' => 210, 'lightness' => 0.5]);
        Http::fake(['*' => Http::response(['version' => 'x', 'offers' => []], 200)]);

        $iExitCode = Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(1, $iExitCode);
        $this->assertSame(1, OfferColor::count());
        $this->assertNotNull(OfferColor::where('offer_uuid', 'uuid-keep')->first());
    }

    public function testApiFailureLeavesTableUntouched()
    {
        OfferColor::create(['offer_uuid' => 'uuid-keep', 'family' => 'Blue', 'hex' => '#1E88E5', 'hue' => 210, 'lightness' => 0.5]);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('refused');
        });

        $iExitCode = Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(1, $iExitCode);
        $this->assertSame(1, OfferColor::count());
    }

    public function testRepositoryVersionEmptyBeforeFirstSync()
    {
        $this->assertSame('', (new ColorMapRepository())->getVersion());
        $this->assertSame([], (new ColorMapRepository())->getColorMap());
    }
}
