<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Throwable;
use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\StoreExtender\Classes\Helper\OrderPageUrl;
use Logingrupa\StoreExtender\Classes\Helper\PlacedOrderMemory;

/**
 * Class OrderSubmitReplayHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * A checkout submit can place the order while its answer never reaches the browser
 * (aborted request). The cart is cleared by then, so the shopper's next submit used to
 * answer "Cart is empty". The placed order is remembered on the cart before the clear,
 * and a submit that finds the cart empty within the replay window redirects to that
 * order's page.
 */
class OrderSubmitReplayHandler
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     * @return void
     */
    public function subscribe($obEvent): void
    {
        // Fires after the commit and before CartProcessor::clear()
        $obEvent->listen(OrderProcessor::EVENT_ORDER_CREATED, function ($obOrder) {
            $this->rememberPlacedOrder($obOrder);
        });

        // Fired with halt = true: the first non-null answer becomes the redirect URL
        $obEvent->listen(OrderProcessor::EVENT_ORDER_GET_REDIRECT_URL, function ($obOrder = null) {
            return $this->getReplayUrl($obOrder);
        });
    }

    /**
     * @param Order|null $obOrder
     * @return void
     */
    protected function rememberPlacedOrder($obOrder): void
    {
        $obCart = CartProcessor::instance()->getCartObject();
        if (empty($obCart) || !$obOrder instanceof Order) {
            return;
        }

        // The order is already committed; a lost replay record must never fail it
        try {
            PlacedOrderMemory::remember($obCart, $obOrder);
        } catch (Throwable $obException) {
            Log::error('Placed order record failed for order '.$obOrder->id.': '.$obException->getMessage());
        }
    }

    /**
     * The placed order's page URL for a failed submit from an emptied cart, else null so
     * the normal answer goes out.
     * @param Order|null $obOrder
     * @return string|null
     */
    protected function getReplayUrl($obOrder): ?string
    {
        if (!empty($obOrder) || Result::status()) {
            return null;
        }

        $obCart = CartProcessor::instance()->getCartObject();
        if (empty($obCart) || CartPosition::getByCart($obCart->id)->exists()) {
            return null;
        }

        $obPlacedOrder = PlacedOrderMemory::recall($obCart);
        if (empty($obPlacedOrder)) {
            return null;
        }

        return OrderPageUrl::make($obPlacedOrder);
    }
}
