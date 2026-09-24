<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/ShippingLadderStubTables.php';
require_once __DIR__.'/../doubles/ShippingTypeStub.php';

use Illuminate\Support\Facades\DB;
use Logingrupa\StoreExtender\Classes\Shipping\ShippingMechanismCollector;
use Lovata\CampaignsShopaholic\Classes\Helper\CampaignHelper;
use Lovata\CampaignsShopaholic\Classes\Store\ShippingType\ListByCampaignStore;
use Lovata\CampaignsShopaholic\Classes\Store\ShippingTypeListStore as CampaignShippingTypeListStore;
use Lovata\OrdersShopaholic\Classes\Store\ShippingType\ActiveListStore;
use Lovata\OrdersShopaholic\Classes\Store\ShippingTypeListStore as OrderShippingTypeListStore;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\AbstractPromoMechanism;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\PositionTotalPriceGreater\PositionTotalPriceGreaterDiscountShippingPrice;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\WithoutConditionDiscountShippingPrice;

/**
 * The collector must hand the ladder the same shipping mechanisms the cart
 * promo pass gets: active campaigns scoped by their shipping type pivot
 * (empty pivot = every active type) plus auto-add mechanisms on every
 * active type. Inactive campaigns, increase mechanisms (null through the
 * relation condition) and non-shipping mechanisms never land. Tables are
 * SQLite stubs per the local DESCRIBE.
 */
class ShippingMechanismCollectorTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const SHIPPING_PRICE_AT_SIXTY = PositionTotalPriceGreaterDiscountShippingPrice::class;
    const SHIPPING_FREE = WithoutConditionDiscountShippingPrice::class;
    const TOTAL_PRICE_AT_SIXTY = 'Lovata\OrdersShopaholic\Classes\PromoMechanism\PositionTotalPriceGreater\PositionTotalPriceGreaterDiscountTotalPrice';

    public function setUp(): void
    {
        parent::setUp();
        ShippingLadderStubTables::create();
        $this->forgetStoreSingletons();

        $this->insertShippingType(1, 'venipack', 6.90, true);
        $this->insertShippingType(2, 'localpickup-latvija', 0.00, true);
        $this->insertShippingType(3, 'omniva', 2.34, false);
        $this->insertShippingType(6, 'dpd-latvia', 4.00, true);
    }

    public function tearDown(): void
    {
        $this->forgetStoreSingletons();
        parent::tearDown();
    }

    /**
     * Toolbox stores keep the last ID list in the singleton (arCachedList),
     * which outlives the per-test app and its cache.
     * @return void
     */
    protected function forgetStoreSingletons()
    {
        CampaignHelper::forgetInstance();
        CampaignShippingTypeListStore::forgetInstance();
        ListByCampaignStore::forgetInstance();
        OrderShippingTypeListStore::forgetInstance();
        ActiveListStore::forgetInstance();
    }

    public function testCampaignScopedToOneTypeLandsOnlyThere()
    {
        $this->insertMechanism(2, self::SHIPPING_PRICE_AT_SIXTY, ['amount' => '60']);
        $this->insertCampaign(1, 2, true, [6]);

        $arResult = ShippingMechanismCollector::collect();

        $this->assertSame([1, 2, 6], array_keys($arResult), 'one key per active type, the inactive omniva is absent');
        $this->assertSame([], $arResult[1]);
        $this->assertSame([], $arResult[2]);
        $this->assertCount(1, $arResult[6]);
        $this->assertInstanceOf(self::SHIPPING_PRICE_AT_SIXTY, $arResult[6][0]);
        $this->assertTrue($this->scopeAccepts($arResult[6][0], 6));
        $this->assertFalse($this->scopeAccepts($arResult[6][0], 1), 'the campaign scope callback must reject a type outside the pivot');
    }

    public function testCampaignWithEmptyPivotLandsOnEveryActiveType()
    {
        $this->insertMechanism(2, self::SHIPPING_PRICE_AT_SIXTY, ['amount' => '60']);
        $this->insertCampaign(1, 2, true, []);

        $arResult = ShippingMechanismCollector::collect();

        foreach ([1, 2, 6] as $iTypeId) {
            $this->assertCount(1, $arResult[$iTypeId], "type {$iTypeId}");
            $this->assertTrue($this->scopeAccepts($arResult[$iTypeId][0], $iTypeId));
        }
    }

    public function testAutoAddMechanismLandsOnEveryActiveType()
    {
        $this->insertMechanism(16, self::SHIPPING_FREE, ['shipping_type_id' => ['3']], false, true);

        $arResult = ShippingMechanismCollector::collect();

        foreach ([1, 2, 6] as $iTypeId) {
            $this->assertCount(1, $arResult[$iTypeId], "type {$iTypeId}");
            $this->assertInstanceOf(self::SHIPPING_FREE, $arResult[$iTypeId][0]);
            $this->assertTrue($this->scopeAccepts($arResult[$iTypeId][0], $iTypeId), 'auto-add carries a pass-through scope');
        }
    }

    public function testInactiveCampaignIncreaseMechanismAndNonShippingMechanismAreSkipped()
    {
        $this->insertMechanism(2, self::SHIPPING_PRICE_AT_SIXTY, ['amount' => '60']);
        $this->insertCampaign(5, 2, false, []);
        $this->insertMechanism(90, self::SHIPPING_FREE, [], true);
        $this->insertCampaign(4, 90, true, []);
        $this->insertMechanism(33, self::TOTAL_PRICE_AT_SIXTY, ['amount' => '60']);
        $this->insertCampaign(16, 33, true, []);
        $this->insertMechanism(91, self::TOTAL_PRICE_AT_SIXTY, ['amount' => '60'], false, true);

        $arResult = ShippingMechanismCollector::collect();

        $this->assertSame([1 => [], 2 => [], 6 => []], $arResult);
    }

    public function testEveryCollectedMechanismIsShippingType()
    {
        $this->insertMechanism(2, self::SHIPPING_PRICE_AT_SIXTY, ['amount' => '60']);
        $this->insertCampaign(1, 2, true, [6]);
        $this->insertMechanism(16, self::SHIPPING_FREE, [], false, true);

        foreach (ShippingMechanismCollector::collect() as $arMechanismList) {
            foreach ($arMechanismList as $obMechanism) {
                $this->assertSame(AbstractPromoMechanism::TYPE_SHIPPING, $obMechanism::getType());
            }
        }
    }

    /**
     * @param int    $iId
     * @param string $sCode
     * @param float  $fPrice
     * @param bool   $bActive
     * @return void
     */
    protected function insertShippingType($iId, $sCode, $fPrice, $bActive)
    {
        DB::table('lovata_orders_shopaholic_shipping_types')->insert([
            'id' => $iId, 'code' => $sCode, 'name' => ucfirst($sCode), 'price' => $fPrice, 'active' => $bActive, 'sort_order' => $iId,
        ]);
    }

    /**
     * @param int    $iId
     * @param string $sClass
     * @param array  $arProperty
     * @param bool   $bIncrease
     * @param bool   $bAutoAdd
     * @return void
     */
    protected function insertMechanism($iId, $sClass, array $arProperty, $bIncrease = false, $bAutoAdd = false)
    {
        DB::table('lovata_orders_shopaholic_promo_mechanism')->insert([
            'id' => $iId, 'name' => "mechanism {$iId}", 'type' => $sClass, 'increase' => $bIncrease, 'auto_add' => $bAutoAdd,
            'priority' => 1000, 'discount_value' => 100, 'discount_type' => 'percent', 'final_discount' => false,
            'property' => json_encode($arProperty),
        ]);
    }

    /**
     * @param int   $iId
     * @param int   $iMechanismId
     * @param bool  $bActive
     * @param int[] $arShippingTypeIdList
     * @return void
     */
    protected function insertCampaign($iId, $iMechanismId, $bActive, array $arShippingTypeIdList)
    {
        DB::table('lovata_campaigns_shopaholic_campaigns')->insert([
            'id' => $iId, 'name' => "campaign {$iId}", 'active' => $bActive, 'promo_mechanism_id' => $iMechanismId,
            'date_begin' => '2020-01-01 00:00:00', 'date_end' => null,
        ]);
        foreach ($arShippingTypeIdList as $iShippingTypeId) {
            DB::table('lovata_campaigns_shopaholic_campaign_shipping_type')->insert(['campaign_id' => $iId, 'shipping_type_id' => $iShippingTypeId]);
        }
    }

    /**
     * Run the scope callback the collector attached, as check() would.
     * @param AbstractPromoMechanism $obMechanism
     * @param int                    $iShippingTypeId
     * @return bool
     */
    protected function scopeAccepts($obMechanism, $iShippingTypeId)
    {
        $obProperty = new ReflectionProperty(AbstractPromoMechanism::class, 'callbackCheckShippingType');
        $obProperty->setAccessible(true);
        $fnCallback = $obProperty->getValue($obMechanism);

        return (bool) $fnCallback(new ShippingTypeStub($iShippingTypeId, 'stub', 1.0));
    }
}
