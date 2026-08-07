<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

/**
 * Group F - derived price types pl[4] (authorized) / pl[6] (regular) plus THE OPEN
 * QUESTION from 00-context.md: do steps 7-8 overwrite factored pl[4]/pl[6] values?
 * F5/F6 use absurd tracer factors {4: 9.99, 6: 9.99} to make any survival obvious.
 */
class DerivedPriceTypeDiscountCharacterizationTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    const ISOLATION = [
        'import_vat_recalculate_enable'  => false,
        'import_currency_convert_enable' => false,
        'import_price_factor_enable'     => false,
    ];

    /**
     * @param bool $bAttachProduct
     * @return int product id
     */
    protected function seedWithDiscounts(bool $bAttachProduct): int
    {
        [$iProductId] = PricingSchemaSeeder::seedStandard();
        $obAuthorized = PricingSchemaSeeder::seedAuthorizedDiscount();
        $obRegular = PricingSchemaSeeder::seedRegularDiscount();

        if ($bAttachProduct) {
            PricingSchemaSeeder::attachProductToDiscount($obAuthorized, $iProductId);
            PricingSchemaSeeder::attachProductToDiscount($obRegular, $iProductId);
        }

        return $iProductId;
    }

    public function testF1DerivedRowsAlwaysWrittenWhenDiscountAndPriceTypeExist(): void
    {
        $this->seedWithDiscounts(false);
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 100,
            'old_price'   => 0,
        ]);

        // Product not in either pivot, no feed discount -> price passes, dedupe zeroes old
        self::assertEquals(100.0, $arResult['price_list'][4]['price']);
        self::assertEquals(0, $arResult['price_list'][4]['old_price']);
        self::assertEquals(100.0, $arResult['price_list'][6]['price']);
        self::assertEquals(0, $arResult['price_list'][6]['old_price']);
    }

    public function testF2PercentPathWithPriceAsBaseWhenOldPriceEmpty(): void
    {
        $this->seedWithDiscounts(true);
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 100,
        ]);

        self::assertEquals(80.00, $arResult['price_list'][4]['price']);
        self::assertEquals(100.0, $arResult['price_list'][4]['old_price']);
        self::assertEquals(90.00, $arResult['price_list'][6]['price']);
        self::assertEquals(100.0, $arResult['price_list'][6]['old_price']);
    }

    public function testF3OldPriceIsTheBaseWhenPresent(): void
    {
        $this->seedWithDiscounts(true);
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 70,
            'old_price'   => 100,
        ]);

        // Base is old_price 100, not price 70
        self::assertEquals(80.00, $arResult['price_list'][4]['price']);
        self::assertEquals(100.0, $arResult['price_list'][4]['old_price']);
        self::assertEquals(90.00, $arResult['price_list'][6]['price']);
        self::assertEquals(100.0, $arResult['price_list'][6]['old_price']);
    }

    public function testF4FeedDiscountValueWinsWhenLarger(): void
    {
        $this->seedWithDiscounts(true);
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id'       => self::EXTERNAL_ID,
            'price'             => 100,
            'discount_value'    => 30,
            'discount_name'     => 'Aug sale',
            'discount_date_end' => '2026-09-10',
        ]);

        // Step 2 rewrote price=70/old=100 and left discount_value=30 in the array;
        // 30 > 20 and 30 > 10 -> the feed value wins in BOTH derived rows.
        self::assertEquals(70.00, $arResult['price_list'][4]['price']);
        self::assertEquals(100.0, $arResult['price_list'][4]['old_price']);
        self::assertEquals(70.00, $arResult['price_list'][6]['price']);
        self::assertEquals(100.0, $arResult['price_list'][6]['old_price']);
    }

    public function testF5OpenQuestionBranchANoDiscountRowsFactoredValuesSurvive(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply([
            'import_vat_recalculate_enable'  => false,
            'import_currency_convert_enable' => false,
            'import_price_factor_mapping'    => [
                ['price_type_id' => 4, 'factor' => 9.99],
                ['price_type_id' => 6, 'factor' => 9.99],
            ],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 100,
            'price_list'  => [4 => ['price' => 100], 6 => ['price' => 100]],
        ]);

        // RECORDED ANSWER (branch a): with NO discount records, steps 7-8 early-return
        // and the factored tracer values SURVIVE -> factor rows on 4/6 are live config
        // in the no-discount-record configuration.
        self::assertEquals(999.00, $arResult['price_list'][4]['price']);
        self::assertEquals(999.00, $arResult['price_list'][6]['price']);
    }

    public function testF6OpenQuestionBranchBDiscountRowsRebuildAndDiscardFactoredValues(): void
    {
        $this->seedWithDiscounts(true);
        PricingSettingsFixture::apply([
            'import_vat_recalculate_enable'  => false,
            'import_currency_convert_enable' => false,
            'import_price_factor_mapping'    => [
                ['price_type_id' => 4, 'factor' => 9.99],
                ['price_type_id' => 6, 'factor' => 9.99],
            ],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 100,
            'price_list'  => [4 => ['price' => 100], 6 => ['price' => 100]],
        ]);

        // RECORDED ANSWER (branch b - the decisive test): steps 7-8 REBUILD pl[4]/pl[6]
        // from the main price/old_price via array_set, DISCARDING the factored 999.00.
        // Factors on types 4/6 are dead config whenever discount records exist.
        self::assertEquals(80.00, $arResult['price_list'][4]['price']);
        self::assertEquals(100.0, $arResult['price_list'][4]['old_price']);
        self::assertEquals(90.00, $arResult['price_list'][6]['price']);
        self::assertEquals(100.0, $arResult['price_list'][6]['old_price']);
    }
}
