<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics\BrowserMarginValueHandler;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\MarginValueHandler;

/**
 * The GA4 browser value hook answers with the same offer-line margin Meta's
 * browser pixel gets: gross without the offer's VAT, minus the izpl cost,
 * times quantity, never below zero. Malformed lines are skipped; no line
 * left, or no known cost, leaves the GA4 plugin's default value in place.
 */
class BrowserMarginValueTest extends TestCase
{
    /**
     * Margin owner with the tax and izpl lookups read from per-offer maps.
     * @param array $arCostMap offer id -> izpl cost
     * @param array $arTaxMap  offer id -> tax percent (default 21)
     * @return MarginValueHandler
     */
    protected function makeMarginHandler(array $arCostMap, array $arTaxMap = [])
    {
        return new class($arCostMap, $arTaxMap) extends MarginValueHandler
        {
            private $arStubCostMap;
            private $arStubTaxMap;

            public function __construct(array $arCostMap, array $arTaxMap)
            {
                $this->arStubCostMap = $arCostMap;
                $this->arStubTaxMap = $arTaxMap;
            }

            protected function taxPercentForOffer(int $iOfferId): float
            {
                return $this->arStubTaxMap[$iOfferId] ?? 21.0;
            }

            protected function izplCost(int $iOfferId): float
            {
                return $this->arStubCostMap[$iOfferId] ?? 0.0;
            }
        };
    }

    /**
     * Listener subscribed to a real dispatcher, answering through the given margin owner.
     * @param MarginValueHandler $obMarginHandler
     * @return Dispatcher
     */
    protected function makeDispatcher(MarginValueHandler $obMarginHandler): Dispatcher
    {
        $obHandler = new class($obMarginHandler) extends BrowserMarginValueHandler
        {
            private $obStubMarginHandler;

            public function __construct(MarginValueHandler $obMarginHandler)
            {
                $this->obStubMarginHandler = $obMarginHandler;
            }

            protected function makeMarginValueHandler(): MarginValueHandler
            {
                return $this->obStubMarginHandler;
            }
        };

        $obDispatcher = new Dispatcher();
        $obHandler->subscribe($obDispatcher);

        return $obDispatcher;
    }

    /**
     * Dispatch the GA4 browser hook the way the GA4 plugin does.
     * @param array $arCostMap
     * @param mixed $mLineList
     * @return mixed
     */
    protected function askValue(array $arCostMap, $mLineList)
    {
        $obDispatcher = $this->makeDispatcher($this->makeMarginHandler($arCostMap));

        return $obDispatcher->dispatch('googleanalytics.browser.before_send', ['add_to_cart', $mLineList, 16.9, 'EUR'], true);
    }

    public function testOneLineIsNetMinusCost()
    {
        // 16.90 gross at 21% VAT = 13.9669 net, minus izpl 5.12 = 8.85
        $mResult = $this->askValue([12 => 5.12], [['offer_id' => 12, 'price' => 16.9, 'quantity' => 1]]);

        $this->assertSame(8.85, $mResult);
    }

    public function testQuantityMultipliesTheMargin()
    {
        $mResult = $this->askValue([12 => 5.12], [['offer_id' => 12, 'price' => 16.9, 'quantity' => 2]]);

        $this->assertSame(17.69, $mResult);
    }

    public function testTwoOffersSum()
    {
        // 8.8469 + (10.00 / 1.21 - 4.00 = 4.2645) = 13.11
        $mResult = $this->askValue([12 => 5.12, 13 => 4.00], [
            ['offer_id' => 12, 'price' => 16.9, 'quantity' => 1],
            ['offer_id' => 13, 'price' => 10.0, 'quantity' => 1],
        ]);

        $this->assertSame(13.11, $mResult);
    }

    public function testCostAboveNetGivesZero()
    {
        $mResult = $this->askValue([12 => 10.00], [['offer_id' => 12, 'price' => 5.0, 'quantity' => 1]]);

        $this->assertSame(0.0, $mResult);
    }

    public function testNoKnownCostGetsNoAnswer()
    {
        $mResult = $this->askValue([], [['offer_id' => 12, 'price' => 16.9, 'quantity' => 1]]);

        $this->assertNull($mResult);
    }

    public function testMalformedLinesAreSkipped()
    {
        $mResult = $this->askValue([12 => 5.12, 13 => 4.00, 14 => 4.00], [
            ['offer_id' => 12, 'price' => 16.9, 'quantity' => 1],
            ['offer_id' => 13, 'price' => 0.0, 'quantity' => 1],
            ['price' => 10.0, 'quantity' => 1],
            ['offer_id' => 14, 'price' => 10.0, 'quantity' => 0],
        ]);

        $this->assertSame(8.85, $mResult);
    }

    public function testAllLinesSkippedGetsNoAnswer()
    {
        $mResult = $this->askValue([12 => 5.12], [
            ['offer_id' => 0, 'price' => 16.9, 'quantity' => 1],
            ['offer_id' => 12, 'price' => -1.0, 'quantity' => 1],
            ['offer_id' => 12, 'price' => 16.9, 'quantity' => 0],
        ]);

        $this->assertNull($mResult);
    }

    public function testNonArrayLinesGetNoAnswer()
    {
        $this->assertNull($this->askValue([12 => 5.12], 'not-a-list'));
    }

    public function testMetaBrowserPixelGetsTheSameNumber()
    {
        $obMarginHandler = $this->makeMarginHandler([12 => 5.12]);

        $arCustomData = $obMarginHandler->applyMarginToCustomData('AddToCart', [
            'value'    => 16.9,
            'contents' => [['id' => 'SKU-1-12', 'item_price' => 16.9, 'quantity' => 1]],
        ]);
        $fLineMargin = $obMarginHandler->marginForOfferLines([['offer_id' => 12, 'price' => 16.9, 'quantity' => 1]]);

        $this->assertSame($arCustomData['value'], $fLineMargin);
    }

    public function testHookNameIsTheGoogleAnalyticsLiteral()
    {
        $this->assertSame('googleanalytics.browser.before_send', BrowserMarginValueHandler::HOOK_BEFORE_SEND);
    }
}
