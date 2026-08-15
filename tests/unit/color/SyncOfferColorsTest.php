<?php namespace Logingrupa\StoreExtender\Tests\Unit\Color;

require_once __DIR__ . '/../../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Models\OfferColor;
use System\Models\Parameter;

/**
 * Integration test of the sync command + repository read side: real SQLite DB,
 * fake HTTP. Skips the full plugin migration chain (Shopaholic migrations are
 * SQLite-incompatible, see wishlistextender BaseWishlistExtenderTestCase);
 * only the offer color table is created, straight from its migration class.
 * The core module schema comes from StoreExtenderPluginTestCase, which builds
 * it before the first settings read so the ambient cms manifest cannot turn
 * these tests into skips (see that class's docblock).
 */
class SyncOfferColorsTest extends \StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;
    /** @var array Valid payload fixture matching the upstream contract */
    protected $arValidBody = [
        'version' => 'v42',
        'count'   => 2,
        'offers'  => [
            'uuid-pink'  => ['family' => 'Pink', 'hex' => '#EC407A', 'hue' => 354.2, 'lightness' => 0.64, 'confidence' => 0.85],
            'uuid-red'   => ['family' => 'Red', 'hex' => '#C62828', 'hue' => 2.1, 'lightness' => 0.45],
            'uuid-junk'  => ['family' => '', 'hex' => 'nope', 'hue' => 'x', 'lightness' => null],
        ],
    ];

    public function setUp(): void
    {
        try {
            parent::setUp();
            // migration classes are not composer-autoloaded, October loads them by path
            require_once __DIR__.'/../../../updates/create_table_offer_colors.php';
            require_once __DIR__.'/../../../updates/update_table_offer_colors_add_confidence.php';
            (new \Logingrupa\StoreExtender\Updates\CreateTableOfferColors())->up();
            (new \Logingrupa\StoreExtender\Updates\UpdateTableOfferColorsAddConfidence())->up();
        } catch (\Throwable $obException) {
            $this->markTestSkipped('Test bootstrap failed on this environment: '.$obException->getMessage());
        }
        Cache::flush();
        // Parameter keeps a static cache that outlives the per-test database,
        // so without this the sync state written by one test answers the next
        // one's reads. Harmless in production, where every request and every
        // console run is its own process.
        Parameter::clearInternalCache();
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
        $this->assertSame(0.85, (float) $obPink->confidence, 'the confidence score must import');
        $this->assertNull(
            OfferColor::where('offer_uuid', 'uuid-red')->first()->confidence,
            'an export without a score must store null, not zero'
        );

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

    public function testSyncRecordsExportTimestampAndImportTime()
    {
        $arBody = $this->arValidBody + ['last_updated_at' => '2026-08-05 09:49:08'];
        Http::fake(['*' => Http::response($arBody, 200)]);

        Artisan::call('storeextender:sync-offer-colors');

        $obRepository = new ColorMapRepository();
        $this->assertSame('2026-08-05 09:49:08', $obRepository->getExportUpdatedAt());
        $this->assertNotSame('', $obRepository->getSyncedAt(), 'the import time must be recorded');
    }

    public function testSyncStateSurvivesACacheClear()
    {
        Http::fake(['*' => Http::response($this->arValidBody + ['last_updated_at' => '2026-08-05 09:49:08'], 200)]);
        Artisan::call('storeextender:sync-offer-colors');

        // The deploy runs cache:clear, and this state used to live only in the
        // cache - after which nothing could say what had already been imported
        Cache::flush();

        $obRepository = new ColorMapRepository();
        $this->assertSame('v42', $obRepository->getVersion());
        $this->assertSame('2026-08-05 09:49:08', $obRepository->getExportUpdatedAt());
    }

    public function testLegacyCachedVersionCannotShadowThePersistedOne()
    {
        // Before this release the version stamp lived under this cache key. On
        // upgrade the old entry is still there, holding the version currently
        // live upstream, so a getVersion() that read the cache first would
        // report "already in sync", skip the import and never persist any
        // state - the drift check would sit at "never synced" forever.
        Cache::forever(ColorMapRepository::CACHE_KEY_LEGACY_VERSION, 'v42');

        $this->assertSame(
            '',
            (new ColorMapRepository())->getVersion(),
            'a leftover cache entry must not answer for the persisted version'
        );

        Http::fake(['*' => Http::response($this->arValidBody, 200)]);
        Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(2, OfferColor::count(), 'the import must still run on upgrade');
        $this->assertNotSame('', (new ColorMapRepository())->getSyncedAt());
    }

    public function testUnchangedExportIsNotReimported()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);
        Artisan::call('storeextender:sync-offer-colors');

        // Same version stamp: the second run must leave the table alone rather
        // than rewriting every row on every hourly tick
        OfferColor::where('offer_uuid', 'uuid-pink')->update(['family' => 'SentinelValue']);
        Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(
            'SentinelValue',
            OfferColor::where('offer_uuid', 'uuid-pink')->first()->family,
            'an unchanged export must not trigger a write'
        );
    }

    public function testChangedExportIsReimported()
    {
        // A sequence, not two fake() calls: a second fake() ADDS a stub rather
        // than replacing one, so the original '*' would keep answering and the
        // new version would never arrive. Each successful run makes exactly
        // two requests: the offer export, then the family taxonomy (empty
        // here, so the property sync skips itself).
        $arNextBody = $this->arValidBody;
        $arNextBody['version'] = 'v43';
        Http::fakeSequence()
            ->push($this->arValidBody, 200)
            ->push(['families' => []], 200)
            ->push($arNextBody, 200)
            ->push(['families' => []], 200);

        Artisan::call('storeextender:sync-offer-colors');
        OfferColor::where('offer_uuid', 'uuid-pink')->update(['family' => 'SentinelValue']);
        Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame('Pink', OfferColor::where('offer_uuid', 'uuid-pink')->first()->family);
        $this->assertSame('v43', (new ColorMapRepository())->getVersion());
    }

    public function testForceReimportsAnUnchangedExport()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);
        Artisan::call('storeextender:sync-offer-colors');
        OfferColor::where('offer_uuid', 'uuid-pink')->update(['family' => 'SentinelValue']);

        Artisan::call('storeextender:sync-offer-colors', ['--force' => true]);

        $this->assertSame('Pink', OfferColor::where('offer_uuid', 'uuid-pink')->first()->family);
    }

    public function testEmptiedTableIsRefilledEvenWhenTheVersionMatches()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);
        Artisan::call('storeextender:sync-offer-colors');
        OfferColor::query()->delete();

        Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(2, OfferColor::count(), 'a wiped table must refill even on a matching version');
    }

    public function testStatusReportsInSyncAndWritesNothing()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);
        Artisan::call('storeextender:sync-offer-colors');
        OfferColor::where('offer_uuid', 'uuid-pink')->update(['family' => 'SentinelValue']);

        $iExitCode = Artisan::call('storeextender:sync-offer-colors', ['--status' => true]);

        $this->assertSame(0, $iExitCode);
        $this->assertSame(
            'SentinelValue',
            OfferColor::where('offer_uuid', 'uuid-pink')->first()->family,
            '--status must not import'
        );
    }

    public function testStatusExitsNonZeroWhenTheExportHasMoved()
    {
        $arNextBody = $this->arValidBody;
        $arNextBody['version'] = 'v43';
        // sync run = offer export + family taxonomy; --status run = offer only
        Http::fakeSequence()
            ->push($this->arValidBody, 200)
            ->push(['families' => []], 200)
            ->push($arNextBody, 200);

        Artisan::call('storeextender:sync-offer-colors');

        $this->assertSame(1, Artisan::call('storeextender:sync-offer-colors', ['--status' => true]));
    }

    public function testStatusExitsNonZeroWhenTheApiIsUnreachable()
    {
        Http::fake(['*' => Http::response($this->arValidBody, 200)]);
        Artisan::call('storeextender:sync-offer-colors');

        // An API we cannot reach proves nothing, so it must never read as
        // "in sync" just because the last cached body still matches
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('refused');
        });

        $this->assertSame(1, Artisan::call('storeextender:sync-offer-colors', ['--status' => true]));
    }
}
