<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

use Lovata\Shopaholic\Models\Offer;

/**
 * Group G3/G4 - composite whole-array JSON goldens. These files are THE contract the
 * Phase 2 1:1 migration must reproduce byte-for-byte (copied verbatim into the new
 * plugin's tests/fixtures/golden/).
 *
 * Regeneration: UPDATE_GOLDENS=1 writes actual output (never under CI); normal runs
 * read + compare. NEVER edit an expectation to make a test pass.
 */
class PipelineCompositeGoldenTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    /**
     * Realistic .no-shaped composite input (spec B.2 G3).
     * @return array
     */
    protected function compositeInput(): array
    {
        return [
            'external_id'       => self::EXTERNAL_ID,
            'price'             => '11.50',
            'old_price'         => '15.00',
            'price_list'        => [
                '1' => ['price' => '5.10'],
                '2' => ['price' => '6.61'],
                '3' => ['price' => '4.49'],
            ],
            'discount_value'    => '',
            'discount_name'     => '',
            'discount_date_end' => '',
            'value_condition'   => '',
        ];
    }

    /**
     * Seed the full G3 scenario: standard schema + both derived discounts attached.
     * @return void
     */
    protected function seedComposite(): void
    {
        [$iProductId] = PricingSchemaSeeder::seedStandard();
        $obAuthorized = PricingSchemaSeeder::seedAuthorizedDiscount();
        $obRegular = PricingSchemaSeeder::seedRegularDiscount();
        PricingSchemaSeeder::attachProductToDiscount($obAuthorized, $iProductId);
        PricingSchemaSeeder::attachProductToDiscount($obRegular, $iProductId);
    }

    public function testG3CompositeNoGolden(): void
    {
        $this->seedComposite();
        PricingSettingsFixture::apply();

        $arResult = PricingPipelineHarness::dispatchExtendImportData($this->compositeInput());

        $this->assertMatchesGolden('g3-composite-no', $arResult);
    }

    public function testG3aCompositeWithoutDiscountRecordsGolden(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply();

        $arResult = PricingPipelineHarness::dispatchExtendImportData($this->compositeInput());

        $this->assertMatchesGolden('g3a-composite-no-discounts', $arResult);
    }

    public function testG3bCompositeConversionOffGolden(): void
    {
        // ~ .lv shape - protects the "other sites bit-for-bit unaffected" requirement.
        $this->seedComposite();
        PricingSettingsFixture::apply(['import_currency_convert_enable' => false]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData($this->compositeInput());

        $this->assertMatchesGolden('g3b-composite-conversion-off', $arResult);
    }

    public function testG3cCompositeRecalcOffGolden(): void
    {
        $this->seedComposite();
        PricingSettingsFixture::apply(['import_vat_recalculate_enable' => false]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData($this->compositeInput());

        $this->assertMatchesGolden('g3c-composite-recalc-off', $arResult);
    }

    public function testG4CompositePriceListConversionGolden(): void
    {
        // The full two-listener sequence PASS 2 actually performs: G3 output fed
        // into EVENT_BEFORE_IMPORT (convertPriceListOnly).
        $this->seedComposite();
        PricingSettingsFixture::apply();

        $arPassOne = PricingPipelineHarness::dispatchExtendImportData($this->compositeInput());
        $arResult = PricingPipelineHarness::dispatchBeforeImport(Offer::class, $arPassOne);

        $this->assertMatchesGolden('g4-composite-price-list-conversion', $arResult);
    }

    /**
     * Compare against (or with UPDATE_GOLDENS=1 regenerate) a committed JSON golden.
     *
     * @param string $sName
     * @param array  $arActual
     * @return void
     */
    protected function assertMatchesGolden(string $sName, array $arActual): void
    {
        $sPath = __DIR__ . '/fixtures/golden/' . $sName . '.json';

        $arNormalized = $this->ksortRecursive($arActual);
        $sJson = json_encode(
            $arNormalized,
            JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
        ) . "\n";

        if (getenv('UPDATE_GOLDENS') === '1') {
            if (!empty(getenv('CI'))) {
                $this->fail('UPDATE_GOLDENS must never run under CI - goldens are generated locally and inspected by hand.');
            }

            if (!is_dir(dirname($sPath))) {
                mkdir(dirname($sPath), 0775, true);
            }
            file_put_contents($sPath, $sJson);
        }

        self::assertFileExists($sPath, "Golden '{$sName}' missing - generate once with UPDATE_GOLDENS=1 and inspect the diff by hand.");
        self::assertSame(
            file_get_contents($sPath),
            $sJson,
            "Pipeline output diverged from golden '{$sName}' - behavior changed."
        );
    }

    /**
     * Sort keys recursively so goldens are byte-stable regardless of insertion order.
     *
     * @param array $arData
     * @return array
     */
    protected function ksortRecursive(array $arData): array
    {
        ksort($arData);
        foreach ($arData as $mKey => $mValue) {
            if (is_array($mValue)) {
                $arData[$mKey] = $this->ksortRecursive($mValue);
            }
        }

        return $arData;
    }
}
