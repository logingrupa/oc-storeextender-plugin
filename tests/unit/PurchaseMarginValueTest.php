<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics\PurchaseMarginValueHandler;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\MarginValueHandler;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * The GA4 purchase value hook answers with the same order margin Meta gets:
 * gross without the stamped VAT, minus the izpl cost, times quantity, never
 * below zero. No known cost, or a subject that is not an order, leaves the
 * GA4 plugin's default value in place (the listener answers null).
 */
class PurchaseMarginValueTest extends TestCase
{
    /**
     * Listener whose margin owner reads the given order items instead of the DB.
     * @param array $arOrderItems what readOrderItems should answer
     * @return PurchaseMarginValueHandler
     */
    protected function makeHandler(array $arOrderItems)
    {
        $obMarginHandler = new class($arOrderItems) extends MarginValueHandler
        {
            private $arStubOrderItems;

            public function __construct(array $arOrderItems)
            {
                $this->arStubOrderItems = $arOrderItems;
            }

            protected function readOrderItems(Order $obOrder): array
            {
                return $this->arStubOrderItems;
            }
        };

        return new class($obMarginHandler) extends PurchaseMarginValueHandler
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
    }

    /**
     * An Order instance without booting the model.
     * @return Order
     */
    protected function makeOrder(): Order
    {
        return (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
    }

    public function testOrderValueIsNetMinusCost()
    {
        // 16.90 gross at 21% VAT = 13.9669 net, minus izpl 5.12 = 8.85
        $obHandler = $this->makeHandler([
            ['gross' => 16.90, 'tax' => 21.0, 'cost' => 5.12, 'quantity' => 1],
        ]);

        $this->assertSame(8.85, $obHandler->valueFor($this->makeOrder(), 16.90, 'EUR'));
    }

    public function testNonOrderSubjectGetsNoAnswer()
    {
        $obHandler = $this->makeHandler([
            ['gross' => 16.90, 'tax' => 21.0, 'cost' => 5.12, 'quantity' => 1],
        ]);

        $this->assertNull($obHandler->valueFor(new \stdClass(), 16.90, 'EUR'));
    }

    public function testNoKnownCostGetsNoAnswer()
    {
        $obHandler = $this->makeHandler([
            ['gross' => 16.90, 'tax' => 21.0, 'cost' => 0.0, 'quantity' => 1],
        ]);

        $this->assertNull($obHandler->valueFor($this->makeOrder(), 16.90, 'EUR'));
    }

    public function testCostAboveNetGivesZero()
    {
        $obHandler = $this->makeHandler([
            ['gross' => 5.00, 'tax' => 21.0, 'cost' => 10.00, 'quantity' => 1],
        ]);

        $this->assertSame(0.0, $obHandler->valueFor($this->makeOrder(), 5.00, 'EUR'));
    }

    public function testDispatcherAnswersTheHookWithTheMargin()
    {
        $obHandler = $this->makeHandler([
            ['gross' => 16.90, 'tax' => 21.0, 'cost' => 5.12, 'quantity' => 1],
        ]);
        $obDispatcher = new Dispatcher();
        $obHandler->subscribe($obDispatcher);

        $mResult = $obDispatcher->dispatch('googleanalytics.purchase.before_send', [$this->makeOrder(), 16.90, 'EUR'], true);

        $this->assertSame(8.85, $mResult);
    }

    public function testHookNameIsTheGoogleAnalyticsLiteral()
    {
        $this->assertSame('googleanalytics.purchase.before_send', PurchaseMarginValueHandler::HOOK_BEFORE_SEND);
    }
}
