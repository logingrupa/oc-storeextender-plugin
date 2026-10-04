<?php namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailData;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailState;

/**
 * Class OrderMailDataHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * Adds the mail state, locale, bank details and admin link to the two new-order mails
 * Lovata sends. Lovata merges every array a listener returns into the mail data.
 *
 * The customer copy follows the site the order was placed on, the manager copy the shop's
 * own language.
 */
class OrderMailDataHandler
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(OrderProcessor::EVENT_ORDER_CREATED_USER_MAIL_DATA, function (Order $obOrder) {
            return OrderMailData::make($obOrder, OrderMailState::forNewOrder($obOrder));
        });

        $obEvent->listen(OrderProcessor::EVENT_ORDER_CREATED_MANAGER_MAIL_DATA, function (Order $obOrder) {
            return OrderMailData::forManager($obOrder, OrderMailState::forNewOrder($obOrder));
        });
    }
}
