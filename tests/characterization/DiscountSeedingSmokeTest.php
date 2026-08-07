<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;

/**
 * Gate 1 risk spike: Discount (TranslatableModel behavior, Validation, Sortable,
 * TraitCached) + DiscountItem (Toolbox CCache) + ProductCollection->discount()
 * must all work on the hermetic SQLite schema before any golden is written.
 */
class DiscountSeedingSmokeTest extends PricingCharacterizationTestCase
{
    public function testRegularDiscountSeedsAndResolvesThroughActivePriceHelper(): void
    {
        PricingSchemaSeeder::seedPriceTypes();
        PricingSchemaSeeder::seedGlobalTax();
        [$iProductId] = PricingSchemaSeeder::seedProductAndOffer();

        $obDiscount = PricingSchemaSeeder::seedRegularDiscount();
        PricingSchemaSeeder::attachProductToDiscount($obDiscount, $iProductId);

        $obDiscountItem = ActivePriceHelper::instance()->getRegularDiscount();
        // Toolbox gotcha: assign magic props to variables before comparing.
        $fDiscountValue = $obDiscountItem->discount_value;
        self::assertEquals(10, $fDiscountValue);

        $obProductList = ActivePriceHelper::instance()->getRegularDiscountProductList();
        self::assertTrue($obProductList->has($iProductId));
    }

    public function testMissingDiscountCodeYieldsEmptyItem(): void
    {
        PricingSchemaSeeder::seedPriceTypes();
        PricingSchemaSeeder::seedProductAndOffer();

        $obDiscountItem = ActivePriceHelper::instance()->getAuthorizedDiscount();
        self::assertTrue($obDiscountItem->isEmpty());
    }
}
