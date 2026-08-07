<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

use Carbon\Carbon;
use Lovata\DiscountsShopaholic\Models\Discount;

/**
 * Group E - applyOfferDiscount characterization (cases E1-E5).
 * Clock pinned to 2026-08-15 12:00:00 by the base case. Real-DB assertions
 * (Tiger rule - no mocks): the step CREATES/UPDATES Discount rows mid-import.
 */
class ImportDiscountCharacterizationTest extends PricingCharacterizationTestCase
{
    const EXTERNAL_ID = 'test-product-001#test-ext-001';

    const ISOLATION = [
        'import_vat_recalculate_enable'  => false,
        'import_currency_convert_enable' => false,
        'import_price_factor_enable'     => false,
    ];

    public function testE1CreatesPercentDiscountRowAndAppliesIt(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id'       => self::EXTERNAL_ID,
            'price'             => 100,
            'discount_value'    => 30,
            'discount_name'     => 'Aug sale',
            'discount_date_end' => '2026-09-10',
        ]);

        self::assertEquals(70.00, $arResult['price']);
        self::assertEquals(100.0, $arResult['old_price']);
        self::assertSame(Discount::PERCENT_TYPE, $arResult['discount_type']);
        self::assertEquals(30, $arResult['discount_value']);
        self::assertArrayHasKey('discount_id', $arResult);
        self::assertArrayNotHasKey('discount_name', $arResult);
        self::assertArrayNotHasKey('discount_date_end', $arResult);

        $obDiscount = Discount::find($arResult['discount_id']);
        self::assertNotNull($obDiscount);
        self::assertSame('Aug sale', $obDiscount->name);
        self::assertEquals(30, $obDiscount->discount_value);
        self::assertSame(Discount::PERCENT_TYPE, $obDiscount->discount_type);
        self::assertSame('2026-08-01 00:00:00', $obDiscount->date_begin->toDateTimeString());
        self::assertSame('2026-09-30 23:59:59', $obDiscount->date_end->toDateTimeString());
    }

    public function testE2ReusesExistingRowMatchedByValueAndEndMonth(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $obExisting = Discount::create([
            'active'         => true,
            'name'           => 'Old name',
            'discount_value' => 30,
            'discount_type'  => Discount::PERCENT_TYPE,
            'date_begin'     => Carbon::parse('2026-07-01'),
            'date_end'       => Carbon::parse('2026-09-15 00:00:00'),
        ]);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id'       => self::EXTERNAL_ID,
            'price'             => 100,
            'discount_value'    => 30,
            'discount_name'     => 'Aug sale',
            'discount_date_end' => '2026-09-10',
        ]);

        self::assertEquals($obExisting->id, $arResult['discount_id']);
        self::assertSame(1, Discount::count());

        $obFresh = Discount::find($obExisting->id);
        self::assertSame('Aug sale', $obFresh->name);
        self::assertSame('2026-09-30 23:59:59', $obFresh->date_end->toDateTimeString());
    }

    public function testE3ValueConditionSuppressesDiscountAndStripsKeys(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id'       => self::EXTERNAL_ID,
            'price'             => 100,
            'discount_value'    => 30,
            'discount_name'     => 'Aug sale',
            'discount_date_end' => '2026-09-10',
            'value_condition'   => '>10',
        ]);

        self::assertEquals(100, $arResult['price']);
        self::assertArrayNotHasKey('discount_id', $arResult);
        self::assertArrayNotHasKey('discount_value', $arResult);
        self::assertArrayNotHasKey('discount_name', $arResult);
        self::assertArrayNotHasKey('discount_date_end', $arResult);
        self::assertArrayNotHasKey('value_condition', $arResult);
        self::assertSame(0, Discount::count());
    }

    public function testE4ArrayShapedKeysResolveViaLatestDate(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id'       => self::EXTERNAL_ID,
            'price'             => 100,
            'discount_value'    => ['10', '30'],
            'discount_name'     => ['A', 'B'],
            'discount_date_end' => ['2026-09-10', '2026-10-05'],
        ]);

        // arsort picks '2026-10-05' (key 1); name/value resolved from the same key
        self::assertEquals(70.00, $arResult['price']);
        self::assertEquals(30, $arResult['discount_value']);

        $obDiscount = Discount::find($arResult['discount_id']);
        self::assertSame('B', $obDiscount->name);
        self::assertSame('2026-10-31 23:59:59', $obDiscount->date_end->toDateTimeString());
    }

    public function testE5MissingDateEndMeansNoDiscount(): void
    {
        PricingSchemaSeeder::seedStandard();
        PricingSettingsFixture::apply(self::ISOLATION);

        $arResult = PricingPipelineHarness::dispatchExtendImportData([
            'external_id'    => self::EXTERNAL_ID,
            'price'          => 100,
            'discount_value' => 30,
            'discount_name'  => 'Aug sale',
        ]);

        self::assertEquals(100, $arResult['price']);
        self::assertArrayNotHasKey('discount_id', $arResult);
        self::assertArrayNotHasKey('discount_value', $arResult);
        self::assertSame(0, Discount::count());
    }
}
