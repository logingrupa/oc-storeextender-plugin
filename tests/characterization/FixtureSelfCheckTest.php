<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Models\XmlImportSettings;

/**
 * Gate 3: fixture helpers are trustworthy before any golden relies on them.
 */
class FixtureSelfCheckTest extends PricingCharacterizationTestCase
{
    public function testEveryBaselineKeyRoundTripsThroughGetValue(): void
    {
        PricingSettingsFixture::apply();

        foreach (PricingSettingsFixture::baseline() as $sKey => $mExpected) {
            $mActual = XmlImportSettings::getValue($sKey);
            self::assertEquals($mExpected, $mActual, "Settings key '{$sKey}' did not round-trip");
        }
    }

    public function testOverrideMergesOntoBaseline(): void
    {
        PricingSettingsFixture::apply(['import_conversion_rate' => 7]);

        self::assertEquals(7, XmlImportSettings::getValue('import_conversion_rate'));
        self::assertEquals(25, XmlImportSettings::getValue('import_vat_rate'));
    }

    public function testNullOverrideRemovesKeySoCodedDefaultApplies(): void
    {
        PricingSettingsFixture::apply(['import_vat_rate' => null]);

        self::assertEquals(21, XmlImportSettings::getValue('import_vat_rate', 21));
    }

    public function testSeederYieldsExactPriceTypeIdCodeCoupling(): void
    {
        PricingSchemaSeeder::seedPriceTypes();

        $arExpectedIdByCode = [
            'vairum'     => 1,
            'salona'     => 2,
            'izpl'       => 3,
            'authorized' => 4,
            'regular'    => 6,
        ];

        foreach ($arExpectedIdByCode as $sCode => $iExpectedId) {
            $obPriceType = PriceTypeHelper::instance()->findByCode($sCode);
            self::assertNotNull($obPriceType, "Price type '{$sCode}' missing");
            self::assertSame($iExpectedId, (int) $obPriceType->id, "Price type '{$sCode}' id mismatch");
        }
    }
}
