<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Classes\Helper\ProductStructuredData;
use Lovata\ReviewsShopaholic\Classes\Item\ReviewItem;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Item\ElementItem;
use October\Rain\Database\Collection;
use System\Models\File;

/**
 * The schema.org Product block Google reads on every product page.
 *
 * Search Console flagged nailscosmetics.lv with "Missing field 'price' (in
 * 'offers')": the Twig block printed "price": "" for every product with no
 * sellable offer (three active products without an active offer, and the 367
 * inactive products CustomProductPage still serves for SEO). It also printed a
 * one-review five-star aggregateRating for products with no reviews at all.
 *
 * Items are built from model data through reflection, the same way
 * OfferRenderContextTest does it: the Shopaholic schema does not build on
 * SQLite, and nothing here needs a row. The app is booted (core modules only)
 * because File::path and the url() helper need it. The two OfferItem accessors
 * that reach into the currency table are overridden on a test double.
 */
class ProductStructuredDataTest extends StoreExtenderPluginTestCase
{
    /** @var bool core module schema only, see class comment */
    protected $autoMigrate = false;

    const PAGE_URL = 'https://nailscosmetics.lv/lv/p/brilliant-bond';
    const SELLER = 'NAI_S cosmetics';

    public function testASellableOfferRendersAPricedOfferInTheOfferCurrency()
    {
        $obProduct = $this->makeProduct(['id' => 160, 'name' => 'Brilliant Bond', 'code' => 'PRD-160']);
        $obOffer = $this->makeOffer(['id' => 6835, 'product_id' => 160, 'name' => 'Brilliant Bond 12ml', 'code' => '4752307000417', 'quantity' => 4], 8.9, 'EUR');

        $arData = ProductStructuredData::build($obProduct, $obOffer, false, [], self::PAGE_URL, self::SELLER);

        $this->assertSame('Offer', $arData['offers']['@type']);
        $this->assertSame('8.90', $arData['offers']['price']);
        $this->assertSame('EUR', $arData['offers']['priceCurrency']);
        $this->assertSame(self::PAGE_URL, $arData['offers']['url']);
        $this->assertSame('https://schema.org/InStock', $arData['offers']['availability']);
        $this->assertSame(self::SELLER, $arData['offers']['seller']['name']);
        $this->assertSame(date('Y-m-d', strtotime('+1 year')), $arData['offers']['priceValidUntil']);
        $this->assertSame('SKU-160-6835', $arData['sku']);
    }

    public function testAProductWithoutAnOfferCarriesNoOffersAndNoRating()
    {
        $obProduct = $this->makeProduct(['id' => 371, 'name' => 'LOSJONIEM -35%']);

        $arData = ProductStructuredData::build($obProduct, null, false, [], self::PAGE_URL, self::SELLER);

        $this->assertArrayNotHasKey('offers', $arData, 'a product nobody can buy must not carry an Offer with an empty price');
        $this->assertArrayNotHasKey('aggregateRating', $arData, 'no reviews, no rating');
        $this->assertArrayNotHasKey('review', $arData);
        $this->assertSame('LOSJONIEM -35%', $arData['name']);
        $this->assertSame('SKU-371', $arData['sku']);
        $this->assertSame(['@type' => 'Brand', 'name' => self::SELLER], $arData['brand']);
    }

    public function testAnEmptyOrZeroPricedOfferIsNotAnOffer()
    {
        $obProduct = $this->makeProduct(['id' => 1, 'name' => 'P']);
        $obEmptyOffer = $this->makeOffer([], 0.0, 'EUR');
        $obFreeOffer = $this->makeOffer(['id' => 2, 'product_id' => 1, 'quantity' => 1], 0.0, 'EUR');

        $this->assertArrayNotHasKey('offers', ProductStructuredData::build($obProduct, $obEmptyOffer, false, [], self::PAGE_URL, self::SELLER));
        $this->assertArrayNotHasKey('offers', ProductStructuredData::build($obProduct, $obFreeOffer, false, [], self::PAGE_URL, self::SELLER));
    }

    public function testASoldOutOfferIsOutOfStock()
    {
        $obProduct = $this->makeProduct(['id' => 1, 'name' => 'P']);
        $obOffer = $this->makeOffer(['id' => 2, 'product_id' => 1, 'quantity' => 0], 12.5, 'NOK');

        $arData = ProductStructuredData::build($obProduct, $obOffer, false, [], self::PAGE_URL, self::SELLER);

        $this->assertSame('https://schema.org/OutOfStock', $arData['offers']['availability']);
        $this->assertSame('NOK', $arData['offers']['priceCurrency']);
        $this->assertSame('12.50', $arData['offers']['price']);
    }

    public function testTheNameFollowsTheShadeOnlyWhenTheVisitorChoseIt()
    {
        $obProduct = $this->makeProduct(['id' => 1, 'name' => 'Gellaka']);
        $obOffer = $this->makeOffer(['id' => 2, 'product_id' => 1, 'name' => 'Gellaka 014', 'quantity' => 1], 7.9, 'EUR');

        $this->assertSame('Gellaka', ProductStructuredData::build($obProduct, $obOffer, false, [], self::PAGE_URL, self::SELLER)['name']);
        $this->assertSame('Gellaka 014', ProductStructuredData::build($obProduct, $obOffer, true, [], self::PAGE_URL, self::SELLER)['name']);
    }

    public function testRatedReviewsFeedBothTheAggregateAndTheReviewList()
    {
        $obProduct = $this->makeProduct(['id' => 1, 'name' => 'P']);
        $arReviewList = [
            $this->makeReview(['id' => 1, 'name' => 'Amanda', 'rating' => 5, 'comment' => 'Super', 'created_at' => new DateTime('2026-08-01')]),
            $this->makeReview(['id' => 2, 'name' => '', 'rating' => 4, 'comment' => '']),
            $this->makeReview(['id' => 3, 'name' => 'Comment only', 'rating' => 0, 'comment' => 'no stars']),
        ];

        $arData = ProductStructuredData::build($obProduct, null, false, $arReviewList, self::PAGE_URL, self::SELLER);

        $this->assertSame(['@type' => 'AggregateRating', 'ratingValue' => 4.5, 'reviewCount' => 2, 'bestRating' => 5, 'worstRating' => 1], $arData['aggregateRating']);
        $this->assertCount(2, $arData['review'], 'a review without a rating is not a schema.org Review');
        $this->assertSame('Amanda', $arData['review'][0]['author']['name']);
        $this->assertSame('2026-08-01', $arData['review'][0]['datePublished']);
        $this->assertSame('Super', $arData['review'][0]['reviewBody']);
        $this->assertSame(5, $arData['review'][0]['reviewRating']['ratingValue']);
        $this->assertSame('Anonymous', $arData['review'][1]['author']['name']);
        $this->assertArrayNotHasKey('reviewBody', $arData['review'][1]);
        $this->assertArrayNotHasKey('datePublished', $arData['review'][1]);
    }

    public function testAGs1CodeIsAGtinAndAnythingElseIsAnMpn()
    {
        $obProduct = $this->makeProduct(['id' => 1, 'name' => 'P', 'code' => 'ABC-1']);

        $obEan = $this->makeOffer(['id' => 2, 'product_id' => 1, 'code' => '4751039484144', 'quantity' => 1], 1.0, 'EUR');
        $arData = ProductStructuredData::build($obProduct, $obEan, false, [], self::PAGE_URL, self::SELLER);
        $this->assertSame('4751039484144', $arData['gtin13']);
        $this->assertArrayNotHasKey('mpn', $arData);

        $obBadCheckDigit = $this->makeOffer(['id' => 2, 'product_id' => 1, 'code' => '4751039484145', 'quantity' => 1], 1.0, 'EUR');
        $arData = ProductStructuredData::build($obProduct, $obBadCheckDigit, false, [], self::PAGE_URL, self::SELLER);
        $this->assertSame('4751039484145', $arData['mpn']);
        $this->assertArrayNotHasKey('gtin13', $arData);

        $obNoCode = $this->makeOffer(['id' => 2, 'product_id' => 1, 'code' => '', 'quantity' => 1], 1.0, 'EUR');
        $this->assertSame('ABC-1', ProductStructuredData::build($obProduct, $obNoCode, false, [], self::PAGE_URL, self::SELLER)['mpn']);

        $obNoCodeAnywhere = $this->makeProduct(['id' => 1, 'name' => 'P']);
        $arData = ProductStructuredData::build($obNoCodeAnywhere, null, false, [], self::PAGE_URL, self::SELLER);
        $this->assertArrayNotHasKey('mpn', $arData);
        $this->assertArrayNotHasKey('gtin13', $arData);
    }

    public function testGs1CheckDigitsAcrossLengths()
    {
        $this->assertTrue(ProductStructuredData::isValidGs1Code('96385074'), 'gtin8');
        $this->assertTrue(ProductStructuredData::isValidGs1Code('036000291452'), 'gtin12');
        $this->assertTrue(ProductStructuredData::isValidGs1Code('4006381333931'), 'gtin13');
        $this->assertTrue(ProductStructuredData::isValidGs1Code('10614141000415'), 'gtin14');
        $this->assertFalse(ProductStructuredData::isValidGs1Code('4006381333932'));
        $this->assertFalse(ProductStructuredData::isValidGs1Code('12345'));
        $this->assertFalse(ProductStructuredData::isValidGs1Code('47510394841AA'));
    }

    public function testImagesAreOfferFirstUniqueAndCapped()
    {
        $obShared = $this->makeFile('shared000001.jpg');
        $obProduct = $this->makeProduct([
            'id' => 1,
            'name' => 'P',
            'preview_image' => $obShared,
            'images' => new Collection([$this->makeFile('product00001.jpg'), $this->makeFile('product00002.jpg')]),
        ]);
        $obOffer = $this->makeOffer([
            'id' => 2,
            'product_id' => 1,
            'quantity' => 1,
            'preview_image' => $this->makeFile('offer0000001.jpg'),
            'images' => new Collection([$obShared, $this->makeFile('offer0000002.jpg')]),
        ], 1.0, 'EUR');

        $arData = ProductStructuredData::build($obProduct, $obOffer, false, [], self::PAGE_URL, self::SELLER);

        $arNameList = array_map('basename', $arData['image']);
        $this->assertSame(['offer0000001.jpg', 'shared000001.jpg', 'offer0000002.jpg', 'product00001.jpg', 'product00002.jpg'], $arNameList);
        $this->assertStringStartsWith('http', $arData['image'][0]);

        $arManyImages = [];
        for ($iIndex = 0; $iIndex < 12; $iIndex++) {
            $arManyImages[] = $this->makeFile(sprintf('many%08d.jpg', $iIndex));
        }
        $obCrowded = $this->makeProduct(['id' => 1, 'name' => 'P', 'images' => new Collection($arManyImages)]);
        $this->assertCount(ProductStructuredData::IMAGE_LIMIT, ProductStructuredData::build($obCrowded, null, false, [], self::PAGE_URL, self::SELLER)['image']);

        $obBare = $this->makeProduct(['id' => 1, 'name' => 'P']);
        $this->assertArrayNotHasKey('image', ProductStructuredData::build($obBare, null, false, [], self::PAGE_URL, self::SELLER));
    }

    public function testDescriptionIsPlainTextAndTheScriptElementCannotBeBrokenOut()
    {
        $obProduct = $this->makeProduct([
            'id' => 1,
            'name' => 'P',
            'description' => "<p>Bezsk&#257;bes  \n\n praimeris &amp; gels</p><p>Otrs teikums.<br>Trešais</p><script>alert(1)</script></script>",
        ]);

        $arData = ProductStructuredData::build($obProduct, null, false, [], self::PAGE_URL, self::SELLER);
        $this->assertSame('Bezskābes praimeris & gels Otrs teikums. Trešais alert(1)', $arData['description']);

        $sJson = ProductStructuredData::render($obProduct, null, false, [], self::PAGE_URL, self::SELLER);
        $this->assertStringNotContainsString('</', $sJson);
        $this->assertSame($arData, json_decode($sJson, true));

        $obPreviewOnly = $this->makeProduct(['id' => 1, 'name' => 'P', 'description' => '<p> </p>', 'preview_text' => 'Short text']);
        $this->assertSame('Short text', ProductStructuredData::build($obPreviewOnly, null, false, [], self::PAGE_URL, self::SELLER)['description']);

        $obNoText = $this->makeProduct(['id' => 1, 'name' => 'P']);
        $this->assertArrayNotHasKey('description', ProductStructuredData::build($obNoText, null, false, [], self::PAGE_URL, self::SELLER));
    }

    public function testBoundaryAssertions()
    {
        $obProduct = $this->makeProduct(['id' => 1, 'name' => 'P']);

        try {
            ProductStructuredData::build($this->makeProduct([]), null, false, [], self::PAGE_URL, self::SELLER);
            $this->fail('empty product accepted');
        } catch (InvalidArgumentException $obException) {
            $this->assertStringContainsString('product item is empty', $obException->getMessage());
        }

        try {
            ProductStructuredData::build($obProduct, $obProduct, false, [], self::PAGE_URL, self::SELLER);
            $this->fail('a ProductItem accepted as the offer');
        } catch (InvalidArgumentException $obException) {
            $this->assertStringContainsString('must be an OfferItem', $obException->getMessage());
        }

        try {
            ProductStructuredData::build($obProduct, null, false, [], ' ', self::SELLER);
            $this->fail('blank page URL accepted');
        } catch (InvalidArgumentException $obException) {
            $this->assertStringContainsString('page URL', $obException->getMessage());
        }

        try {
            ProductStructuredData::build($obProduct, null, false, [], self::PAGE_URL, '');
            $this->fail('blank seller accepted');
        } catch (InvalidArgumentException $obException) {
            $this->assertStringContainsString('seller name', $obException->getMessage());
        }
    }

    /**
     * @param array $arModelData
     * @return ProductItem
     */
    protected function makeProduct(array $arModelData): ProductItem
    {
        /** @var ProductItem $obItem */
        $obItem = $this->makeItem(ProductItem::class, $arModelData);

        return $obItem;
    }

    /**
     * price_value and currency_code read the currency table on a real
     * OfferItem; the double answers with what the fixture says.
     * @param array  $arModelData
     * @param float  $fPrice
     * @param string $sCurrencyCode
     * @return OfferItem
     */
    protected function makeOffer(array $arModelData, float $fPrice, string $sCurrencyCode): OfferItem
    {
        $obPrototype = new class(0, null) extends OfferItem {
            /** @var float */
            public $fFakePrice = 0.0;
            /** @var string */
            public $sFakeCurrencyCode = '';

            protected function getPriceValueAttribute()
            {
                return $this->fFakePrice;
            }

            protected function getCurrencyCodeAttribute()
            {
                return $this->sFakeCurrencyCode;
            }
        };

        /** @var OfferItem $obItem */
        $obItem = $this->makeItem(get_class($obPrototype), $arModelData);
        $obItem->fFakePrice = $fPrice;
        $obItem->sFakeCurrencyCode = $sCurrencyCode;

        return $obItem;
    }

    /**
     * @param array $arModelData
     * @return ReviewItem
     */
    protected function makeReview(array $arModelData): ReviewItem
    {
        /** @var ReviewItem $obItem */
        $obItem = $this->makeItem(ReviewItem::class, $arModelData);

        return $obItem;
    }

    /**
     * An item with model data and no database, no cache and no constructor.
     * @param string $sClass
     * @param array  $arModelData
     * @return ElementItem
     */
    protected function makeItem(string $sClass, array $arModelData): ElementItem
    {
        $obReflection = new ReflectionClass($sClass);
        /** @var ElementItem $obItem */
        $obItem = $obReflection->newInstanceWithoutConstructor();

        $obProperty = $obReflection->getProperty('arModelData');
        $obProperty->setAccessible(true);
        $obProperty->setValue($obItem, $arModelData);

        return $obItem;
    }

    /**
     * A public upload whose path resolves through the booted app's url().
     * @param string $sDiskName
     * @return File
     */
    protected function makeFile(string $sDiskName): File
    {
        $obFile = new File();
        $obFile->disk_name = $sDiskName;
        $obFile->file_name = $sDiskName;
        $obFile->is_public = true;

        return $obFile;
    }
}
