<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * A queue:work daemon runs many jobs in one process, and an offer written by
 * another process fires no model event in it. Every job runs through the sync
 * queue here, which raises the same JobProcessing event a worker does.
 *
 * Stub table on SQLite, raw inserts (ProductOfferMapTest pattern).
 */
class RequestMapResetHandlerTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', function (Blueprint $obTable) {
                $obTable->increments('id');
                $obTable->integer('product_id')->nullable();
                $obTable->boolean('active')->default(true);
                $obTable->integer('sort_order')->nullable();
                $obTable->timestamp('deleted_at')->nullable();
                $obTable->timestamps();
            });
        }

        ProductItem::clearActiveOfferMap();
        CategoryItem::clearActiveChildrenMap();
        MapProbeJob::$arSeen = null;
    }

    public function tearDown(): void
    {
        ProductItem::clearActiveOfferMap();
        CategoryItem::clearActiveChildrenMap();

        parent::tearDown();
    }

    public function testAJobReadsTheOffersAnotherProcessWroteAfterTheWorkerPrimedTheMap()
    {
        $iFirst = $this->insertOffer(7, 1);
        $this->assertSame([$iFirst], MapProbeJob::readOfferIdList(7), 'an earlier job primed the map');

        $iSecond = $this->insertOffer(7, 2);
        Queue::connection('sync')->push(new MapProbeJob());

        $this->assertSame([$iFirst, $iSecond], MapProbeJob::$arSeen['offer_id_list']);
    }

    public function testAJobStartsWithTheCategoryChildrenMapUnprimed()
    {
        (new ReflectionProperty(CategoryItem::class, 'arActiveChildrenMap'))->setValue(null, [1 => [2]]);

        Queue::connection('sync')->push(new MapProbeJob());

        $this->assertNull(MapProbeJob::$arSeen['children_map']);
    }

    /**
     * @param int $iProductId
     * @param int $iSortOrder
     * @return int
     */
    protected function insertOffer($iProductId, $iSortOrder)
    {
        return DB::table('lovata_shopaholic_offers')->insertGetId([
            'product_id' => $iProductId,
            'active'     => true,
            'sort_order' => $iSortOrder,
        ]);
    }
}

/**
 * Records what a job body sees of both maps.
 */
class MapProbeJob implements ShouldQueue
{
    /** @var array|null */
    public static $arSeen = null;

    public function handle(): void
    {
        self::$arSeen = [
            'children_map'  => (new ReflectionProperty(CategoryItem::class, 'arActiveChildrenMap'))->getValue(),
            'offer_id_list' => self::readOfferIdList(7),
        ];
    }

    /**
     * @param int $iProductId
     * @return array
     */
    public static function readOfferIdList($iProductId)
    {
        $obMethod = new ReflectionMethod(ProductItem::class, 'getActiveOfferRowList');

        return array_map(function ($obRow) {
            return (int) $obRow->id;
        }, $obMethod->invoke(null, $iProductId));
    }
}
