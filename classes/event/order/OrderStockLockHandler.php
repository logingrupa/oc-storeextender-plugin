<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Log;
use Kharanenka\Helper\Result;
use Logingrupa\StoreExtender\Classes\Helper\OrderEligibilityValidator;
use Lovata\OrdersShopaholic\Classes\Item\CartPositionItem;
use Lovata\OrdersShopaholic\Classes\Item\PaymentMethodItem;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Toolbox\Classes\Item\ItemStorage;
use Throwable;

/**
 * Unconditional priority-10 InnoDB row-locking stock guard inside OrderProcessor's transaction.
 * Runs before OrderStockHoldHandler (priority 0) for BOTH browser checkouts and B2B API orders:
 * - Locks requested Offer rows and parent Product rows in deterministic sorted ID order
 * - Rejects missing, soft-deleted, or inactive offers/products under the lock
 * - Clears CartPositionItem caches and injects fresh locked Offer models into ItemStorage so
 *   Shopaholic's decrement and OrderStockHoldHandler never read pre-lock quantities
 * - Re-evaluates aggregate requested quantities across duplicate cart lines for both
 *   check_offer_quantity=ON and check_offer_quantity=OFF (online gateway veto & bidirectional out_of_stock)
 * - Never returns false from EVENT_UPDATE_ORDER_BEFORE_CREATE (uses Result::setFalse() returning null
 *   so OrderProcessor::create() cleanly executes DB::rollBack()).
 */
class OrderStockLockHandler
{
    public const PRIORITY = 10;

    private ?bool $bPendingLockedOverstock = null;

    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_BEFORE_CREATE, function ($arOrderData = []): void {
            $this->lockAndValidateUnderTransaction(is_array($arOrderData) ? $arOrderData : []);
        }, self::PRIORITY);

        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, function ($obOrder): void {
            $bLockedOverstock = $this->bPendingLockedOverstock;
            $this->bPendingLockedOverstock = null;
            if ($bLockedOverstock === null || !$obOrder instanceof Order || !Result::status()) {
                return;
            }

            // Persist bidirectional overstock decision (both 0 -> 1 and 1 -> 0) inside the transaction.
            $obOrder->property = ['out_of_stock' => $bLockedOverstock ? '1' : '0'];
            $obOrder->save();
        });

        $obEvent->listen(TransactionRolledBack::class, function (TransactionRolledBack $obRolledBack): void {
            if ($obRolledBack->connection->transactionLevel() === 0) {
                $this->bPendingLockedOverstock = null;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $arOrderData
     */
    protected function lockAndValidateUnderTransaction(array $arOrderData): void
    {
        $this->bPendingLockedOverstock = null;

        if (!Result::status()) {
            return;
        }

        $obCartPositionList = CartProcessor::instance()->get();
        if ($obCartPositionList->isEmpty()) {
            return;
        }

        /** @var array<int, int> $arRequestedByOffer */
        $arRequestedByOffer = [];
        /** @var list<int> $arCartPositionIds */
        $arCartPositionIds = [];

        foreach ($obCartPositionList as $obCartPositionItem) {
            $iCartPosId = (int) $obCartPositionItem->id;
            if ($iCartPosId > 0) {
                $arCartPositionIds[] = $iCartPosId;
            }

            if ((string) $obCartPositionItem->item_type !== Offer::class) {
                continue;
            }

            $iOfferId = (int) $obCartPositionItem->item_id;
            $iQty = (int) $obCartPositionItem->quantity;
            if ($iOfferId > 0 && $iQty > 0) {
                $arRequestedByOffer[$iOfferId] = ($arRequestedByOffer[$iOfferId] ?? 0) + $iQty;
            }
        }

        if ($arRequestedByOffer === []) {
            return;
        }

        $arSortedOfferIds = array_keys($arRequestedByOffer);
        sort($arSortedOfferIds, SORT_NUMERIC);

        try {
            $obLockedOffers = Offer::withTrashed()
                ->with(OfferItem::$arQueryWith)
                ->whereIn('id', $arSortedOfferIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $arProductIds = [];
            foreach ($arSortedOfferIds as $iOfferId) {
                $obOffer = $obLockedOffers->get($iOfferId);
                if (!$obOffer instanceof Offer || $obOffer->trashed() || !$obOffer->active) {
                    $this->rejectOrder((string) __('One or more items in your cart are no longer available.'));

                    return;
                }
                $iProductId = (int) $obOffer->product_id;
                if ($iProductId < 1) {
                    $this->rejectOrder((string) __('One or more items in your cart are no longer available.'));

                    return;
                }
                $arProductIds[$iProductId] = $iProductId;
            }

            $arSortedProductIds = array_values($arProductIds);
            sort($arSortedProductIds, SORT_NUMERIC);
            $obLockedProducts = Product::withTrashed()
                ->whereIn('id', $arSortedProductIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($arSortedProductIds as $iProductId) {
                $obProduct = $obLockedProducts->get($iProductId);
                if (!$obProduct instanceof Product || $obProduct->trashed() || !$obProduct->active) {
                    $this->rejectOrder((string) __('One or more items in your cart are no longer available.'));

                    return;
                }
            }
        } catch (Throwable $obException) {
            Log::warning('OrderStockLockHandler failed to lock stock rows inside checkout transaction.', [
                'offer_ids' => $arSortedOfferIds,
                'exception' => $obException::class,
                'message' => $obException->getMessage(),
            ]);
            $this->rejectOrder((string) __('Unable to reserve stock right now. Please try again.'));

            return;
        }

        // Clear cached CartPositionItems so OrderProcessor::processOrderPositionList() creates fresh
        // OfferOrderPositionProcessor instances backed by the locked Offer models below.
        foreach (array_unique($arCartPositionIds) as $iCartPositionId) {
            ItemStorage::clear(CartPositionItem::class, $iCartPositionId);
        }

        $bAnyInsufficient = false;
        foreach ($arSortedOfferIds as $iOfferId) {
            /** @var Offer $obLockedOffer */
            $obLockedOffer = $obLockedOffers->get($iOfferId);
            ItemStorage::clear(OfferItem::class, $iOfferId);
            ItemStorage::set(OfferItem::class, $iOfferId, OfferItem::makeNoCache($iOfferId, $obLockedOffer));

            if ((int) $obLockedOffer->quantity < $arRequestedByOffer[$iOfferId]) {
                $bAnyInsufficient = true;
            }
        }

        $bHardStockCheck = (bool) Settings::getValue('check_offer_quantity');
        if ($bHardStockCheck && $bAnyInsufficient) {
            $this->rejectOrder((string) __('Requested quantity exceeds available stock.'));

            return;
        }

        if (!$bHardStockCheck) {
            $iPaymentMethodId = is_numeric($arOrderData['payment_method_id'] ?? null)
                ? (int) $arOrderData['payment_method_id']
                : 0;
            if ($iPaymentMethodId > 0) {
                $obPaymentMethod = PaymentMethodItem::make($iPaymentMethodId);
                $sPaymentError = OrderEligibilityValidator::validatePaymentForStockState($obPaymentMethod, $bAnyInsufficient);
                if ($sPaymentError !== null) {
                    $this->rejectOrder($sPaymentError);

                    return;
                }
            }

            $this->bPendingLockedOverstock = $bAnyInsufficient;
        }
    }

    private function rejectOrder(string $sMessage): void
    {
        $this->bPendingLockedOverstock = null;
        Result::setFalse()->setMessage($sMessage);
    }
}
