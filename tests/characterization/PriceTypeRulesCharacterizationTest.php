<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

/**
 * Group C - salona / izpl / vairum price-type rules (cases C1-C10).
 * These three steps ALWAYS run (no toggle). Hardcoded reads price_list.2/.3/.1,
 * writes to price_list.{findByCode(...)->id} - the id<->code coupling is invariant.
 * Isolated: recalc/conversion/factor OFF, import_vat_rate=25 (multiplier 1.25).
 */
class PriceTypeRulesCharacterizationTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    const ISOLATION = [
        'import_vat_recalculate_enable'  => false,
        'import_currency_convert_enable' => false,
        'import_price_factor_enable'     => false,
    ];

    protected function dispatchIsolated(array $arImportData): array
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        return PricingPipelineHarness::dispatchExtendImportData($arImportData);
    }

    public function testC1AllThreeRowsInjectedWithMainFallback(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'old_price'   => 0,
        ]);

        foreach ([2, 3, 1] as $iPriceTypeId) {
            self::assertEquals(9.58, $arResult['price_list'][$iPriceTypeId]['price']);
            self::assertEquals(0, $arResult['price_list'][$iPriceTypeId]['old_price']);
        }
    }

    public function testC2SalonaBelowMainGetsVatAndMainBecomesOldPrice(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        // round(6.61*1.25)=8.26; main 9.58 becomes old_price
        self::assertEquals(8.26, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
    }

    public function testC3SalonaAboveMainClampsToMain(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [2 => ['price' => 8.00]],
        ]);

        // 8.00*1.25=10.00 > 9.58 -> clamp to main, old_price 0
        self::assertEquals(9.58, $arResult['price_list'][2]['price']);
        self::assertEquals(0, $arResult['price_list'][2]['old_price']);
    }

    public function testC4OldEqualToPriceIsZeroedBeforeBranching(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'old_price'   => 9.58,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        self::assertEquals(8.26, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
        // izpl also dedupes old==price -> fallback row carries old_price 0, not 9.58
        self::assertEquals(9.58, $arResult['price_list'][3]['price']);
        self::assertEquals(0, $arResult['price_list'][3]['old_price']);
    }

    public function testC5OldEqualToSalonaSourceIsZeroed(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'old_price'   => 6.61,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        // $fOriginalOldPrice == $fSalonaPrice -> zeroed for the salona step only
        self::assertEquals(8.26, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
        // izpl step re-reads old_price 6.61 (no dedupe hit there: 6.61 != 9.58, != 0)
        self::assertEquals(9.58, $arResult['price_list'][3]['price']);
        self::assertEquals(6.61, $arResult['price_list'][3]['old_price']);
    }

    public function testC6IzplHasNoVatMultiplier(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [3 => ['price' => 4.00]],
        ]);

        self::assertEquals(4.00, $arResult['price_list'][3]['price']);
        self::assertEquals(9.58, $arResult['price_list'][3]['old_price']);
    }

    public function testC7IzplAboveMainClampsToMain(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [3 => ['price' => 11.00]],
        ]);

        self::assertEquals(9.58, $arResult['price_list'][3]['price']);
        self::assertEquals(0, $arResult['price_list'][3]['old_price']);
    }

    public function testC8VairumMirrorsSalonaIncludingVat(): void
    {
        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [1 => ['price' => 6.61]],
        ]);

        self::assertEquals(8.26, $arResult['price_list'][1]['price']);
        self::assertEquals(9.58, $arResult['price_list'][1]['old_price']);
    }

    public function testC9UnsetVatRateFallsBackToCodedDefault21(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION + [
            'import_vat_rate' => null, // removed from the record -> getValue default 21
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id' => self::EXTERNAL_ID,
            'price'       => 9.58,
            'price_list'  => [2 => ['price' => 6.61]],
        ]);

        // 6.61*1.21=7.9981 -> round -> 8.00 (multiplier 1.21 proves the default)
        self::assertEquals(8.00, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
    }

    public function testC10StringPriceListKeysBehaveLikeIntKeys(): void
    {
        // XML-parsed shape carries string keys; PHP canonicalizes numeric-string array
        // keys to int at construction, so array_get('price_list.2.price') resolves
        // identically. This pins the migration against int/string key regressions.
        $arPriceList = ['2' => ['price' => '6.61']];
        self::assertSame([2], array_keys($arPriceList));

        $arResult = $this->dispatchIsolated([
            'external_id' => self::EXTERNAL_ID,
            'price'       => '9.58',
            'price_list'  => $arPriceList,
        ]);

        self::assertEquals(8.26, $arResult['price_list'][2]['price']);
        self::assertEquals(9.58, $arResult['price_list'][2]['old_price']);
    }
}
