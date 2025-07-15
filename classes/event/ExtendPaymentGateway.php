<?php namespace Logingrupa\StoreExtender\Classes\Event;

use Cms\Classes\Page;
use Lovata\PayPalShopaholic\Classes\Helper\PayPalExpressPaymentGateway;

/**
 * Class ExtendPaymentGateway
 * @package Logingrupa\StoreExtender\Classes\Event
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendPaymentGateway
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(PayPalExpressPaymentGateway::EVENT_GET_PAYMENT_GATEWAY_RETURN_URL, function ($obOrder, $obPaymentMethod) {
            /** @var \Lovata\OrdersShopaholic\Models\Order $obOrder */
            return Page::url('order-complete', ['slug' => $obOrder->secret_key]);
        });
    }
}
