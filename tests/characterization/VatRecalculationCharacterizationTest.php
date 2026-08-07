<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

/**
 * Group A - recalculateMainPriceVat characterization (cases A1-A7).
 * Pins CURRENT behavior, bugs included. Hand-computed expectations follow the
 * code math exactly: calculatePriceWithoutTax = round(p*100/(100+t), 2), then
 * calculatePriceWithTax = round(p*(100+t)/100, 2) - intermediate 2dp rounding.
 */
class VatRecalculationCharacterizationTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    /** @var array Toggles that isolate the VAT step */
    const ISOLATION = [
        'import_currency_convert_enable' => false,
        'import_price_factor_enable'     => false,
    ];

    public function testA1RecalculateDisabledPassesPriceThrough(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_vat_recalculate_enable' => false,
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 11.50,
        ]);

        self::assertEquals(11.50, $arResult['price']);
        // The always-on price-type steps still inject pl[2]/pl[3]/pl[1] (see C1).
        foreach ([1, 2, 3] as $iPriceTypeId) {
            self::assertEquals(11.50, $arResult['price_list'][$iPriceTypeId]['price']);
            self::assertEquals(0, $arResult['price_list'][$iPriceTypeId]['old_price']);
        }
    }

    public function testA2DefaultMappingRowWithIntermediateRounding(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => '11.50',
            'old_price'   => '15.00',
        ]);

        // round(11.50/1.21)=9.50 -> round(9.50*1.25)=11.88 (NOT round(11.50/1.21*1.25)=11.88 by luck of 2dp)
        self::assertEquals(11.88, $arResult['price']);
        // round(15.00/1.21)=12.40 -> round(12.40*1.25)=15.50
        self::assertEquals(15.50, $arResult['old_price']);
    }

    public function testA3ReverseLookupByProductTaxLinkBeatsDefaultRow(): void
    {
        [$iProductId] = PricingSchemaSeeder::seedStandard();
        PricingSchemaSeeder::seedNonGlobalTax(2, 15);
        PricingSchemaSeeder::linkProductToTax(2, $iProductId);

        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_vat_mapping' => [
                ['source_vat_rate' => 21, 'target_tax_id' => 1],
                ['source_vat_rate' => 12, 'target_tax_id' => 2],
            ],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 11.50,
        ]);

        // Reverse lookup picks [12, 15]: round(11.50/1.12)=10.27 -> round(10.27*1.15)=11.81
        self::assertEquals(11.81, $arResult['price']);
    }

    public function testA4UnknownOfferSkipsVatAndDerivedSteps(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => 'nope#missing-offer',
            'price'       => 11.50,
        ]);

        self::assertEquals(11.50, $arResult['price']);
        // resolveProductIdFromExternalId() null -> steps 7-8 never run.
        self::assertArrayNotHasKey(4, $arResult['price_list']);
        self::assertArrayNotHasKey(6, $arResult['price_list']);
    }

    public function testA5NonPositiveSourceRateGuard(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_vat_mapping' => [['source_vat_rate' => 0, 'target_tax_id' => 1]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 11.50,
        ]);

        self::assertEquals(11.50, $arResult['price']);
    }

    public function testA6NonexistentTargetTaxLeavesPriceUntouched(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_vat_mapping' => [['source_vat_rate' => 21, 'target_tax_id' => 99]],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 11.50,
        ]);

        self::assertEquals(11.50, $arResult['price']);
    }

    public function testA7EmptyMappingBeatsEnabledToggle(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_vat_mapping' => [],
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 11.50,
        ]);

        self::assertEquals(11.50, $arResult['price']);
    }
}
