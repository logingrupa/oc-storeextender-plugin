<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopItemDoubles.php';

use Logingrupa\StoreExtender\Classes\Helper\ProductOfferPrice;
use Logingrupa\StoreExtender\Classes\Helper\ProductStructuredData;

/**
 * The price a shopper can pay for an offer, as crawlers read it.
 *
 * Two places print it: the Offer of the Product JSON-LD and the
 * product:price metas of the page head. Both read ProductOfferPrice, and the
 * last case here holds the JSON-LD to the helper, so the two figures cannot
 * drift apart. Items come from ShopItemDoubles, with no row behind them.
 */
class ProductOfferPriceTest extends StoreExtenderPluginTestCase
{
    use ShopItemDoubles;

    /** @var bool core module schema only, the items need no table */
    protected $autoMigrate = false;

    public function testAPricedOfferAnswersItsAmountAndCurrency()
    {
        $obOffer = $this->makeOffer(['id' => 6835, 'product_id' => 160, 'quantity' => 4], 8.9, 'EUR');

        $this->assertSame(['amount' => '8.90', 'currency' => 'EUR'], ProductOfferPrice::resolve($obOffer));
    }

    public function testNoOfferAnEmptyOfferAndAZeroPriceAnswerNull()
    {
        $obEmptyOffer = $this->makeOffer([], 8.9, 'EUR');
        $obFreeOffer = $this->makeOffer(['id' => 2, 'product_id' => 1, 'quantity' => 1], 0.0, 'EUR');

        $this->assertNull(ProductOfferPrice::resolve(null));
        $this->assertNull(ProductOfferPrice::resolve($obEmptyOffer));
        $this->assertNull(ProductOfferPrice::resolve($obFreeOffer));
    }

    public function testAPricedOfferWithoutACurrencyCodeThrows()
    {
        $obOffer = $this->makeOffer(['id' => 7, 'product_id' => 1, 'quantity' => 1], 8.9, ' ');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('offer 7 has no currency code');

        ProductOfferPrice::resolve($obOffer);
    }

    public function testAnythingButAnOfferItemThrowsNamingTheType()
    {
        try {
            ProductOfferPrice::resolve($this->makeProduct(['id' => 1, 'name' => 'P']));
            $this->fail('a ProductItem accepted as the offer');
        } catch (InvalidArgumentException $obException) {
            $this->assertStringContainsString('ProductItem', $obException->getMessage());
        }

        try {
            ProductOfferPrice::resolve('6835');
            $this->fail('a string accepted as the offer');
        } catch (InvalidArgumentException $obException) {
            $this->assertStringContainsString('string given', $obException->getMessage());
        }
    }

    public function testTheJsonLdOfferPrintsTheFiguresOfTheHelper()
    {
        $obProduct = $this->makeProduct(['id' => 160, 'name' => 'Brilliant Bond']);
        $obOffer = $this->makeOffer(['id' => 6835, 'product_id' => 160, 'quantity' => 4], 8.9, 'EUR');

        $arOfferPrice = ProductOfferPrice::resolve($obOffer);
        $arData = ProductStructuredData::build($obProduct, $obOffer, false, [], 'https://nailscosmetics.lv/lv/p/brilliant-bond', 'NAI_S cosmetics');

        $this->assertSame($arOfferPrice['amount'], $arData['offers']['price']);
        $this->assertSame($arOfferPrice['currency'], $arData['offers']['priceCurrency']);
    }
}
