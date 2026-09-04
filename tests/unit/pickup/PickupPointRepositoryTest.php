<?php namespace Logingrupa\StoreExtender\Tests\Unit\Pickup;

require_once __DIR__ . '/../../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointRepository;

/**
 * The stored file is the durable copy: a fresh feed replaces it, a dead feed
 * leaves it, an unknown country never reaches the network. Http::fake and a
 * faked local disk on the hermetic plugin test case (core schema only, no
 * Lovata migrations).
 */
class PickupPointRepositoryTest extends \StoreExtenderPluginTestCase
{
    /** No plugin migrations: the repository touches only the Http and Storage facades. */
    protected $autoMigrate = false;

    /** @var array Two DPD rows, one per country */
    protected $arFeedRows = [
        ['parcelShopId' => 'LV90364', 'companyName' => 'Paku Skapis TC Aplis', 'street' => 'Eksporta iela 6', 'houseNo' => '', 'city' => 'TUKUMS', 'zipCode' => '3104', 'countryCode' => 'LV'],
        ['parcelShopId' => 'LT90028', 'companyName' => 'PC Saulės miestas', 'street' => 'Tilžės g. 109', 'houseNo' => '', 'city' => 'ŠIAULIAI', 'zipCode' => '77159', 'countryCode' => 'LT'],
    ];

    public function setUp(): void
    {
        parent::setUp();
        Storage::fake(PickupPointRepository::DISK);
    }

    public function testFirstReadFetchesStoresAndFiltersByCountry()
    {
        Http::fake(['*' => Http::response($this->arFeedRows, 200)]);

        $arPointList = (new PickupPointRepository())->get('dpd', 'lv');

        $this->assertCount(1, $arPointList);
        $this->assertSame('LV90364', $arPointList[0]['id']);
        $this->assertSame('LV-3104', $arPointList[0]['zip']);
        Storage::disk(PickupPointRepository::DISK)->assertExists('pickup-points/dpd.json');
        Http::assertSentCount(1);
    }

    public function testFreshStoredFileIsServedWithoutTouchingTheFeed()
    {
        $this->storeFile(time() - 60);
        Http::fake();

        $arPointList = (new PickupPointRepository())->get('dpd', 'LV');

        $this->assertSame('stored', $arPointList[0]['id']);
        Http::assertNothingSent();
    }

    public function testExpiredFileIsKeptWhenTheFeedIsDown()
    {
        $this->storeFile(time() - PickupPointRepository::MAX_AGE_SECONDS - 10);
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $arPointList = (new PickupPointRepository())->get('dpd', 'LV');

        $this->assertSame('stored', $arPointList[0]['id']);
        $this->assertStringContainsString('"id":"stored"', Storage::disk(PickupPointRepository::DISK)->get('pickup-points/dpd.json'));
    }

    public function testExpiredFileIsReplacedWhenTheFeedAnswers()
    {
        $this->storeFile(time() - PickupPointRepository::MAX_AGE_SECONDS - 10);
        Http::fake(['*' => Http::response($this->arFeedRows, 200)]);

        $arPointList = (new PickupPointRepository())->get('dpd', 'LV');

        $this->assertSame('LV90364', $arPointList[0]['id']);
    }

    public function testEmptyOrMalformedFeedNeverOverwritesTheFile()
    {
        $this->storeFile(time() - PickupPointRepository::MAX_AGE_SECONDS - 10);
        Http::fake(['*' => Http::response('[]', 200)]);

        $this->assertNull((new PickupPointRepository())->refresh('dpd'));
        $this->assertSame('stored', (new PickupPointRepository())->readStored('dpd')['points'][0]['id']);
    }

    public function testCountryMustBeAlphaTwo()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PickupPointRepository())->get('dpd', 'Latvia');
    }

    /**
     * @param int $iFetchedAt
     */
    protected function storeFile(int $iFetchedAt): void
    {
        Storage::disk(PickupPointRepository::DISK)->put('pickup-points/dpd.json', json_encode([
            'carrier'    => 'dpd',
            'fetched_at' => $iFetchedAt,
            'points'     => [['id' => 'stored', 'name' => 'Stored', 'address' => 'A 1', 'city' => 'Rīga', 'zip' => 'LV-1005', 'country' => 'LV']],
        ]));
    }
}
