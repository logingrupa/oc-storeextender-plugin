<?php

require_once __DIR__.'/../../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../../doubles/TaxHelperDouble.php';
require_once __DIR__.'/../../doubles/ShippingTypeStub.php';

use Logingrupa\StoreExtender\Classes\Shipping\ShippingLadderProbe;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\WithoutConditionDiscountShippingPrice;

/**
 * The probe is what a shipping mechanism's check() reads when the ladder
 * replays it: the shipping type under test, no payment method, and a
 * position total equal to the subtotal asked about. The app boots because
 * the price containers reach Shopaholic Settings and TaxHelper; no plugin
 * table is created.
 */
class ShippingLadderProbeTest extends StoreExtenderPluginTestCase
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
        parent::tearDown();
    }

    public function testPositionTotalEqualsTheSubtotalGiven()
    {
        $obProbe = new ShippingLadderProbe(new ShippingTypeStub(6, 'DPD', 4.0), 12.5);

        $this->assertSame(12.5, $obProbe->getPositionTotalPrice()->price_value);
    }

    public function testShippingTypeIsTheOneGivenAndPaymentMethodIsNull()
    {
        $obType = new ShippingTypeStub(6, 'DPD', 4.0);
        $obProbe = new ShippingLadderProbe($obType, 0.0);

        $this->assertSame($obType, $obProbe->getShippingType());
        $this->assertNull($obProbe->getPaymentMethod());
        $this->assertSame([], $obProbe->getPositionList());
    }

    public function testNegativeSubtotalIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingLadderProbe(new ShippingTypeStub(6, 'DPD', 4.0), -0.01);
    }

    public function testSortByPriorityIsLovataOrderHighestFirst()
    {
        $obLow = new WithoutConditionDiscountShippingPrice(100, 1, 'fixed', false, [], false);
        $obHigh = new WithoutConditionDiscountShippingPrice(900, 1, 'fixed', false, [], false);
        $obProbe = new ShippingLadderProbe(new ShippingTypeStub(6, 'DPD', 4.0), 0.0);

        $this->assertSame([$obHigh, $obLow], $obProbe->sortByPriority([$obLow, $obHigh]));
    }
}
