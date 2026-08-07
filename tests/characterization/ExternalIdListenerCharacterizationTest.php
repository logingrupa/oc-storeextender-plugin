<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

/**
 * Group G1/G2 - listener-level fixExternalID + array_forget('product_id') behavior.
 */
class ExternalIdListenerCharacterizationTest extends PricingCharacterizationTestCase
{
    const ISOLATION = [
        'import_vat_recalculate_enable'  => false,
        'import_currency_convert_enable' => false,
        'import_price_factor_enable'     => false,
    ];

    public function testG1HashSplitThenProductIdForgotten(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => 'prodX#offerY',
            'product_id'  => 'STALE',
            'price'       => 10,
        ]);

        // fixExternalID split product_id='prodX'/external_id='offerY', then the listener
        // forgets product_id - product resolution is DB-only via the offer external_id.
        self::assertSame('offerY', $arResult['external_id']);
        self::assertArrayNotHasKey('product_id', $arResult);
    }

    public function testG2NoHashDropsExternalIdEntirely(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => 'test-ext-001',
            'product_id'  => 'STALE',
            'price'       => 10,
        ]);

        // OBSERVED WART (code is the truth): fixExternalID array_pulls external_id and
        // only restores it when the '#' split yields exactly 2 parts - without '#'
        // the key is REMOVED from the import data, it is NOT kept verbatim.
        self::assertArrayNotHasKey('external_id', $arResult);
        self::assertArrayNotHasKey('product_id', $arResult);
        self::assertEquals(10, $arResult['price']);
    }
}
