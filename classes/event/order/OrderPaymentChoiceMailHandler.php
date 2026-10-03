<?php namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Lovata\OrdersShopaholic\Models\Order;
use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Logingrupa\StoreExtender\Plugin;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailSender;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailState;

/**
 * Class OrderPaymentChoiceMailHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * Mails for what the customer does with an unpaid order on the order page: what to do next
 * once they switch to bank transfer or pay at store, and a confirmation plus a manager
 * notice once they cancel. RetrypaymentShopaholic fires both events.
 */
class OrderPaymentChoiceMailHandler
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(RetryPaymentHelper::EVENT_SWITCHED_TO_OFFLINE, function (Order $obOrder) {
            OrderMailSender::sendToCustomer($obOrder, Plugin::MAIL_ORDER_METHOD_CHANGED_USER, OrderMailState::forNewOrder($obOrder));
        });

        $obEvent->listen(RetryPaymentHelper::EVENT_CANCELED_BY_CUSTOMER, function (Order $obOrder) {
            OrderMailSender::sendToCustomer($obOrder, Plugin::MAIL_ORDER_CANCELED_USER, OrderMailState::CANCELED);
            OrderMailSender::sendToManagers($obOrder, Plugin::MAIL_ORDER_CANCELED_MANAGER, OrderMailState::CANCELED);
        });
    }
}
