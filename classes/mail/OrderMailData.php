<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use App;
use Site;
use Backend;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;
use Logingrupa\StoreExtender\Classes\Helper\BankTransferDetails;

/**
 * Class OrderMailData
 * @package Logingrupa\StoreExtender\Classes\Mail
 *
 * The variables every order mail view reads besides the order itself. The paid mail is
 * sent from a gateway webhook, where the request carries no shop locale (.lt runs
 * APP_LOCALE=en), so the locale comes from the site the order was placed on.
 */
class OrderMailData
{
    /**
     * @param Order $obOrder
     * @param string $sState a key of OrderMailState::VIEW_MAP
     * @return array
     */
    public static function make(Order $obOrder, string $sState): array
    {
        $arView = OrderMailState::view($sState);

        return [
            'mail_state' => $sState,
            'mail_view' => $arView,
            'can_pay_online' => $arView['pay_online'] && self::hasOnlinePaymentMethod(),
            '_current_locale' => self::getOrderLocale($obOrder),
            'backend_order_url' => Backend::url('lovata/ordersshopaholic/orders/update/'.$obOrder->id),
            'bank_detail_list' => $arView['bank_details'] ? BankTransferDetails::forOrder($obOrder) : [],
        ];
    }

    /**
     * Collections resolve through the container, so a plugin hiding test-mode methods
     * from customers (PayseraShopaholic) also hides them here.
     * @return bool
     */
    protected static function hasOnlinePaymentMethod(): bool
    {
        $arActiveIdList = PaymentMethodCollection::make()->active()->getIDList();

        return PaymentMethod::whereIn('id', $arActiveIdList)->where('gateway_id', '!=', '')->exists();
    }

    /**
     * @param Order $obOrder
     * @return string
     */
    protected static function getOrderLocale(Order $obOrder): string
    {
        $obSite = empty($obOrder->site_id) ? null : Site::getSiteFromId($obOrder->site_id);

        return $obSite ? (string) $obSite->hard_locale : App::getLocale();
    }
}
