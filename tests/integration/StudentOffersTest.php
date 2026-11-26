<?php

declare(strict_types=1);

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Carbon\Carbon;
use Cms\Classes\Theme;
use Cms\Models\ThemeData;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\StoreExtender\Classes\StudentOffers\StudentOfferDefaults;
use Logingrupa\StoreExtender\Classes\StudentOffers\StudentOfferOptionsProvider;
use Logingrupa\StoreExtender\Classes\StudentOffers\StudentOfferSectionResolver;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Classes\Store\OfferListStore;
use Lovata\Shopaholic\Classes\Store\ProductListStore;
use Lovata\Toolbox\Classes\Item\ItemStorage;
use System\Classes\PluginManager;

class StudentOffersTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var array<int, string> */
    private array $arOriginalProductWith = [];

    /** @var array<int, string> */
    private array $arOriginalOfferWith = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->arOriginalProductWith = ProductItem::$arQueryWith;
        $this->arOriginalOfferWith = OfferItem::$arQueryWith;
        ProductItem::$arQueryWith = [];
        OfferItem::$arQueryWith = [];

        $this->createStubTables();
        $this->resetCaches();
    }

    public function tearDown(): void
    {
        ProductItem::$arQueryWith = $this->arOriginalProductWith;
        OfferItem::$arQueryWith = $this->arOriginalOfferWith;
        $this->resetCaches();
        parent::tearDown();
    }

    public function testFallbackUsesDefaultsOnlyOnLatvianStorefrontWhenKeyIsAbsent(): void
    {
        $this->seedProductWithOffers(212, true, [['id' => 1001, 'active' => true, 'name' => 'Remover']]);
        $this->resetThemeDataRow(null);

        $this->defineSites([['lv', true, true, 'lv']]);
        $arLvSections = StudentOfferSectionResolver::resolveForActiveTheme();
        $this->assertCount(1, $arLvSections);
        $this->assertSame('Starta komplekta produktiem -10%', $arLvSections[0]['title']);
        $this->assertCount(1, $arLvSections[0]['cards']);
        $this->assertSame('021.021', $arLvSections[0]['cards'][0]['coupon']);

        $this->defineSites([['nb-no', false, true, ''], ['lv', true, false, '']]);
        $arNoSections = StudentOfferSectionResolver::resolveForActiveTheme();
        $this->assertSame([], $arNoSections);
    }

    public function testExplicitlyClearedRepeaterDoesNotResurrectDefaults(): void
    {
        $this->seedProductWithOffers(212, true, [['id' => 1001, 'active' => true, 'name' => 'Remover']]);
        $this->defineSites([['lv', true, true, 'lv']]);

        foreach ([null, [], '[]'] as $mEmptyValue) {
            $this->resetThemeDataRow([StudentOfferDefaults::THEME_FIELD => $mEmptyValue]);
            $this->assertSame([], StudentOfferSectionResolver::resolveForActiveTheme());
        }
    }

    public function testEmptyCandidateIdsNeverHydrateEntireCatalog(): void
    {
        $this->seedProductWithOffers(500, true, [['id' => 5001, 'active' => true, 'name' => 'Unrelated Product']]);

        $arResult = StudentOfferSectionResolver::resolve([
            [
                'is_active' => true,
                'title' => 'Empty Section',
                'subtitle' => '',
                'coupon_group_ids' => [],
                'coupon_code' => 'ABC',
                'cards_per_row' => '5',
                'products' => [
                    ['product_id' => '', 'coupon_code' => '', 'is_hidden' => false],
                    ['product_id' => '0', 'coupon_code' => '', 'is_hidden' => false],
                ],
            ],
        ]);

        $this->assertSame([], $arResult);
    }

    public function testManualProductsRepeaterHandlesOverridesHiddenAndActiveFiltering(): void
    {
        $this->seedProductWithOffers(10, true, [
            ['id' => 101, 'active' => true, 'name' => 'Active Offer A'],
            ['id' => 102, 'active' => false, 'name' => 'Inactive Offer'],
        ]);
        $this->seedProductWithOffers(11, true, [
            ['id' => 111, 'active' => true, 'name' => 'Override Offer'],
        ]);
        $this->seedProductWithOffers(12, true, [
            ['id' => 121, 'active' => true, 'name' => 'Hidden Offer'],
        ]);
        $this->seedProductWithOffers(13, false, [
            ['id' => 131, 'active' => true, 'name' => 'Offer On Inactive Product'],
        ]);
        $this->seedProductWithOffers(14, true, [
            ['id' => 141, 'active' => true, 'name' => 'Offer On Deleted Product'],
        ], '2026-01-01 00:00:00');

        $arResult = StudentOfferSectionResolver::resolve([
            [
                'is_active' => '0',
                'title' => 'Disabled Section',
                'products' => [['product_id' => '10', 'coupon_code' => '', 'is_hidden' => false]],
            ],
            [
                'is_active' => '1',
                'title' => 'Active Section',
                'subtitle' => '<p>Watch <a href="https://onlinemanikirs.learnworlds.com/course" onclick="evil()">video</a><script>alert(1)</script><a href="javascript:alert(1)">bad</a></p>',
                'coupon_group_ids' => '',
                'coupon_code' => 'SHARED10',
                'cards_per_row' => '6',
                'products' => [
                    ['product_id' => '10', 'coupon_code' => '', 'is_hidden' => '0'],
                    ['product_id' => '11', 'coupon_code' => 'CUSTOM5', 'is_hidden' => '0'],
                    ['product_id' => '12', 'coupon_code' => '', 'is_hidden' => '1'],
                    ['product_id' => '13', 'coupon_code' => '', 'is_hidden' => '0'],
                    ['product_id' => '14', 'coupon_code' => '', 'is_hidden' => '0'],
                ],
            ],
        ]);

        $this->assertCount(1, $arResult);
        $this->assertSame('Active Section', $arResult[0]['title']);
        $this->assertSame(StudentOfferDefaults::CARD_CLASS_6, $arResult[0]['card_class']);
        $this->assertStringContainsString('https://onlinemanikirs.learnworlds.com/course', $arResult[0]['subtitle']);
        $this->assertStringNotContainsString('<script', $arResult[0]['subtitle']);
        $this->assertStringNotContainsString('onclick', $arResult[0]['subtitle']);
        $this->assertStringNotContainsString('javascript:', $arResult[0]['subtitle']);

        $arCards = $arResult[0]['cards'];
        $this->assertCount(2, $arCards);
        $this->assertSame(101, (int) $arCards[0]['offer']->id);
        $this->assertSame('SHARED10', $arCards[0]['coupon']);
        $this->assertSame(111, (int) $arCards[1]['offer']->id);
        $this->assertSame('CUSTOM5', $arCards[1]['coupon']);
    }

    public function testNoCouponGroupQueriesRunWhenSectionsHaveNoCouponGroups(): void
    {
        $this->seedProductWithOffers(20, true, [['id' => 201, 'active' => true, 'name' => 'Manual Item']]);

        $arCouponQueries = [];
        DB::listen(static function ($obQuery) use (&$arCouponQueries): void {
            if (str_contains($obQuery->sql, 'lovata_coupons_shopaholic')) {
                $arCouponQueries[] = $obQuery->sql;
            }
        });

        $arResult = StudentOfferSectionResolver::resolve([
            [
                'is_active' => true,
                'title' => 'Manual Only',
                'coupon_group_ids' => [],
                'coupon_code' => 'MAN1',
                'products' => [['product_id' => '20', 'coupon_code' => '', 'is_hidden' => false]],
            ],
        ]);

        $this->assertCount(1, $arResult);
        $this->assertSame([], $arCouponQueries);
    }

    public function testCouponGroupAutoLoadingMatchesCheckoutEligibilityAndPerOfferMerge(): void
    {
        $this->seedPromoMechanism(1, false);
        $this->seedPromoMechanism(2, true);

        $this->seedProductWithOffers(30, true, [
            ['id' => 301, 'active' => true, 'name' => 'Shade 1'],
            ['id' => 302, 'active' => true, 'name' => 'Shade 2'],
        ]);
        $this->seedProductWithOffers(31, true, [
            ['id' => 311, 'active' => true, 'name' => 'Lamp A'],
        ]);
        $this->seedProductWithOffers(32, true, [
            ['id' => 321, 'active' => true, 'name' => 'Hidden By Manual'],
        ]);

        // Group 1: active, valid mechanism, attached to offer 301 only, coupon max_usage=0 overrides group max_usage=1
        $this->seedCouponGroup(1, ['active' => true, 'promo_mechanism_id' => 1, 'max_usage' => 1]);
        $this->seedCoupon(10, 1, 'OFFER301', ['max_usage' => 0]);
        $this->seedCouponOrderUsage(10, 50, 99);
        DB::table('lovata_coupons_shopaholic_group_offer')->insert(['group_id' => 1, 'offer_id' => 301]);

        // Group 2: active, valid mechanism, attached to offer 302 only
        $this->seedCouponGroup(2, ['active' => true, 'promo_mechanism_id' => 1]);
        $this->seedCoupon(11, 2, 'OFFER302');
        DB::table('lovata_coupons_shopaholic_group_offer')->insert(['group_id' => 2, 'offer_id' => 302]);

        // Group 3: active, valid mechanism, attached to products 31 and 32
        $this->seedCouponGroup(3, ['active' => true, 'promo_mechanism_id' => 1]);
        $this->seedCoupon(12, 3, 'PROD31');
        DB::table('lovata_coupons_shopaholic_group_product')->insert([
            ['group_id' => 3, 'product_id' => 31],
            ['group_id' => 3, 'product_id' => 32],
        ]);

        // Group 4: increase=true mechanism (invalid for coupons) -> must be skipped
        $this->seedCouponGroup(4, ['active' => true, 'promo_mechanism_id' => 2]);
        $this->seedCoupon(13, 4, 'BADMECH');
        DB::table('lovata_coupons_shopaholic_group_product')->insert(['group_id' => 4, 'product_id' => 31]);

        // Group 5: exhausted for user 77 (max_usage_per_user=1) -> skipped for user 77, usable for user 88
        $this->seedCouponGroup(5, ['active' => true, 'promo_mechanism_id' => 1, 'max_usage_per_user' => 1]);
        $this->seedCoupon(14, 5, 'ONCEUSER');
        $this->seedCouponOrderUsage(14, 60, 77);
        $this->seedProductWithOffers(33, true, [['id' => 331, 'active' => true, 'name' => 'Per User Item']]);
        DB::table('lovata_coupons_shopaholic_group_product')->insert(['group_id' => 5, 'product_id' => 33]);

        // Group 6: expired -> must be skipped
        $this->seedCouponGroup(6, [
            'active' => true,
            'promo_mechanism_id' => 1,
            'date_end' => Carbon::now()->subDay()->toDateTimeString(),
        ]);
        $this->seedCoupon(15, 6, 'EXPIRED');
        $this->seedProductWithOffers(34, true, [['id' => 341, 'active' => true, 'name' => 'Expired Item']]);
        DB::table('lovata_coupons_shopaholic_group_product')->insert(['group_id' => 6, 'product_id' => 34]);

        $arConfig = [
            [
                'is_active' => true,
                'title' => 'Mixed Offers',
                'coupon_group_ids' => ['1', '2', '3', '4', '5', '6'],
                'coupon_code' => 'SECTIONFALLBACK',
                'products' => [
                    // Blank manual coupon preserves group coupon; override wins; is_hidden removes group product 32
                    ['product_id' => '31', 'coupon_code' => 'OVERRIDE31', 'is_hidden' => false],
                    ['product_id' => '32', 'coupon_code' => '', 'is_hidden' => true],
                ],
            ],
        ];

        $arForUser77 = StudentOfferSectionResolver::resolve($arConfig, 77);
        $this->assertCount(1, $arForUser77);
        $arMap77 = [];
        foreach ($arForUser77[0]['cards'] as $arCard) {
            $arMap77[(int) $arCard['offer']->id] = $arCard['coupon'];
        }
        $this->assertSame([
            301 => 'OFFER301',
            302 => 'OFFER302',
            311 => 'OVERRIDE31',
        ], $arMap77);

        $arForUser88 = StudentOfferSectionResolver::resolve($arConfig, 88);
        $arMap88 = [];
        foreach ($arForUser88[0]['cards'] as $arCard) {
            $arMap88[(int) $arCard['offer']->id] = $arCard['coupon'];
        }
        $this->assertArrayHasKey(331, $arMap88);
        $this->assertSame('ONCEUSER', $arMap88[331]);
    }

    public function testOptionsProviderMemoizesAndGuardsDisabledCouponsPlugin(): void
    {
        $this->seedProductWithOffers(40, true, [['id' => 401, 'active' => true, 'name' => 'Memo Product']]);
        $this->seedPromoMechanism(1, false);
        $this->seedCouponGroup(1, ['active' => true, 'name' => 'Starter -10%', 'promo_mechanism_id' => 1]);
        $this->seedCoupon(20, 1, '021.021');

        $iProductQueries = 0;
        DB::listen(static function ($obQuery) use (&$iProductQueries): void {
            if (str_contains($obQuery->sql, 'lovata_shopaholic_products')) {
                $iProductQueries++;
            }
        });

        $arFirst = StudentOfferOptionsProvider::getProductOptions();
        $arSecond = StudentOfferOptionsProvider::getProductOptions();
        $this->assertSame($arFirst, $arSecond);
        $this->assertSame(1, $iProductQueries);
        $this->assertStringContainsString('(#40)', $arFirst[40]);

        $arGroups = StudentOfferOptionsProvider::getCouponGroupOptions();
        $this->assertSame('Starter -10% (021.021)', $arGroups[1]);

        StudentOfferOptionsProvider::resetCache();
        $this->withDisabledPlugins(['Lovata.CouponsShopaholic'], function (): void {
            $this->assertSame([], StudentOfferOptionsProvider::getCouponGroupOptions());
        });
    }

    public function testSeedCommandDryRunGuardIdempotencyAndForce(): void
    {
        $sTheme = Theme::getActiveTheme()->getDirName();
        DB::table('cms_theme_data')->where('theme', $sTheme)->delete();

        // Non-LV skips without --force and creates zero rows
        $this->defineSites([['nb-no', false, true, ''], ['lv', true, false, '']]);
        $this->assertSame(0, Artisan::call('storeextender:seed-student-offers'));
        $this->assertNull(DB::table('cms_theme_data')->where('theme', $sTheme)->first());

        // LV --dry-run creates zero rows
        $this->defineSites([['lv', true, true, 'lv']]);
        $this->assertSame(0, Artisan::call('storeextender:seed-student-offers', ['--dry-run' => true]));
        $this->assertNull(DB::table('cms_theme_data')->where('theme', $sTheme)->first());

        // Pre-existing ThemeData settings (e.g. site_name) must survive seeding
        $this->resetThemeDataRow(['site_name' => 'NAI_S cosmetics']);

        // Real seed populates 2 sections while preserving site_name
        $this->assertSame(0, Artisan::call('storeextender:seed-student-offers'));
        $obRow = DB::table('cms_theme_data')->where('theme', $sTheme)->first();
        $this->assertNotNull($obRow);
        $arData = json_decode((string) $obRow->data, true);
        $this->assertSame('NAI_S cosmetics', $arData['site_name'] ?? null);
        $arDecoded = StudentOfferDefaults::decodeRepeaterValue($arData[StudentOfferDefaults::THEME_FIELD] ?? null);
        $this->assertIsArray($arDecoded);
        $this->assertCount(2, $arDecoded);

        // Explicitly cleared value is NOT overwritten without --force
        $this->resetThemeDataRow([StudentOfferDefaults::THEME_FIELD => null]);
        $this->assertSame(0, Artisan::call('storeextender:seed-student-offers'));
        $arAfterSkip = json_decode((string) DB::table('cms_theme_data')->where('theme', $sTheme)->value('data'), true);
        $this->assertNull($arAfterSkip[StudentOfferDefaults::THEME_FIELD]);

        // --force overwrites even explicitly cleared value
        $this->assertSame(0, Artisan::call('storeextender:seed-student-offers', ['--force' => true]));
        $arAfterForce = json_decode((string) DB::table('cms_theme_data')->where('theme', $sTheme)->value('data'), true);
        $arForcedDecoded = StudentOfferDefaults::decodeRepeaterValue($arAfterForce[StudentOfferDefaults::THEME_FIELD] ?? null);
        $this->assertIsArray($arForcedDecoded);
        $this->assertCount(2, $arForcedDecoded);
    }

    private function createStubTables(): void
    {
        if (! Schema::hasTable('lovata_shopaholic_products')) {
            Schema::create('lovata_shopaholic_products', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(true);
                $obTable->string('name')->nullable();
                $obTable->string('slug')->nullable();
                $obTable->string('code')->nullable();
                $obTable->integer('category_id')->nullable();
                $obTable->integer('brand_id')->nullable();
                $obTable->text('preview_text')->nullable();
                $obTable->text('description')->nullable();
                $obTable->string('external_id')->nullable();
                $obTable->timestamps();
                $obTable->softDeletes();
            });
        }

        if (! Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(true);
                $obTable->integer('product_id')->nullable();
                $obTable->string('name')->nullable();
                $obTable->string('code')->nullable();
                $obTable->integer('quantity')->default(10);
                $obTable->integer('sort_order')->default(0);
                $obTable->text('preview_text')->nullable();
                $obTable->text('description')->nullable();
                $obTable->string('external_id')->nullable();
                $obTable->timestamps();
                $obTable->softDeletes();
            });
        }

        if (! Schema::hasTable('lovata_shopaholic_currency')) {
            Schema::create('lovata_shopaholic_currency', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(true);
                $obTable->boolean('is_default')->default(true);
                $obTable->string('name')->default('EUR');
                $obTable->string('code')->default('EUR');
                $obTable->string('symbol')->default('€');
                $obTable->decimal('rate', 12, 6)->default(1.000000);
                $obTable->integer('sort_order')->default(1);
                $obTable->timestamps();
                $obTable->softDeletes();
            });
            DB::table('lovata_shopaholic_currency')->insert([
                'id' => 1,
                'active' => 1,
                'is_default' => 1,
                'name' => 'EUR',
                'code' => 'EUR',
                'symbol' => '€',
                'rate' => 1.0,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('lovata_shopaholic_prices')) {
            Schema::create('lovata_shopaholic_prices', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->string('item_type')->nullable();
                $obTable->integer('item_id')->nullable();
                $obTable->integer('price_type_id')->nullable();
                $obTable->decimal('price', 15, 2)->default(10.00);
                $obTable->decimal('old_price', 15, 2)->default(0.00);
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_orders_shopaholic_promo_mechanism')) {
            Schema::create('lovata_orders_shopaholic_promo_mechanism', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->string('name')->default('Promo');
                $obTable->string('type')->default('position_without_condition');
                $obTable->boolean('increase')->default(false);
                $obTable->boolean('auto_add')->default(false);
                $obTable->integer('priority')->default(1);
                $obTable->decimal('discount_value', 15, 2)->default(10.00);
                $obTable->string('discount_type')->default('percent');
                $obTable->boolean('final_discount')->default(false);
                $obTable->text('property')->nullable();
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_shopaholic_categories')) {
            Schema::create('lovata_shopaholic_categories', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(true);
                $obTable->string('name')->nullable();
                $obTable->string('slug')->nullable();
                $obTable->integer('nest_left')->nullable();
                $obTable->integer('nest_right')->nullable();
                $obTable->integer('nest_depth')->nullable();
                $obTable->integer('parent_id')->nullable();
                $obTable->timestamps();
                $obTable->softDeletes();
            });
        }

        if (! Schema::hasTable('lovata_shopaholic_additional_categories')) {
            Schema::create('lovata_shopaholic_additional_categories', static function (Blueprint $obTable): void {
                $obTable->integer('product_id');
                $obTable->integer('category_id');
            });
        }

        if (! Schema::hasTable('lovata_properties_shopaholic_value_link')) {
            Schema::create('lovata_properties_shopaholic_value_link', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->integer('property_id')->nullable();
                $obTable->integer('value_id')->nullable();
                $obTable->integer('product_id')->nullable();
                $obTable->integer('element_id')->nullable();
                $obTable->string('element_type')->nullable();
            });
        }

        if (! Schema::hasTable('lovata_coupons_shopaholic_coupon_groups')) {
            Schema::create('lovata_coupons_shopaholic_coupon_groups', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(true);
                $obTable->string('name')->default('Group');
                $obTable->timestamp('date_begin')->nullable();
                $obTable->timestamp('date_end')->nullable();
                $obTable->integer('promo_mechanism_id')->nullable();
                $obTable->integer('promo_block_id')->nullable();
                $obTable->integer('max_usage')->default(0);
                $obTable->integer('max_usage_per_user')->nullable();
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_coupons_shopaholic_coupons')) {
            Schema::create('lovata_coupons_shopaholic_coupons', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->integer('group_id');
                $obTable->string('code');
                $obTable->boolean('hidden')->default(false);
                $obTable->integer('user_id')->nullable();
                $obTable->integer('max_usage')->nullable();
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_coupons_shopaholic_group_product')) {
            Schema::create('lovata_coupons_shopaholic_group_product', static function (Blueprint $obTable): void {
                $obTable->integer('group_id');
                $obTable->integer('product_id');
            });
        }

        if (! Schema::hasTable('lovata_coupons_shopaholic_group_offer')) {
            Schema::create('lovata_coupons_shopaholic_group_offer', static function (Blueprint $obTable): void {
                $obTable->integer('group_id');
                $obTable->integer('offer_id');
            });
        }

        if (! Schema::hasTable('lovata_orders_shopaholic_orders')) {
            Schema::create('lovata_orders_shopaholic_orders', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->integer('user_id')->nullable();
                $obTable->string('order_number')->nullable();
                $obTable->timestamps();
            });
        }

        if (! Schema::hasTable('lovata_coupons_shopaholic_order_coupon')) {
            Schema::create('lovata_coupons_shopaholic_order_coupon', static function (Blueprint $obTable): void {
                $obTable->integer('coupon_id');
                $obTable->integer('order_id');
                $obTable->string('code')->nullable();
            });
        }

        if (! Schema::hasTable('rainlab_translate_attributes')) {
            Schema::create('rainlab_translate_attributes', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->string('locale')->index();
                $obTable->string('model_id')->nullable();
                $obTable->string('model_type')->nullable();
                $obTable->mediumText('attribute_data')->nullable();
            });
        }

        if (! Schema::hasTable('lovata_mighty_seo_params')) {
            Schema::create('lovata_mighty_seo_params', static function (Blueprint $obTable): void {
                $obTable->increments('id');
                $obTable->string('external_type')->nullable();
                $obTable->integer('external_id')->nullable();
                $obTable->timestamps();
            });
        }
    }

    /**
     * @param  list<array{id: int, active: bool, name: string}>  $arOffers
     */
    private function seedProductWithOffers(int $iProductId, bool $bActive, array $arOffers, ?string $sDeletedAt = null): void
    {
        DB::table('lovata_shopaholic_products')->insert([
            'id' => $iProductId,
            'active' => $bActive ? 1 : 0,
            'name' => 'Product '.$iProductId,
            'slug' => 'product-'.$iProductId,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => $sDeletedAt,
        ]);

        foreach ($arOffers as $arOffer) {
            DB::table('lovata_shopaholic_offers')->insert([
                'id' => $arOffer['id'],
                'active' => $arOffer['active'] ? 1 : 0,
                'product_id' => $iProductId,
                'name' => $arOffer['name'],
                'quantity' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('lovata_shopaholic_prices')->insert([
                'item_type' => \Lovata\Shopaholic\Models\Offer::class,
                'item_id' => $arOffer['id'],
                'price_type_id' => null,
                'price' => 19.90,
                'old_price' => 0.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->clearShopaholicStores();
    }

    private function seedPromoMechanism(int $iId, bool $bIncrease): void
    {
        DB::table('lovata_orders_shopaholic_promo_mechanism')->insert([
            'id' => $iId,
            'name' => 'Mechanism '.$iId,
            'increase' => $bIncrease ? 1 : 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $arOverrides
     */
    private function seedCouponGroup(int $iId, array $arOverrides = []): void
    {
        DB::table('lovata_coupons_shopaholic_coupon_groups')->insert(array_merge([
            'id' => $iId,
            'active' => 1,
            'name' => 'Group '.$iId,
            'date_begin' => Carbon::now()->subDay()->toDateTimeString(),
            'date_end' => null,
            'promo_mechanism_id' => 1,
            'max_usage' => 0,
            'max_usage_per_user' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $arOverrides));
    }

    /**
     * @param  array<string, mixed>  $arOverrides
     */
    private function seedCoupon(int $iId, int $iGroupId, string $sCode, array $arOverrides = []): void
    {
        DB::table('lovata_coupons_shopaholic_coupons')->insert(array_merge([
            'id' => $iId,
            'group_id' => $iGroupId,
            'code' => $sCode,
            'hidden' => 0,
            'user_id' => null,
            'max_usage' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $arOverrides));
    }

    private function seedCouponOrderUsage(int $iCouponId, int $iOrderId, int $iUserId): void
    {
        DB::table('lovata_orders_shopaholic_orders')->insert([
            'id' => $iOrderId,
            'user_id' => $iUserId,
            'order_number' => 'ORD-'.$iOrderId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lovata_coupons_shopaholic_order_coupon')->insert([
            'coupon_id' => $iCouponId,
            'order_id' => $iOrderId,
            'code' => 'USED',
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $arData
     */
    private function resetThemeDataRow(?array $arData): void
    {
        $sTheme = Theme::getActiveTheme()->getDirName();
        DB::table('cms_theme_data')->where('theme', $sTheme)->delete();

        if ($arData !== null) {
            DB::table('cms_theme_data')->insert([
                'theme' => $sTheme,
                'data' => json_encode($arData),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->clearThemeDataCaches($sTheme);
    }

    private function clearThemeDataCaches(string $sTheme): void
    {
        Cache::forget(ThemeData::getCacheKey($sTheme));
        $obProp = new ReflectionProperty(ThemeData::class, 'instances');
        $obProp->setAccessible(true);
        $obProp->setValue(null, []);
    }

    private function clearShopaholicStores(): void
    {
        ProductListStore::instance()->active->clear();
        OfferListStore::instance()->active->clear();
        ProductItem::clearActiveOfferMap();
        $obItemStore = new ReflectionProperty(ItemStorage::class, 'arItemStore');
        $obItemStore->setAccessible(true);
        $obItemStore->setValue(null, []);
    }

    private function resetCaches(): void
    {
        StudentOfferOptionsProvider::resetCache();
        $this->clearShopaholicStores();

        $obTheme = Theme::getActiveTheme();
        if ($obTheme) {
            $this->clearThemeDataCaches($obTheme->getDirName());
        }
    }

    /**
     * @param  list<string>  $arCodes
     */
    private function withDisabledPlugins(array $arCodes, callable $fnCallback): void
    {
        $obManager = PluginManager::instance();
        $obProp = new ReflectionProperty(PluginManager::class, 'disabledPlugins');
        $obProp->setAccessible(true);
        $arOriginal = (array) $obProp->getValue($obManager);

        try {
            $arDisabled = $arOriginal;
            foreach ($arCodes as $sCode) {
                $arDisabled[$obManager->normalizeIdentifier($sCode)] = true;
            }
            $obProp->setValue($obManager, $arDisabled);
            $fnCallback();
        } finally {
            $obProp->setValue($obManager, $arOriginal);
        }
    }
}
