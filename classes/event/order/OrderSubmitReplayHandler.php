<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Throwable;
use Kharanenka\Helper\Result;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Lovata\Shopaholic\Models\Settings;
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
 * (aborted request, or a throw after the commit). The placed order is recorded on the
 * cart and the cart is emptied in the same transaction as the order, so a submit that
 * finds the cart empty within the replay window redirects to that order's page instead
 * of placing a second one.
 *
 * Triggers:
 * - shopaholic.order.after_create: the checkout order waits for its commit
 * - TransactionCommitting on the order's connection: record, then clear the cart unless
 *   disable_cart_clear_on_new_order; a failure rolls the order back and rethrows
 * - The outermost TransactionRolledBack drops the waiting order
 * - shopaholic.order.get_redirect_url: the replay answer
 */
class OrderSubmitReplayHandler
{
    /** @var Order|null checkout order between after_create and its commit */
    private ?Order $obPendingOrder = null;

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     * @return void
     */
    public function subscribe($obEvent): void
    {
        // Fired inside the checkout transaction, only by OrderProcessor::create
        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, function ($obOrder): void {
            $this->obPendingOrder = $obOrder instanceof Order ? $obOrder : null;
        });

        // Connection events reach priority 0 listeners only
        $obEvent->listen(TransactionCommitting::class, function (TransactionCommitting $obCommitting): void {
            $this->settlePendingOrder($obCommitting);
        });

        $obEvent->listen(TransactionRolledBack::class, function (TransactionRolledBack $obRolledBack): void {
            $this->dropPendingOrderOnOuterRollback($obRolledBack);
        });

        // Fired with halt = true: the first non-null answer becomes the redirect URL
        $obEvent->listen(OrderProcessor::EVENT_ORDER_GET_REDIRECT_URL, function ($obOrder = null) {
            return $this->getReplayUrl($obOrder);
        });
    }

    /**
     * Record the pending order on the cart and empty the cart before the order commits.
     * @param TransactionCommitting $obCommitting
     * @return void
     * @throws Throwable when the record or the clear fails, after rolling the order back
     */
    protected function settlePendingOrder(TransactionCommitting $obCommitting): void
    {
        $obOrder = $this->obPendingOrder;
        if (empty($obOrder) || $obCommitting->connectionName !== $obOrder->getConnection()->getName()) {
            return;
        }

        $this->obPendingOrder = null;

        $obCartProcessor = CartProcessor::instance();
        $obCart = $obCartProcessor->getCartObject();
        if (empty($obCart)) {
            return;
        }

        try {
            PlacedOrderMemory::remember($obCart, $obOrder);
            if (!Settings::get('disable_cart_clear_on_new_order')) {
                $obCartProcessor->clear();
            }
        } catch (Throwable $obException) {
            // Laravel leaves the transaction open when a committing listener throws. Roll back
            // before logging: log_events writes the line to system_event_logs on this connection.
            $obCommitting->connection->rollBack();
            Log::error('Checkout commit aborted for order '.$obOrder->id.': '.$obException->getMessage());
            throw $obException;
        }
    }

    /**
     * A savepoint rollback fires the same event and keeps the pending order.
     * @param TransactionRolledBack $obRolledBack
     * @return void
     */
    protected function dropPendingOrderOnOuterRollback(TransactionRolledBack $obRolledBack): void
    {
        if ($obRolledBack->connection->transactionLevel() !== 0) {
            return;
        }

        $this->obPendingOrder = null;
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
