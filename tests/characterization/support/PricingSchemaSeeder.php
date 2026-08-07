<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Lovata\DiscountsShopaholic\Models\Discount;

/**
 * Seeds the hermetic Lovata pricing schema with the exact .no shape.
 *
 * Price type IDs are an INVARIANT: the pipeline hardcodes reads from price_list.2/.3/.1
 * but writes to price_list.{findByCode(...)->id} - production .no semantics require
 * id=1 vairum, id=2 salona, id=3 izpl, id=4 authorized, id=6 regular.
 *
 * Products/offers are seeded via raw DB inserts, NOT Offer::create - Offer::afterSave()
 * unconditionally calls savePriceValue() which writes lovata_shopaholic_prices, a table
 * this hermetic schema deliberately does not build.
 */
class PricingSchemaSeeder
{
    const PRODUCT_EXTERNAL_ID = 'test-product-001';
    const OFFER_EXTERNAL_ID = 'test-ext-001';

    /**
     * Seed the five .no price types with exact IDs and codes (all active).
     * @return void
     */
    public static function seedPriceTypes(): void
    {
        $sNow = Carbon::now()->toDateTimeString();
        $arRowList = [
            ['id' => 1, 'code' => 'vairum', 'name' => 'Vairumtirgotaji'],
            ['id' => 2, 'code' => 'salona', 'name' => 'Saloni'],
            ['id' => 3, 'code' => 'izpl', 'name' => 'Izplatitaji'],
            ['id' => 4, 'code' => 'authorized', 'name' => 'Authorized'],
            ['id' => 6, 'code' => 'regular', 'name' => 'Regular'],
        ];

        foreach ($arRowList as $iIndex => $arRow) {
            DB::table('lovata_shopaholic_price_types')->insert([
                'id'         => $arRow['id'],
                'code'       => $arRow['code'],
                'name'       => $arRow['name'],
                'active'     => 1,
                'sort_order' => $iIndex + 1,
                'created_at' => $sNow,
                'updated_at' => $sNow,
            ]);
        }
    }

    /**
     * Seed the global 25% tax (id=1) the default VAT mapping row targets.
     * @return void
     */
    public static function seedGlobalTax(): void
    {
        DB::table('lovata_shopaholic_taxes')->insert([
            'id'         => 1,
            'name'       => '25% NO VAT',
            'percent'    => 25,
            'active'     => 1,
            'is_global'  => 1,
            'sort_order' => 1,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /**
     * Seed a non-global tax (for the reverse VAT mapping lookup, case A3).
     *
     * @param int   $iTaxId
     * @param float $fPercent
     * @return void
     */
    public static function seedNonGlobalTax(int $iTaxId, float $fPercent): void
    {
        DB::table('lovata_shopaholic_taxes')->insert([
            'id'         => $iTaxId,
            'name'       => 'Tax ' . $iTaxId,
            'percent'    => $fPercent,
            'active'     => 1,
            'is_global'  => 0,
            'sort_order' => $iTaxId,
            'created_at' => Carbon::now()->toDateTimeString(),
            'updated_at' => Carbon::now()->toDateTimeString(),
        ]);
    }

    /**
     * Link a product to a tax via the pivot table.
     *
     * @param int $iTaxId
     * @param int $iProductId
     * @return void
     */
    public static function linkProductToTax(int $iTaxId, int $iProductId): void
    {
        DB::table('lovata_shopaholic_tax_product_link')->insert([
            'tax_id'     => $iTaxId,
            'product_id' => $iProductId,
        ]);
    }

    /**
     * Seed the standard active Product + Offer pair.
     * @return array{0: int, 1: int} [product id, offer id]
     */
    public static function seedProductAndOffer(): array
    {
        $sNow = Carbon::now()->toDateTimeString();

        $iProductId = (int) DB::table('lovata_shopaholic_products')->insertGetId([
            'name'        => 'Test product',
            'slug'        => self::PRODUCT_EXTERNAL_ID,
            'external_id' => self::PRODUCT_EXTERNAL_ID,
            'active'      => 1,
            'created_at'  => $sNow,
            'updated_at'  => $sNow,
        ]);

        $iOfferId = (int) DB::table('lovata_shopaholic_offers')->insertGetId([
            'name'        => 'Test offer',
            'product_id'  => $iProductId,
            'external_id' => self::OFFER_EXTERNAL_ID,
            'active'      => 1,
            'created_at'  => $sNow,
            'updated_at'  => $sNow,
        ]);

        return [$iProductId, $iOfferId];
    }

    /**
     * Seed the 'authorized' PERCENT 20 discount (Discount::create so Validation,
     * Sortable, TraitCached and the TranslatableModel behavior all run - the smoke
     * test pins that this works on the hermetic schema).
     *
     * @return Discount
     */
    public static function seedAuthorizedDiscount(): Discount
    {
        return Discount::create([
            'active'         => true,
            'name'           => 'Authorized -20%',
            'code'           => 'authorized',
            'discount_value' => 20,
            'discount_type'  => Discount::PERCENT_TYPE,
            'date_begin'     => Carbon::now()->startOfMonth(),
        ]);
    }

    /**
     * Seed the 'regular' PERCENT 10 discount.
     * @return Discount
     */
    public static function seedRegularDiscount(): Discount
    {
        return Discount::create([
            'active'         => true,
            'name'           => 'Regular -10%',
            'code'           => 'regular',
            'discount_value' => 10,
            'discount_type'  => Discount::PERCENT_TYPE,
            'date_begin'     => Carbon::now()->startOfMonth(),
        ]);
    }

    /**
     * Attach a product to a discount via the product pivot.
     *
     * @param Discount $obDiscount
     * @param int      $iProductId
     * @return void
     */
    public static function attachProductToDiscount(Discount $obDiscount, int $iProductId): void
    {
        $obDiscount->product()->attach($iProductId);
    }

    /**
     * Standard full seed used by most golden cases: price types, global tax,
     * product + offer.
     * @return array{0: int, 1: int} [product id, offer id]
     */
    public static function seedStandard(): array
    {
        self::seedPriceTypes();
        self::seedGlobalTax();

        return self::seedProductAndOffer();
    }
}
