<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\Shopaholic\Models\Settings;
use Logingrupa\StoreExtender\Classes\Helper\SearchOfferHelper;

/**
 * SearchOfferHelper against the real Lovata search plumbing: the Shopaholic
 * collections, the SearchShopaholic LIKE scan on the configured fields and
 * the offers-of-matching-products union, on SQLite with stub catalog tables
 * (FamilyPropertySyncTest pattern - the full Shopaholic migration chain is
 * SQLite-incompatible).
 *
 * The collection search() dynamic methods normally arrive when
 * Lovata.SearchShopaholic and Logingrupa.SearchOffersShopaholic boot;
 * booting either plugin here would drag in the whole Shopaholic chain, so
 * their two handlers are subscribed directly in every setUp (the test app
 * refresh flushes extend() registrations between tests).
 */
class SearchOfferHelperTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var int */
    protected $iGelProductId;

    /** @var int */
    protected $iTopCoatProductId;

    /** @var int */
    protected $iPlainShadeOfferId;

    /** @var int */
    protected $iGelShadeOfferId;

    /** @var int */
    protected $iGelTopOfferId;

    /** @var int */
    protected $iUnrelatedOfferId;

    public function setUp(): void
    {
        parent::setUp();

        (new \Lovata\SearchShopaholic\Classes\Event\ProductModelHandler())->subscribe();
        (new \Logingrupa\SearchOffersShopaholic\Classes\Event\OfferModelHandler())->subscribe();

        Settings::clearInternalCache();

        $this->createStubTable('lovata_shopaholic_products', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->text('preview_text')->nullable();
            $obTable->text('description')->nullable();
            $obTable->integer('category_id')->nullable();
            $obTable->integer('brand_id')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_shopaholic_product_site_relation', function (Blueprint $obTable) {
            $obTable->integer('product_id')->nullable();
            $obTable->integer('site_id')->nullable();
        });

        $this->createStubTable('lovata_shopaholic_offers', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('product_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('external_id')->nullable();
            // the Offer model's Sortable global scope orders every query by it
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        // raw inserts: model create() would fire every booted plugin's model
        // chain needing dozens of stub tables; the helper only reads
        $this->iGelProductId = DB::table('lovata_shopaholic_products')->insertGetId([
            'name'   => 'Gel polish',
            'slug'   => 'gel-polish',
            'active' => true,
        ]);
        $this->iTopCoatProductId = DB::table('lovata_shopaholic_products')->insertGetId([
            'name'   => 'Top coat',
            'slug'   => 'top-coat',
            'active' => true,
        ]);

        // offer of the matching product, no direct match of its own
        $this->iPlainShadeOfferId = $this->insertOffer($this->iGelProductId, 'Shade 01');
        // matches directly AND belongs to the matching product - the dedup case
        $this->iGelShadeOfferId = $this->insertOffer($this->iGelProductId, 'Gel shade');
        // direct offer match on a product the query does not match
        $this->iGelTopOfferId = $this->insertOffer($this->iTopCoatProductId, 'Gel top');
        // matches nothing on either axis
        $this->iUnrelatedOfferId = $this->insertOffer($this->iTopCoatProductId, 'Shade 09');
    }

    /**
     * @param string   $sTableName
     * @param \Closure $fnDefineTable
     * @return void
     */
    protected function createStubTable($sTableName, $fnDefineTable)
    {
        if (Schema::hasTable($sTableName)) {
            return;
        }
        Schema::create($sTableName, $fnDefineTable);
    }

    /**
     * @param int    $iProductId
     * @param string $sName
     * @return int
     */
    protected function insertOffer($iProductId, $sName)
    {
        return DB::table('lovata_shopaholic_offers')->insertGetId([
            'product_id' => $iProductId,
            'name'       => $sName,
            'active'     => true,
        ]);
    }

    /**
     * Configure the LIKE-scan field lists the collections' search() reads.
     * @return void
     */
    protected function configureSearchFields(): void
    {
        Settings::set('offer_search_by', [['field' => 'name']]);
        Settings::set('product_search_by', [['field' => 'name']]);
    }

    public function testEmptyOrInvalidQueryAnswersEmptyList()
    {
        $this->configureSearchFields();

        $this->assertSame([], SearchOfferHelper::searchOfferIds(''), 'empty query must never mean everything');
        $this->assertSame([], SearchOfferHelper::searchOfferIds('   '), 'whitespace query must never mean everything');
        $this->assertSame([], SearchOfferHelper::searchOfferIds(null), 'null query must never mean everything');
        $this->assertSame([], SearchOfferHelper::searchOfferIds(123), 'non-string query must never mean everything');
    }

    public function testUnconfiguredSearchFieldsAnswerEmptyListNeverEverything()
    {
        // no offer_search_by / product_search_by settings at all
        $this->assertSame([], SearchOfferHelper::searchOfferIds('gel'));
    }

    public function testUnmatchedQueryAnswersEmptyList()
    {
        $this->configureSearchFields();

        $this->assertSame([], SearchOfferHelper::searchOfferIds('zzz'));
    }

    public function testUnionOfOfferAndProductMatchesDeduplicatesAndReturnsInts()
    {
        $this->configureSearchFields();

        $arOfferIdList = SearchOfferHelper::searchOfferIds('gel');

        $this->assertCount(3, $arOfferIdList, 'the double-matched offer must appear exactly once');
        $this->assertEqualsCanonicalizing(
            [$this->iPlainShadeOfferId, $this->iGelShadeOfferId, $this->iGelTopOfferId],
            $arOfferIdList,
            'direct offer matches union with the offers of matching products'
        );
        $this->assertNotContains($this->iUnrelatedOfferId, $arOfferIdList);
        $this->assertContainsOnlyInt($arOfferIdList);
    }
}
