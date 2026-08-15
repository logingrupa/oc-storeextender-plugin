<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\MarginValueHandler;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * The Meta value rule for every funnel event: the buyer's gross price with
 * VAT stripped, minus the izpl cost (price type 3, stored without VAT),
 * per unit, times quantity. Fail-safe by design: an unresolvable cost, a
 * foreign content id or a zero sales value leaves the payload untouched,
 * and the margin can never go below zero.
 */
class MarginValueTest extends TestCase
{
    /**
     * Handler with the three DB touchpoints stubbed.
     * @param array $arOrderItems what readOrderItems should answer
     * @param array $arCostMap    offer id -> izpl cost
     * @param array $arTaxMap     offer id -> tax percent (default 21)
     * @return MarginValueHandler
     */
    protected function makeHandler(array $arOrderItems = [], array $arCostMap = [], array $arTaxMap = [])
    {
        return new class($arOrderItems, $arCostMap, $arTaxMap) extends MarginValueHandler
        {
            private $arStubOrderItems;
            private $arStubCostMap;
            private $arStubTaxMap;

            public function __construct(array $arOrderItems, array $arCostMap, array $arTaxMap)
            {
                $this->arStubOrderItems = $arOrderItems;
                $this->arStubCostMap = $arCostMap;
                $this->arStubTaxMap = $arTaxMap;
            }

            protected function readOrderItems(Order $obOrder): array
            {
                return $this->arStubOrderItems;
            }

            protected function izplCost(int $iOfferId): float
            {
                return $this->arStubCostMap[$iOfferId] ?? 0.0;
            }

            protected function taxPercentForOffer(int $iOfferId): float
            {
                return $this->arStubTaxMap[$iOfferId] ?? 21.0;
            }
        };
    }

    protected function buildPayload(float $fValue, array $arCustomExtra = []): array
    {
        return ['data' => [[
            'event_id'    => 'uuid-1',
            'event_time'  => 1,
            'custom_data' => array_merge(['value' => $fValue, 'currency' => 'EUR'], $arCustomExtra),
        ]]];
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

    public function testPurchaseValueBecomesNetMinusCostPerPosition()
    {
        // 16.90 gross at 21% VAT = 13.9669 net, minus izpl 5.12 = 8.85
        $obHandler = $this->makeHandler([
            ['gross' => 16.90, 'tax' => 21.0, 'cost' => 5.12, 'quantity' => 1],
        ]);
        $arResult = $obHandler->applyMarginValue('Purchase', $this->buildPayload(16.90), $this->makeOrder());

        $this->assertSame(8.85, $arResult['data'][0]['custom_data']['value']);
    }

    public function testViewContentSingleOfferUsesValueAsUnitPrice()
    {
        // 6.98 gross at 21% = 5.7686 net, minus izpl 3.98 = 1.79
        $obHandler = $this->makeHandler([], [618 => 3.98]);
        $arPayload = $this->buildPayload(6.98, ['content_ids' => ['SKU-214-618']]);
        $arResult = $obHandler->applyMarginValue('ViewContent', $arPayload, new \stdClass());

        $this->assertSame(1.79, $arResult['data'][0]['custom_data']['value']);
    }

    public function testAddToCartContentsRowsMultiplyByQuantity()
    {
        // (11.50 / 1.21 - 4.00) * 2 = 11.01
        $obHandler = $this->makeHandler([], [10 => 4.00]);
        $arPayload = $this->buildPayload(23.0, ['contents' => [
            ['id' => 'SKU-1-10', 'item_price' => 11.50, 'quantity' => 2],
        ]]);
        $arResult = $obHandler->applyMarginValue('AddToCart', $arPayload, new \stdClass());

        $this->assertSame(11.01, $arResult['data'][0]['custom_data']['value']);
    }

    public function testProductSpecificTaxRateWins()
    {
        // 11.20 gross at the product's 12% = 10.00 net, minus 5.00 = 5.00
        $obHandler = $this->makeHandler([], [7 => 5.00], [7 => 12.0]);
        $arPayload = $this->buildPayload(11.20, ['content_ids' => ['SKU-2-7']]);
        $arResult = $obHandler->applyMarginValue('ViewContent', $arPayload, new \stdClass());

        $this->assertSame(5.0, $arResult['data'][0]['custom_data']['value']);
    }

    public function testUnresolvableCostLeavesRevenueUntouched()
    {
        $obHandler = $this->makeHandler([], []);
        $arPayload = $this->buildPayload(6.98, ['content_ids' => ['SKU-214-618']]);
        $arResult = $obHandler->applyMarginValue('ViewContent', $arPayload, new \stdClass());

        $this->assertSame(6.98, $arResult['data'][0]['custom_data']['value']);
    }

    public function testForeignContentIdLeavesPayloadUntouched()
    {
        $obHandler = $this->makeHandler([], [10 => 4.00]);
        $arPayload = $this->buildPayload(9.99, ['content_ids' => ['GTIN-4711']]);

        $this->assertSame($arPayload, $obHandler->applyMarginValue('ViewContent', $arPayload, new \stdClass()));
    }

    public function testZeroSalesValueLeavesPayloadUntouched()
    {
        $arPayload = $this->buildPayload(0.0, ['content_ids' => ['SKU-214-618']]);
        $obHandler = $this->makeHandler([], [618 => 3.98]);

        $this->assertSame($arPayload, $obHandler->applyMarginValue('ViewContent', $arPayload, new \stdClass()));
    }

    public function testMarginNeverGoesNegative()
    {
        $obHandler = $this->makeHandler([
            ['gross' => 5.00, 'tax' => 21.0, 'cost' => 10.00, 'quantity' => 1],
        ]);
        $arResult = $obHandler->applyMarginValue('Purchase', $this->buildPayload(5.00), $this->makeOrder());

        $this->assertSame(0.0, $arResult['data'][0]['custom_data']['value']);
    }

    public function testPixelCustomDataGetsSameMarginAsCapi()
    {
        $obHandler = $this->makeHandler([], [618 => 3.98]);
        $arCustomData = ['value' => 6.98, 'currency' => 'EUR', 'content_ids' => ['SKU-214-618']];
        $arResult = $obHandler->applyMarginToCustomData('ViewContent', $arCustomData);

        $this->assertSame(1.79, $arResult['value']);
        $this->assertSame('EUR', $arResult['currency']);
    }

    public function testPixelPurchaseIsSkippedFrozenPayloadAlreadyCarriesMargin()
    {
        $obHandler = $this->makeHandler([], [618 => 3.98]);
        $arCustomData = ['value' => 8.85, 'content_ids' => ['SKU-214-618']];

        $this->assertSame($arCustomData, $obHandler->applyMarginToCustomData('Purchase', $arCustomData));
    }

    public function testEventIdAndTimeAreNeverTouched()
    {
        $obHandler = $this->makeHandler([
            ['gross' => 16.90, 'tax' => 21.0, 'cost' => 5.12, 'quantity' => 1],
        ]);
        $arResult = $obHandler->applyMarginValue('Purchase', $this->buildPayload(16.90), $this->makeOrder());

        $this->assertSame('uuid-1', $arResult['data'][0]['event_id']);
        $this->assertSame(1, $arResult['data'][0]['event_time']);
    }
}
