<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * ProductItem reads offer_id_list from one active offer map per request
 * (patches/lovata-shopaholic-productitem-active-offer-map.patch in the root repo)
 * instead of hydrating every offer with its prices, files and translations.
 * ShopaholicExtensionRegistrar::extendProductOfferMapReset() drops that map on any
 * Offer save or delete.
 *
 * Stub tables on SQLite, raw inserts: model create() would fire every booted
 * plugin's model chain (SearchOfferHelperTest pattern).
 */
class ProductOfferMapTest extends StoreExtenderPluginTestCase
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
                $obTable->string('name')->nullable();
                $obTable->integer('sort_order')->nullable();
                $obTable->timestamp('deleted_at')->nullable();
                $obTable->timestamps();
            });
        }

        // RainLab Translate cleans both tables up on every model.afterDelete
        foreach (['rainlab_translate_attributes', 'rainlab_translate_indexes'] as $sTableName) {
            if (!Schema::hasTable($sTableName)) {
                Schema::create($sTableName, function (Blueprint $obTable) {
                    $obTable->increments('id');
                    $obTable->string('model_id')->nullable();
                    $obTable->string('model_type')->nullable();
                });
            }
        }

        ProductItem::clearActiveOfferMap();
    }

    public function tearDown(): void
    {
        ProductItem::clearActiveOfferMap();

        parent::tearDown();
    }

    public function testMapHoldsActiveLiveOffersOnlyInSortOrder()
    {
        $iLast = $this->insertOffer(7, 3);
        $iFirst = $this->insertOffer(7, 1);
        $this->insertOffer(7, 2, false);
        $this->insertOffer(7, 0, true, '2026-01-01 00:00:00');
        $iOtherProduct = $this->insertOffer(8, 1);

        $this->assertSame([$iFirst, $iLast], $this->readOfferIdList(7));
        $this->assertSame([$iOtherProduct], $this->readOfferIdList(8));
        $this->assertSame([], $this->readOfferIdList(9), 'a product without active offers has none');
    }

    public function testOfferSaveAndDeleteDropTheMap()
    {
        $iFirst = $this->insertOffer(7, 1);
        $this->assertSame([$iFirst], $this->readOfferIdList(7));

        $iSecond = $this->insertOffer(7, 2);
        $this->assertSame([$iFirst], $this->readOfferIdList(7), 'the map is primed once per request');

        (new Offer())->fireEvent('model.afterSave');
        $this->assertSame([$iFirst, $iSecond], $this->readOfferIdList(7), 'an offer save must drop the map');

        DB::table('lovata_shopaholic_offers')->where('id', $iFirst)->update(['active' => false]);
        (new Offer())->fireEvent('model.afterDelete');
        $this->assertSame([$iSecond], $this->readOfferIdList(7), 'an offer delete must drop the map');
    }

    /**
     * @param int         $iProductId
     * @param int         $iSortOrder
     * @param bool        $bActive
     * @param string|null $sDeletedAt
     * @return int
     */
    protected function insertOffer($iProductId, $iSortOrder, $bActive = true, $sDeletedAt = null)
    {
        return DB::table('lovata_shopaholic_offers')->insertGetId([
            'product_id' => $iProductId,
            'active'     => $bActive,
            'sort_order' => $iSortOrder,
            'deleted_at' => $sDeletedAt,
        ]);
    }

    /**
     * @param int $iProductId
     * @return array
     */
    protected function readOfferIdList($iProductId)
    {
        $obMethod = new ReflectionMethod(ProductItem::class, 'getActiveOfferRowList');

        return array_map(function ($obRow) {
            return (int) $obRow->id;
        }, $obMethod->invoke(null, $iProductId));
    }
}
