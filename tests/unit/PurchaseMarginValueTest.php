<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\PurchaseMarginValueHandler;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * The Meta Purchase value rule: order sales total minus the summed izpl
 * (cost) prices - the margin the v1 site reported. Fail-safe by design:
 * an unresolvable cost or a zero sales value leaves the payload untouched,
 * and the margin can never go below zero.
 */
class PurchaseMarginValueTest extends TestCase
{
    /**
     * @param float $fCost what sumIzplCost should pretend the order costs
     * @return PurchaseMarginValueHandler
     */
    protected function makeHandlerWithCost(float $fCost)
    {
        return new class($fCost) extends PurchaseMarginValueHandler
        {
            private $fStubbedCost;

            public function __construct(float $fCost)
            {
                $this->fStubbedCost = $fCost;
            }

            protected function sumIzplCost(Order $obOrder): float
            {
                return $this->fStubbedCost;
            }
        };
    }

    protected function buildPayload(float $fValue): array
    {
        return ['data' => [['event_id' => 'uuid-1', 'event_time' => 1, 'custom_data' => ['value' => $fValue, 'currency' => 'EUR']]]];
    }

    /**
     * An Order instance without booting the model (the constructor pulls the
     * framework container, which a unit test does not have).
     * @return Order
     */
    protected function makeOrder(): Order
    {
        return (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
    }

    public function testValueBecomesSalesMinusCost()
    {
        $arResult = $this->makeHandlerWithCost(48.87)->applyMarginValue($this->buildPayload(75.0), $this->makeOrder());

        $this->assertSame(26.13, $arResult['data'][0]['custom_data']['value']);
    }

    public function testUnresolvableCostLeavesRevenueUntouched()
    {
        $arResult = $this->makeHandlerWithCost(0.0)->applyMarginValue($this->buildPayload(75.0), $this->makeOrder());

        $this->assertSame(75.0, $arResult['data'][0]['custom_data']['value']);
    }

    public function testZeroSalesValueLeavesPayloadUntouched()
    {
        $arPayload = $this->buildPayload(0.0);

        $this->assertSame($arPayload, $this->makeHandlerWithCost(10.0)->applyMarginValue($arPayload, $this->makeOrder()));
    }

    public function testMarginNeverGoesNegative()
    {
        $arResult = $this->makeHandlerWithCost(120.0)->applyMarginValue($this->buildPayload(75.0), $this->makeOrder());

        $this->assertSame(0.0, $arResult['data'][0]['custom_data']['value']);
    }

    public function testEventIdAndTimeAreNeverTouched()
    {
        $arResult = $this->makeHandlerWithCost(48.87)->applyMarginValue($this->buildPayload(75.0), $this->makeOrder());

        $this->assertSame('uuid-1', $arResult['data'][0]['event_id']);
        $this->assertSame(1, $arResult['data'][0]['event_time']);
    }
}
