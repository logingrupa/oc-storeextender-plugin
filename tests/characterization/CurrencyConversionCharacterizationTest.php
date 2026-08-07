<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Group B - currency conversion characterization (cases B1-B4).
 * Pins the main/price_list conversion split: the EXTEND_IMPORT_DATA listener converts
 * main only; price_list conversion happens in EVENT_BEFORE_IMPORT (convertPriceListOnly).
 */
class CurrencyConversionCharacterizationTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    const ISOLATION = [
        'import_vat_recalculate_enable' => false,
        'import_price_factor_enable'    => false,
    ];

    public function testB1ConvertsMainOnlyAndLeavesPriceListInEur(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 10,
            'old_price'   => 13,
            'price_list'  => [3 => ['price' => 5]],
        ]);

        self::assertEquals(120.0, $arResult['price']);
        self::assertEquals(156.0, $arResult['old_price']);
        // izpl step copied/clamped in EUR; the EXTEND_IMPORT_DATA listener never rates pl.
        self::assertEquals(5.0, $arResult['price_list'][3]['price']);
        self::assertEquals(10.0, $arResult['price_list'][3]['old_price']);
        self::assertEquals(10.0, $arResult['price_list'][2]['price']);
        self::assertEquals(13.0, $arResult['price_list'][2]['old_price']);
    }

    public function testB2DisabledToggleBeatsConfiguredRate(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_currency_convert_enable' => false,
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 10,
        ]);

        self::assertEquals(10, $arResult['price']);
    }

    public function testB3BeforeImportConvertsPriceListOnly(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arInput = [
            'external_id' => 'test-ext-001',
            'price'       => 10,
            'price_list'  => [
                1 => ['price' => 2],
                2 => ['price' => 3, 'old_price' => 4],
            ],
        ];

        $arResult = PricingPipelineHarness::dispatchBeforeImport(Offer::class, $arInput);

        self::assertEquals(24.0, $arResult['price_list'][1]['price']);
        self::assertEquals(36.0, $arResult['price_list'][2]['price']);
        self::assertEquals(48.0, $arResult['price_list'][2]['old_price']);
        self::assertEquals(10, $arResult['price']);

        // Non-Offer model class: listener guard returns null.
        $mNonOffer = PricingPipelineHarness::dispatchBeforeImport(Product::class, $arInput);
        self::assertNull($mNonOffer);
    }

    public function testB4ZeroPriceIsSkippedByEmptyGuard(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 0,
        ]);

        // Pins the !empty() wart: 0.00 is never converted.
        self::assertEquals(0, $arResult['price']);
    }
}
