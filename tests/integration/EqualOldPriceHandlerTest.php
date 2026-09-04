<?php

use Illuminate\Support\Facades\DB;
use October\Rain\Database\Schema\Blueprint;
use Lovata\Shopaholic\Models\Price;
use Logingrupa\StoreExtender\Classes\Event\Price\EqualOldPriceHandler;

/**
 * The 1C feed carries no old price and Shopaholic keeps whatever the column
 * already holds, so after a DB move an old price can end up equal to the
 * price and the theme renders the same number struck through. The handler
 * zeroes an equal old price on save; a real discount passes untouched.
 */
class EqualOldPriceHandlerTest extends StoreExtenderPluginTestCase
{
    /** @var bool the full Shopaholic chain is SQLite-incompatible, so only a price table stub is created */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        \System\Classes\UpdateManager::instance()->migratePlugin('Lovata.Toolbox');

        \Illuminate\Support\Facades\Schema::create('lovata_shopaholic_offers', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('product_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create('lovata_shopaholic_prices', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->decimal('price', 15, 2)->nullable();
            $obTable->decimal('old_price', 15, 2)->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->timestamps();
        });
    }

    public function testEqualOldPriceIsStoredAsZero()
    {
        $obPrice = $this->makePrice('7.90', '7.90');

        $this->assertSame(0.0, (float) DB::table('lovata_shopaholic_prices')->where('id', $obPrice->id)->value('old_price'));
    }

    public function testHigherOldPriceSurvives()
    {
        $obPrice = $this->makePrice('6.32', '7.90');

        $this->assertSame(7.9, (float) DB::table('lovata_shopaholic_prices')->where('id', $obPrice->id)->value('old_price'));
    }

    public function testZeroOldPriceStaysZero()
    {
        $obPrice = $this->makePrice('7.90', '0');

        $this->assertSame(0.0, (float) DB::table('lovata_shopaholic_prices')->where('id', $obPrice->id)->value('old_price'));
    }

    public function testEqualOldPriceIsDroppedOnUpdateToo()
    {
        $obPrice = $this->makePrice('6.32', '7.90');
        $obPrice->price = '7.90';
        $obPrice->save();

        $this->assertSame(0.0, (float) DB::table('lovata_shopaholic_prices')->where('id', $obPrice->id)->value('old_price'));
    }

    protected function makePrice(string $sPrice, string $sOldPrice): Price
    {
        $obPrice = new Price();
        $obPrice->item_id = 1;
        $obPrice->item_type = 'Lovata\Shopaholic\Models\Offer';
        $obPrice->price = $sPrice;
        $obPrice->old_price = $sOldPrice;
        $obPrice->save();

        return $obPrice;
    }
}
