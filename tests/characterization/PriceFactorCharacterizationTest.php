<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

/**
 * Group D - applyPriceFactor characterization (cases D1-D7).
 * Sentinel price_type_id=0 factors the top-level price/old_price. Factor runs as
 * step 6, BEFORE currency conversion (step 9) - the docblock in production code
 * claims otherwise; D7 pins the REAL order via rounding sensitivity.
 */
class PriceFactorCharacterizationTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    const ISOLATION = [
        'import_vat_recalculate_enable'  => false,
        'import_currency_convert_enable' => false,
    ];

    public function testD1SentinelZeroFactorsMainOnly(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_mapping' => [['price_type_id' => 0, 'factor' => 1.5642]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 100,
            'old_price'   => 120,
        ]);

        self::assertEquals(156.42, $arResult['price']);
        // round(120*1.5642)=round(187.704)=187.70
        self::assertEquals(187.70, $arResult['old_price']);
        // pl rows injected by Group C steps (run BEFORE factor) are unmapped -> untouched
        foreach ([1, 2, 3] as $iPriceTypeId) {
            self::assertEquals(100.0, $arResult['price_list'][$iPriceTypeId]['price']);
            self::assertEquals(120.0, $arResult['price_list'][$iPriceTypeId]['old_price']);
        }
    }

    public function testD2IzplFactorOneIsALiveNoOpRowNotASkippedRow(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_mapping' => [['price_type_id' => 3, 'factor' => 1]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [3 => ['price' => 4.00]],
        ]);

        // factor 1 is applied (empty(1.0) is false) and survives identically
        self::assertEquals(4.00, $arResult['price_list'][3]['price']);
        self::assertEquals(9.58, $arResult['price_list'][3]['old_price']);
    }

    public function testD3FactorAppliesAfterSalonaRule(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_mapping' => [['price_type_id' => 2, 'factor' => 1.17]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        // Step order 3->6: salona first (8.26/9.58), then factor:
        // round(8.26*1.17)=9.66, round(9.58*1.17)=round(11.2086)=11.21
        self::assertEquals(9.66, $arResult['price_list'][2]['price']);
        self::assertEquals(11.21, $arResult['price_list'][2]['old_price']);
    }

    public function testD4MappedButAbsentTypeIsANoOp(): void
    {
        PricingSchemaSeeder::seedStandard();

        $arInput = [
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [
                1 => ['price' => 5.00],
                2 => ['price' => 6.00],
                3 => ['price' => 4.00],
            ],
        ];

        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_mapping' => [['price_type_id' => 5, 'factor' => 2.0]],
        ]);
        $arWithFactor = PricingPipelineHarness::dispatchExtendImportData($arInput);

        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_enable' => false,
        ]);
        $arWithoutFactor = PricingPipelineHarness::dispatchExtendImportData($arInput);

        self::assertSame($arWithoutFactor, $arWithFactor);
    }

    public function testD5DisabledToggleBeatsConfiguredMapping(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_enable'  => false,
            'import_price_factor_mapping' => [['price_type_id' => 2, 'factor' => 1.17]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        self::assertEquals(8.26, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
        self::assertEquals(9.58, $arResult['price']);
    }

    public function testD6MalformedMappingRowsAreAllSkipped(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_price_factor_mapping' => [
                ['price_type_id' => -1, 'factor' => 2],
                ['price_type_id' => 2, 'factor' => 0],
                ['factor' => 2],
            ],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        // negative id / empty factor / missing key guards -> untouched
        self::assertEquals(8.26, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
        self::assertEquals(9.58, $arResult['price']);
    }

    public function testD7FactorRunsBeforeCurrencyConversion(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply([
            'import_vat_recalculate_enable' => false,
            'import_price_factor_mapping'   => [['price_type_id' => 0, 'factor' => 1.17]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 10.55,
        ]);

        // Factor-first: round(10.55*1.17)=12.34 -> 12.34*12=148.08.
        // Conversion-first would give 10.55*12=126.60 -> round(126.60*1.17)=148.12.
        // The production docblock claims factor runs after conversion - it does NOT.
        self::assertEquals(148.08, $arResult['price']);
    }
}
