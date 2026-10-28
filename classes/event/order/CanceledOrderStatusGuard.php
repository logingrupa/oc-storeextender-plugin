<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Logingrupa\StoreExtender\Classes\Helper\OrderStatusCode;

/**
 * Class CanceledOrderStatusGuard
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * A gateway's cancel or fail callback can arrive after the order was canceled (Vipps sends
 * EXPIRED minutes later, and the gateway status setters check nothing). Moving a canceled
 * order back to an unpaid payment state would make it payable again, send it payment
 * reminders and take its stock back, so the order stays canceled. A paid callback still
 * moves it: the money arrived.
 */
class CanceledOrderStatusGuard
{
    const UNPAID_STATE_LIST = [OrderStatusCode::PAYMENT_PENDING, OrderStatusCode::PAYMENT_CANCELED, OrderStatusCode::PAYMENT_FAILED];

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     * @return void
     */
    public function subscribe($obEvent): void
    {
        Order::extend(function (Order $obOrder) {
            $obOrder->bindEvent('model.beforeSave', function () use ($obOrder) {
                $this->keepCanceled($obOrder);
            });
        });
    }

    /**
     * Status ids are compared as integers: the backend relation field sets a string id.
     * @param Order $obOrder
     * @return void
     */
    protected function keepCanceled(Order $obOrder): void
    {
        $iOriginalStatusID = (int) $obOrder->getOriginal('status_id');
        $iStatusID = (int) $obOrder->status_id;
        if ($iOriginalStatusID === 0 || $iOriginalStatusID === $iStatusID) {
            return;
        }

        $arCodeList = Status::whereIn('id', [$iOriginalStatusID, $iStatusID])->pluck('code', 'id')->all();
        if (($arCodeList[$iOriginalStatusID] ?? null) !== OrderStatusCode::CANCELED
            || !in_array($arCodeList[$iStatusID] ?? null, self::UNPAID_STATE_LIST, true)) {
            return;
        }

        $obOrder->status_id = $iOriginalStatusID;
        Log::info('Order '.$obOrder->id.' stays canceled: refused status '.$arCodeList[$iStatusID]);
    }
}
