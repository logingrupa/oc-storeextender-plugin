<?php namespace Logingrupa\StoreExtender\Classes\Event;

use Lovata\PayPalShopaholic\Classes\Helper\PayPalExpressPaymentGateway;
use Logingrupa\StoreExtender\Classes\Helper\OrderPageUrl;

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
            return OrderPageUrl::make($obOrder);
        });
    }
}
