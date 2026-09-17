<?php

require_once __DIR__.'/../../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../../doubles/TaxHelperDouble.php';
require_once __DIR__.'/../../doubles/ShippingTypeStub.php';

use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Shipping\ShippingLadder;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OfferQuantityGreater\OfferQuantityGreaterDiscountShippingPrice;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\OfferTotalQuantityGreater\OfferTotalQuantityGreaterDiscountShippingPrice;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\PositionCountGreater\PositionCountGreaterDiscountShippingPrice;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\PositionTotalPriceGreater\PositionTotalPriceGreaterDiscountShippingPrice;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\WithoutConditionDiscountShippingPrice;

/**
 * The ladder folds the real mechanism objects over a probe processor, so
 * the numbers below are what checkout charges for the same rows. Mechanisms
 * are built with the public constructor from the local rows of 2026-09-17:
 * mechanism 2 (100% at 60, campaign 1 on dpd-latvia) and mechanism 6
 * (1.00 fixed at 60, campaign 3 on venipack). Shipping types are strict
 * stubs: any read outside id, name, tax_percent and getFullPriceValue()
 * throws, because on a real item it would build the cart. The app boots
 * because calculateItemDiscount() reads Settings and TaxHelper; no plugin
 * table is created.
 */
class ShippingLadderTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        TaxHelperDouble::install();
    }

    public function tearDown(): void
    {
        TaxHelperDouble::reset();
        CartProcessor::forgetInstance();
        parent::tearDown();
    }

    public function testLocalCampaignsProduceTheTwoTiersAndAFreeTargetOfSixty()
    {
        $this->spyOnCartProcessor();
        $obCourier = new ShippingTypeStub(1, 'Courier', 6.90);
        $obDpd = new ShippingTypeStub(6, 'DPD', 4.00);

        $arLadder = ShippingLadder::make([$obCourier, $obDpd], [
            1 => [$this->positionTotalMechanism(1, 'fixed')],
            6 => [$this->positionTotalMechanism(100, 'percent')],
        ]);

        $this->assertSame([
            ['id' => 1, 'name' => 'Courier', 'base' => 6.9, 'tiers' => [['from' => 0.0, 'price' => 6.9], ['from' => 60.0, 'price' => 5.9]]],
            ['id' => 6, 'name' => 'DPD', 'base' => 4.0, 'tiers' => [['from' => 0.0, 'price' => 4.0], ['from' => 60.0, 'price' => 0.0]]],
        ], $arLadder);
        $this->assertSame(['name' => 'DPD', 'from' => 60.0], ShippingLadder::freeShipping($arLadder));
    }

    public function testZeroBasePickupIsNotFreeDelivery()
    {
        $obPickup = new ShippingTypeStub(2, 'Pickup', 0.00);

        $arLadder = ShippingLadder::make([$obPickup], [2 => [$this->positionTotalMechanism(100, 'percent')]]);

        $this->assertSame([['from' => 0.0, 'price' => 0.0]], $arLadder[0]['tiers']);
        $this->assertNull(ShippingLadder::freeShipping($arLadder));
    }

    public function testTypeWithoutMechanismsHasOneTierAtBase()
    {
        $arLadder = ShippingLadder::make([new ShippingTypeStub(5, 'Abroad', 24.00)], []);

        $this->assertSame([['from' => 0.0, 'price' => 24.0]], $arLadder[0]['tiers']);
        $this->assertNull(ShippingLadder::freeShipping($arLadder));
    }

    public function testFinalMechanismStopsTheFold()
    {
        $obFinalFixed = new WithoutConditionDiscountShippingPrice(1000, 1, 'fixed', true, [], false);
        $obLaterFull = new WithoutConditionDiscountShippingPrice(500, 100, 'percent', false, [], false);
        $obType = new ShippingTypeStub(6, 'DPD', 4.00);

        $arLadder = ShippingLadder::make([$obType], [6 => [$obLaterFull, $obFinalFixed]]);

        $this->assertSame([['from' => 0.0, 'price' => 3.0]], $arLadder[0]['tiers'], 'the final mechanism at higher priority ends the fold before the 100% one');
    }

    public function testPaymentGatedMechanismDoesNotApply()
    {
        $obGated = new WithoutConditionDiscountShippingPrice(1000, 100, 'percent', false, ['payment_method_id' => ['3']], false);

        $arLadder = ShippingLadder::make([new ShippingTypeStub(6, 'DPD', 4.00)], [6 => [$obGated]]);

        $this->assertSame([['from' => 0.0, 'price' => 4.0]], $arLadder[0]['tiers']);
    }

    public function testShippingTypeGatedMechanismAppliesOnlyToItsType()
    {
        $obOmnivaOnly = new WithoutConditionDiscountShippingPrice(900, 100, 'percent', false, ['shipping_type_id' => ['3']], false);
        $obOmniva = new ShippingTypeStub(3, 'Omniva', 2.34);
        $obDpd = new ShippingTypeStub(6, 'DPD', 4.00);

        $arLadder = ShippingLadder::make([$obOmniva, $obDpd], [3 => [$obOmnivaOnly], 6 => [$obOmnivaOnly]]);

        $this->assertSame([['from' => 0.0, 'price' => 0.0]], $arLadder[0]['tiers']);
        $this->assertSame([['from' => 0.0, 'price' => 4.0]], $arLadder[1]['tiers']);
    }

    public function testWithoutConditionFullDiscountYieldsOneFreeTier()
    {
        $obFree = new WithoutConditionDiscountShippingPrice(900, 100, 'percent', false, [], false);

        $arLadder = ShippingLadder::make([new ShippingTypeStub(6, 'DPD', 4.00)], [6 => [$obFree]]);

        $this->assertSame([['from' => 0.0, 'price' => 0.0]], $arLadder[0]['tiers']);
        $this->assertSame(['name' => 'DPD', 'from' => 0.0], ShippingLadder::freeShipping($arLadder));
    }

    public function testQuantityBasedMechanismsAreExcludedAndLoggedOncePerClass()
    {
        $arExcluded = [
            new OfferQuantityGreaterDiscountShippingPrice(1000, 100, 'percent', false, ['quantity' => 3], false),
            new OfferTotalQuantityGreaterDiscountShippingPrice(1000, 100, 'percent', false, ['quantity' => 3], false),
            new PositionCountGreaterDiscountShippingPrice(1000, 100, 'percent', false, ['count' => 3], false),
            new PositionCountGreaterDiscountShippingPrice(900, 100, 'percent', false, ['count' => 5], false),
        ];
        Log::shouldReceive('warning')->times(3)->withArgs(function ($sMessage) {
            return strpos($sMessage, 'ShippingPrice') !== false && strpos($sMessage, 'ShippingLadder') === 0;
        });

        $arLadder = ShippingLadder::make([new ShippingTypeStub(6, 'DPD', 4.00), new ShippingTypeStub(1, 'Courier', 6.90)], [6 => $arExcluded, 1 => $arExcluded]);

        $this->assertSame([['from' => 0.0, 'price' => 4.0]], $arLadder[0]['tiers']);
        $this->assertSame([['from' => 0.0, 'price' => 6.9]], $arLadder[1]['tiers']);
    }

    public function testBandsOrderGroupsCheapestFirstWithSingleTierTypesOnTheirOwn()
    {
        $arLadder = ShippingLadder::make(
            [new ShippingTypeStub(5, 'Abroad', 24.00), new ShippingTypeStub(1, 'Courier', 6.90), new ShippingTypeStub(6, 'DPD', 4.00), new ShippingTypeStub(2, 'Pickup', 0.00)],
            [1 => [$this->positionTotalMechanism(1, 'fixed')], 6 => [$this->positionTotalMechanism(100, 'percent')]]
        );

        $this->assertSame([
            ['from' => null, 'to' => null, 'rows' => [['name' => 'Pickup', 'price' => 0.0]]],
            ['from' => 60.0, 'to' => null, 'rows' => [['name' => 'DPD', 'price' => 0.0], ['name' => 'Courier', 'price' => 5.9]]],
            ['from' => 0.0, 'to' => 60.0, 'rows' => [['name' => 'DPD', 'price' => 4.0], ['name' => 'Courier', 'price' => 6.9]]],
            ['from' => null, 'to' => null, 'rows' => [['name' => 'Abroad', 'price' => 24.0]]],
        ], ShippingLadder::bands($arLadder));
    }

    public function testBandsWithTwoThresholdsCarryTheMiddleIntervalCheapestFirst()
    {
        $arLadder = ShippingLadder::make(
            [new ShippingTypeStub(1, 'Courier', 6.90)],
            [1 => [$this->positionTotalMechanism(1, 'fixed', 40), $this->positionTotalMechanism(100, 'percent', 60)]]
        );

        $arGroups = ShippingLadder::bands($arLadder);

        $this->assertSame([[60.0, null], [40.0, 60.0], [0.0, 40.0]], array_map(function ($arGroup) {
            return [$arGroup['from'], $arGroup['to']];
        }, $arGroups));
        $this->assertSame([0.0, 5.9, 6.9], array_map(function ($arGroup) {
            return $arGroup['rows'][0]['price'];
        }, $arGroups));
    }

    public function testBandsWithoutThresholdsListEverySingleTierTypeByPrice()
    {
        $arLadder = ShippingLadder::make([new ShippingTypeStub(5, 'Abroad', 24.00), new ShippingTypeStub(2, 'Pickup', 0.00)], []);

        $this->assertSame([
            ['from' => null, 'to' => null, 'rows' => [['name' => 'Pickup', 'price' => 0.0]]],
            ['from' => null, 'to' => null, 'rows' => [['name' => 'Abroad', 'price' => 24.0]]],
        ], ShippingLadder::bands($arLadder));
    }

    public function testDistinctThresholdsBecomeAscendingTiersAndEqualPricesCollapse()
    {
        $obAtForty = $this->positionTotalMechanism(1, 'fixed', 40);
        $obAtSixty = $this->positionTotalMechanism(100, 'percent', 60);
        $obDuplicateSixty = $this->positionTotalMechanism(100, 'percent', 60);

        $arLadder = ShippingLadder::make([new ShippingTypeStub(1, 'Courier', 6.90)], [1 => [$obAtSixty, $obDuplicateSixty, $obAtForty]]);

        $this->assertSame([
            ['from' => 0.0, 'price' => 6.9],
            ['from' => 40.0, 'price' => 5.9],
            ['from' => 60.0, 'price' => 0.0],
        ], $arLadder[0]['tiers']);
    }

    /**
     * Mechanism 2 / 6 shape: PositionTotalPriceGreater on the shipping price.
     * @param float  $fDiscountValue
     * @param string $sDiscountType
     * @param int    $iAmount
     * @return PositionTotalPriceGreaterDiscountShippingPrice
     */
    protected function positionTotalMechanism($fDiscountValue, $sDiscountType, $iAmount = 60)
    {
        $arProperty = ['amount' => (string) $iAmount, 'quantity_limit' => '', 'quantity_limit_from' => '', 'shipping_type_id' => '', 'payment_method_id' => ''];

        return new PositionTotalPriceGreaterDiscountShippingPrice(1000, $fDiscountValue, $sDiscountType, false, $arProperty, false);
    }

    /**
     * Any call on CartProcessor during the ladder is a cart build, so the
     * singleton is replaced by an object that throws on every method.
     * @return void
     */
    protected function spyOnCartProcessor()
    {
        $obSpy = new class {
            public function __call($sMethod, $arArgumentList)
            {
                throw new LogicException("CartProcessor::{$sMethod}() was reached from the shipping ladder");
            }
        };

        $obProperty = new ReflectionProperty(CartProcessor::class, 'instance');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, $obSpy);
    }
}
