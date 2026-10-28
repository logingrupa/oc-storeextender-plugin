<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Throwable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\StoreExtender\Classes\Helper\OrderStockHolds;
use Logingrupa\StoreExtender\Classes\Helper\StockHoldPolicy;

/**
 * Class OrderStockHoldHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * Gives offer stock back when an order is canceled (OrderStockHolds).
 *
 * Triggers:
 * - shopaholic.order.before_create: the stock each cart offer has before Lovata decrements it
 * - shopaholic.order.after_create: record what the checkout took, inside its transaction
 *   (a failed checkout rolls the record back with it)
 * - Order afterSave: reconcile the holds with the status; afterDelete: give back what was held
 */
class OrderStockHoldHandler
{
    /** @var array|null offer id => quantity, between before_create and after_create */
    private ?array $arQuantityBefore = null;

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     * @return void
     */
    public function subscribe($obEvent): void
    {
        // Fired with halt = true, so the listener must never return a value
        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_BEFORE_CREATE, function (): void {
            $this->arQuantityBefore = StockHoldPolicy::isEnabled() ? $this->readCartOfferQuantityList() : null;
        });

        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, function ($obOrder): void {
            $arQuantityBefore = $this->arQuantityBefore;
            $this->arQuantityBefore = null;
            if ($arQuantityBefore !== null && $obOrder instanceof Order) {
                OrderStockHolds::record($obOrder, $arQuantityBefore);
            }
        });

        Order::extend(function (Order $obOrder) {
            $obOrder->bindEvent('model.afterSave', function () use ($obOrder) {
                $this->runStockMove($obOrder, fn () => OrderStockHolds::reconcile((int) $obOrder->id));
            });
            $obOrder->bindEvent('model.afterDelete', function () use ($obOrder) {
                $this->runStockMove($obOrder, fn () => OrderStockHolds::releaseDeleted((int) $obOrder->id));
            });
        });
    }

    /**
     * The quantity Lovata will decrement from. Its processor reads the offer model through the
     * cart position's OfferItem: the same memoized model when the item was built from the
     * database, else a fresh read in this transaction's snapshot, which this read shares.
     * @return array offer id => quantity
     */
    protected function readCartOfferQuantityList(): array
    {
        $arQuantityList = [];
        foreach (CartProcessor::instance()->get() as $obCartPositionItem) {
            $obOfferItem = $obCartPositionItem->item;
            $obOffer = $obOfferItem instanceof OfferItem ? $obOfferItem->getObject() : null;
            if (!empty($obOffer)) {
                $arQuantityList[(int) $obOffer->id] = (int) $obOffer->quantity;
            }
        }

        return $arQuantityList;
    }

    /**
     * Inside a caller's transaction (backend form save) a failure rolls the status change back
     * with it. A status saved on its own (customer cancel, payment callback) is already
     * committed, so a failure is logged and the next save of the order reconciles again.
     * @param Order    $obOrder
     * @param callable $fnMove
     * @return void
     */
    protected function runStockMove(Order $obOrder, callable $fnMove): void
    {
        if (!StockHoldPolicy::isEnabled()) {
            return;
        }

        $bOwnTransaction = DB::transactionLevel() === 0;
        try {
            $fnMove();
        } catch (Throwable $obException) {
            if (!$bOwnTransaction) {
                throw $obException;
            }

            // The rows keep their state: the next save of the order moves them; a deleted order's
            // rows stay in the ledger table for a manual give-back.
            Log::error('Order '.$obOrder->id.' stock hold move failed, rows left in '.OrderStockHolds::TABLE.': '.$obException->getMessage());
        }
    }
}
