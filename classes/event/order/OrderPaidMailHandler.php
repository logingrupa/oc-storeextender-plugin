<?php namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Helper\AbstractPaymentGateway;
use Logingrupa\StoreExtender\Plugin;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailSender;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailState;

/**
 * Class OrderPaidMailHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * Tells the customer and the managers when an online payment arrives. Lovata sends its
 * order mails only at creation, before the customer has paid, so without this a paid
 * Paysera, PayPal or Vipps order never got a confirmation.
 */
class OrderPaidMailHandler
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        // Lovata reads the listener result as a replacement order, so this returns nothing.
        $obEvent->listen(AbstractPaymentGateway::EVENT_PAYMENT_SUCCESS, function (Order $obOrder) {
            $this->sendPaidMails($obOrder);
        });
    }

    /**
     * @param Order $obOrder
     * @return void
     */
    protected function sendPaidMails(Order $obOrder)
    {
        // A repeated gateway callback re-saves the paid status without changing it
        if (!$obOrder->wasChanged('status_id')) {
            return;
        }

        OrderMailSender::sendToCustomer($obOrder, Plugin::MAIL_ORDER_PAID_USER, OrderMailState::PAID);
        OrderMailSender::sendToManagers($obOrder, Plugin::MAIL_ORDER_PAID_MANAGER, OrderMailState::PAID);
    }
}
