<?php

declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Classes\Event\AgentCommerce\ColorLookupBridge;
use Logingrupa\StoreExtender\Classes\Event\AgentCommerce\ShippingBridge;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointRepository;
use Lovata\Shopaholic\Classes\Store\OfferListStore;
use October\Rain\Database\Schema\Blueprint;

/**
 * Covers StoreExtender's zero-coupling AgentCommerce string-event bridges:
 * - ColorLookupBridge CIELAB CIE76 Delta-E ranking, tie-breaking, and color_family[] batching
 * - ShippingBridge disk-only PickupPointRepository::readStored() filtering
 */
class AgentCommerceBridgesTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake(PickupPointRepository::DISK);

        if (! Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(true);
                $obTable->unsignedInteger('product_id')->default(1);
                $obTable->string('name')->default('');
                $obTable->string('external_id')->nullable();
                $obTable->integer('quantity')->default(5);
                $obTable->integer('sort_order')->default(0);
                $obTable->softDeletes();
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('logingrupa_storeextender_offer_colors')) {
            Schema::create('logingrupa_storeextender_offer_colors', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->string('offer_uuid')->unique();
                $obTable->string('family')->default('');
                $obTable->text('families')->nullable();
                $obTable->string('shade_code')->nullable();
                $obTable->string('hex')->nullable();
                $obTable->float('hue')->default(0);
                $obTable->float('lightness')->default(0);
                $obTable->timestamps();
            });
        }
    }

    public function tearDown(): void
    {
        Cache::flush();
        OfferListStore::instance()->active->clear();

        parent::tearDown();
    }

    public function testColorLookupBridgeRanksHexMatchesByCie76DeltaEAndBatchesFamilies(): void
    {
        $iRedId = (int) DB::table('lovata_shopaholic_offers')->insertGetId([
            'active' => 1,
            'product_id' => 10,
            'name' => 'Pure Red',
            'external_id' => 'prod#uuid-red',
        ]);
        $iCoralId = (int) DB::table('lovata_shopaholic_offers')->insertGetId([
            'active' => 1,
            'product_id' => 10,
            'name' => 'Warm Coral',
            'external_id' => 'prod#uuid-coral',
        ]);
        $iBlueId = (int) DB::table('lovata_shopaholic_offers')->insertGetId([
            'active' => 1,
            'product_id' => 10,
            'name' => 'Deep Blue',
            'external_id' => 'prod#uuid-blue',
        ]);

        DB::table('logingrupa_storeextender_offer_colors')->insert([
            [
                'offer_uuid' => 'uuid-red',
                'family' => 'red',
                'families' => json_encode(['red', 'disco']),
                'shade_code' => '001',
                'hex' => '#D00000',
            ],
            [
                'offer_uuid' => 'uuid-coral',
                'family' => 'coral',
                'families' => json_encode(['coral']),
                'shade_code' => '002',
                'hex' => '#E85D4A',
            ],
            [
                'offer_uuid' => 'uuid-blue',
                'family' => 'blue',
                'families' => json_encode(['blue']),
                'shade_code' => '003',
                'hex' => '#0022CC',
            ],
        ]);
        OfferListStore::instance()->active->clear();
        Cache::forget(ColorMapRepository::CACHE_KEY_MAP);

        $obBridge = new ColorLookupBridge();

        // Hex query near #D00000 ranks Pure Red first, Warm Coral second, and excludes Deep Blue.
        $arHexResult = $obBridge->handleLookupFilters([
            'hex' => '#CC0000',
            'max_color_distance' => 45,
        ]);
        $this->assertNotNull($arHexResult);
        $this->assertTrue($arHexResult['handled']);
        $this->assertSame([$iRedId, $iCoralId], $arHexResult['offer_ids']);
        $this->assertLessThan(
            $arHexResult['meta'][$iCoralId]['color_distance'],
            $arHexResult['meta'][$iRedId]['color_distance']
        );

        // Batched color_family[] query returns both red and blue in one pass.
        $arFamilyResult = $obBridge->handleLookupFilters([
            'color_family' => ['red', 'blue'],
        ]);
        $this->assertNotNull($arFamilyResult);
        $this->assertSame([$iRedId, $iBlueId], $arFamilyResult['offer_ids']);

        // Shade code query matches exact shade_code.
        $arShadeResult = $obBridge->handleLookupFilters(['shade_code' => '002']);
        $this->assertNotNull($arShadeResult);
        $this->assertSame([$iCoralId], $arShadeResult['offer_ids']);
    }

    public function testShippingBridgeReadsStoredPickupPointsFromDiskOnly(): void
    {
        Storage::disk(PickupPointRepository::DISK)->put(
            PickupPointRepository::DIRECTORY . '/omniva.json',
            json_encode([
                'carrier' => 'omniva',
                'fetched_at' => 1, // Deliberately expired: readStored() must NEVER trigger HTTP refresh.
                'points' => [
                    ['id' => 'LV1', 'name' => 'Riga Alfa', 'country' => 'LV'],
                    ['id' => 'LT1', 'name' => 'Vilnius Akropolis', 'country' => 'LT'],
                ],
            ])
        );

        $obBridge = new ShippingBridge();
        $arExtended = $obBridge->attachStoredPickupPoints([
            ['id' => 1, 'code' => 'omniva_lv', 'name' => 'Omniva', 'price' => 3.0, 'pickup_points' => []],
        ], ['country' => 'LV']);

        $this->assertCount(1, $arExtended[0]['pickup_points']);
        $this->assertSame('LV1', $arExtended[0]['pickup_points'][0]['id']);
    }
}
