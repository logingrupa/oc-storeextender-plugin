<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopItemDoubles.php';

use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\BreadcrumbStructuredData;
use Logingrupa\StoreExtender\Classes\Helper\JsonLdScript;
use Logingrupa\StoreExtender\Classes\Helper\ProductOfferPrice;
use Logingrupa\StoreExtender\Classes\Helper\ProductStructuredData;

/**
 * The three Twig functions the product page head prints its SEO side blocks
 * with: breadcrumb_json_ld, product_json_ld and product_offer_price.
 *
 * Their builders reject a content gap (a category the router gives no URL, an
 * empty theme company name, a nameless product, a price with no currency). At
 * the Twig boundary that gap leaves its one block out and is logged: a JSON-LD
 * function prints an empty object, the price function answers null, and the
 * rest of the page renders. The builders themselves keep throwing; their own
 * tests hold that.
 */
class StructuredDataTwigBoundaryTest extends StoreExtenderPluginTestCase
{
    use ShopItemDoubles;

    /** @var bool core module schema only, the items need no table */
    protected $autoMigrate = false;

    const PAGE_URL = 'https://nailscosmetics.lv/lv/p/brilliant-bond';
    const SELLER = 'NAI_S cosmetics';
    const HOME_NAME = 'Sakums';
    const HOME_URL = 'https://nailscosmetics.lv/lv';

    /**
     * One Log::error naming the gap and carrying the exception.
     * @param string $sMessagePart
     * @param string $sExceptionClass
     * @return void
     */
    private function expectGapLogged(string $sMessagePart, string $sExceptionClass): void
    {
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($sMessage, $arContext) use ($sMessagePart, $sExceptionClass) {
                return str_contains($sMessage, $sMessagePart) && $arContext['exception'] instanceof $sExceptionClass;
            });
    }

    public function testACrumbTheRouterGaveNoUrlPrintsAnEmptyBreadcrumbBlock()
    {
        $arCrumbList = [
            ['title' => 'Gel polish', 'url' => null],
            ['title' => 'Gellaka 12ml', 'url' => self::PAGE_URL],
        ];
        $this->expectGapLogged('crumb 0 url is not a string', InvalidArgumentException::class);

        $this->assertSame(JsonLdScript::EMPTY_BLOCK, BreadcrumbStructuredData::render($arCrumbList, self::HOME_NAME, self::HOME_URL));
    }

    public function testACrumbNameThatIsNotUtf8PrintsAnEmptyBreadcrumbBlock()
    {
        $arCrumbList = [['title' => 'Gel ' . chr(0xB1), 'url' => self::PAGE_URL]];
        $this->expectGapLogged('BreadcrumbList JSON-LD left out', JsonException::class);

        $this->assertSame(JsonLdScript::EMPTY_BLOCK, BreadcrumbStructuredData::render($arCrumbList, self::HOME_NAME, self::HOME_URL));
    }

    public function testAWellFormedBreadcrumbBlockLogsNothing()
    {
        Log::shouldReceive('error')->never();

        $sJson = BreadcrumbStructuredData::render([['title' => 'Gellaka 12ml', 'url' => self::PAGE_URL]], self::HOME_NAME, self::HOME_URL);

        $this->assertSame('BreadcrumbList', json_decode($sJson, true)['@type']);
    }

    public function testAnEmptyThemeCompanyNamePrintsAnEmptyProductBlock()
    {
        $obProduct = $this->makeProduct(['id' => 160, 'name' => 'Brilliant Bond']);
        $this->expectGapLogged('seller name is empty', InvalidArgumentException::class);

        $this->assertSame(JsonLdScript::EMPTY_BLOCK, ProductStructuredData::render($obProduct, null, false, [], self::PAGE_URL, ''));
    }

    public function testAThemeWithNoCompanyNamePrintsAnEmptyProductBlock()
    {
        $obProduct = $this->makeProduct(['id' => 160, 'name' => 'Brilliant Bond']);
        $this->expectGapLogged('seller name is empty', InvalidArgumentException::class);

        $this->assertSame(JsonLdScript::EMPTY_BLOCK, ProductStructuredData::render($obProduct, null, false, [], self::PAGE_URL, null));
    }

    public function testANamelessProductPrintsAnEmptyProductBlock()
    {
        $obProduct = $this->makeProduct(['id' => 161, 'name' => ' ']);
        $this->expectGapLogged('product 161 has no name', InvalidArgumentException::class);

        $this->assertSame(JsonLdScript::EMPTY_BLOCK, ProductStructuredData::render($obProduct, null, false, [], self::PAGE_URL, self::SELLER));
    }

    public function testAPriceWithNoCurrencyPrintsAnEmptyProductBlock()
    {
        $obProduct = $this->makeProduct(['id' => 160, 'name' => 'Brilliant Bond']);
        $obOffer = $this->makeOffer(['id' => 7, 'product_id' => 160, 'quantity' => 1], 8.9, ' ');
        $this->expectGapLogged('offer 7 has no currency code', InvalidArgumentException::class);

        $this->assertSame(JsonLdScript::EMPTY_BLOCK, ProductStructuredData::render($obProduct, $obOffer, false, [], self::PAGE_URL, self::SELLER));
    }

    public function testAPriceWithNoCurrencyLeavesThePriceMetasOut()
    {
        $obOffer = $this->makeOffer(['id' => 7, 'product_id' => 1, 'quantity' => 1], 8.9, ' ');
        $this->expectGapLogged('offer 7 has no currency code', InvalidArgumentException::class);

        $this->assertNull(ProductOfferPrice::resolveForTwig($obOffer));
    }

    public function testAnythingButAnOfferLeavesThePriceMetasOut()
    {
        $this->expectGapLogged('string given', InvalidArgumentException::class);

        $this->assertNull(ProductOfferPrice::resolveForTwig('6835'));
    }

    public function testAPricedOfferAnswersWhatResolveAnswers()
    {
        Log::shouldReceive('error')->never();
        $obOffer = $this->makeOffer(['id' => 6835, 'product_id' => 160, 'quantity' => 4], 8.9, 'EUR');

        $this->assertSame(ProductOfferPrice::resolve($obOffer), ProductOfferPrice::resolveForTwig($obOffer));
        $this->assertNull(ProductOfferPrice::resolveForTwig(null));
    }
}
