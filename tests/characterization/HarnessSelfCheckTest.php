<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

/**
 * Gate 4: the private-dispatcher harness behaves as the production wiring does.
 */
class HarnessSelfCheckTest extends PricingCharacterizationTestCase
{
    public function testSingleListenerOnPrivateDispatcher(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply([
            'import_vat_recalculate_enable'  => false,
            'import_currency_convert_enable' => false,
            'import_price_factor_enable'     => false,
        ]);

        $arResultList = PricingPipelineHarness::dispatchExtendImportDataRaw([
            'external_id' => 'test-product-001#test-ext-001',
            'price'       => 10,
        ]);

        self::assertCount(1, $arResultList);
        self::assertIsArray($arResultList[0]);
    }

    public function testFreshSubscriberPerDispatchDefeatsSettingsMemoization(): void
    {
        PricingSchemaSeeder::seedStandard();

        $arInput = [
            'external_id' => 'test-product-001#test-ext-001',
            'price'       => 10,
        ];

        PricingSettingsFixture::apply([
            'import_vat_recalculate_enable' => false,
            'import_price_factor_enable'    => false,
            'import_currency_convert_enable' => true,
            'import_conversion_rate'         => 12,
        ]);
        $arFirst = PricingPipelineHarness::dispatchExtendImportData($arInput);

        PricingSettingsFixture::apply([
            'import_vat_recalculate_enable'  => false,
            'import_price_factor_enable'     => false,
            'import_currency_convert_enable' => false,
        ]);
        $arSecond = PricingPipelineHarness::dispatchExtendImportData($arInput);

        self::assertEquals(120.0, $arFirst['price']);
        self::assertEquals(10, $arSecond['price']);
        self::assertNotEquals($arFirst['price'], $arSecond['price']);
    }
}
