<?php

use Lovata\Shopaholic\Models\XmlImportSettings;

/**
 * Applies the .no baseline XmlImportSettings for characterization tests.
 *
 * Convention verified in XmlImportPriceFlickerTest::seedNorwegianSiteSettings():
 * XmlImportSettings::set() persists through the SettingModel behavior into the hermetic
 * system_settings table; production code reads back via XmlImportSettings::getValue().
 * Test rate 12 is deliberately NOT the live NOK rate - goldens are synthetic and stable.
 */
class PricingSettingsFixture
{
    /**
     * The exact .no baseline from 01-testing-strategy.md B.1 Step 3.
     * @return array
     */
    public static function baseline(): array
    {
        return [
            'import_vat_recalculate_enable' => true,
            'import_vat_rate'               => 25,
            'import_vat_mapping'            => [['source_vat_rate' => 21, 'target_tax_id' => 1]],
            'import_currency_convert_enable' => true,
            'import_conversion_rate'         => 12,
            'import_price_factor_enable'  => true,
            'import_price_factor_mapping' => [
                ['price_type_id' => 0, 'factor' => 1.5642], ['price_type_id' => 1, 'factor' => 1.17],
                ['price_type_id' => 2, 'factor' => 1.17],   ['price_type_id' => 3, 'factor' => 1],
                ['price_type_id' => 6, 'factor' => 1.17],   ['price_type_id' => 4, 'factor' => 1.17],
            ],
        ];
    }

    /**
     * Merge overrides onto the baseline and persist.
     * An override value of exactly NULL REMOVES the key from the written record
     * (needed to pin getValue() defaults, e.g. case C9 'import_vat_rate' unset -
     * writing NULL would make (float) cast yield 0, not the coded default 21).
     *
     * @param array $arOverrideList
     * @return void
     */
    public static function apply(array $arOverrideList = []): void
    {
        $arSettingList = array_merge(self::baseline(), $arOverrideList);

        foreach ($arSettingList as $sKey => $mValue) {
            if ($mValue === null) {
                unset($arSettingList[$sKey]);
            }
        }

        XmlImportSettings::set($arSettingList);
    }
}
