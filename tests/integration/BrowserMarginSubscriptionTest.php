<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics\BrowserMarginValueHandler;
use Logingrupa\CustomXMLImportPricing\Classes\Helper\PriceFactorResolver;

/**
 * Booting the plugin must register the GA4 browser value listener on the
 * application dispatcher, so the hook the GA4 plugin dispatches is answered
 * with the offer-line margin (gross without VAT, minus the izpl cost).
 */
class BrowserMarginSubscriptionTest extends StoreExtenderPluginTestCase
{
    /** @var bool the full Shopaholic chain is SQLite-incompatible, so only the tables the margin reads are created */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        \System\Classes\UpdateManager::instance()->migratePlugin('Lovata.Toolbox');

        Schema::create('lovata_shopaholic_offers', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('product_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_shopaholic_prices', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_shopaholic_taxes', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->boolean('is_global')->default(false);
            $obTable->decimal('percent', 8, 2)->nullable();
        });
    }

    /**
     * Insert an offer with an izpl cost price row.
     * @param float $fCost
     * @return int offer id
     */
    protected function makeOfferWithCost(float $fCost): int
    {
        $iOfferId = DB::table('lovata_shopaholic_offers')->insertGetId(['name' => 'Offer', 'active' => 1]);
        DB::table('lovata_shopaholic_prices')->insert([
            'item_id'       => $iOfferId,
            'item_type'     => \Lovata\Shopaholic\Models\Offer::class,
            'price'         => $fCost,
            'price_type_id' => PriceFactorResolver::IZPL_PRICE_TYPE_ID,
        ]);

        return $iOfferId;
    }

    public function testBootedPluginAnswersTheBrowserValueHookWithTheMargin()
    {
        $iOfferId = $this->makeOfferWithCost(4.0);

        $mResult = Event::dispatch(
            BrowserMarginValueHandler::HOOK_BEFORE_SEND,
            ['add_to_cart', [['offer_id' => $iOfferId, 'price' => 10.0, 'quantity' => 2]], 20.0, 'EUR'],
            true
        );

        // no tax configured: 10.00 net - 4.00 cost = 6.00, times 2
        $this->assertSame(12.0, $mResult);
    }

    public function testBootedPluginLeavesTheDefaultWhenNoCostIsKnown()
    {
        $iOfferId = DB::table('lovata_shopaholic_offers')->insertGetId(['name' => 'Offer', 'active' => 1]);

        $mResult = Event::dispatch(
            BrowserMarginValueHandler::HOOK_BEFORE_SEND,
            ['add_to_cart', [['offer_id' => $iOfferId, 'price' => 10.0, 'quantity' => 1]], 10.0, 'EUR'],
            true
        );

        $this->assertNull($mResult);
    }

    public function testBrowserValueListenerIsRegisteredOnBoot()
    {
        $this->assertTrue(Event::hasListeners(BrowserMarginValueHandler::HOOK_BEFORE_SEND));
    }
}
