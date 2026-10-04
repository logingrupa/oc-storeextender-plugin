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
 *
 * Manager mails are the exception: they stay in the shop's own language whatever
 * language the customer ordered in.
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
        return self::build($obOrder, $sState, self::getOrderLocale($obOrder));
    }

    /**
     * The same data in the shop's language: an order from the ru site must not reach the
     * shop staff in Russian.
     * @param Order $obOrder
     * @param string $sState a key of OrderMailState::VIEW_MAP
     * @return array
     */
    public static function forManager(Order $obOrder, string $sState): array
    {
        // the customer copy renders first and pins every relation it reads to its own language
        return ['order' => self::reload($obOrder)] + self::build($obOrder, $sState, self::getShopLocale());
    }

    /**
     * @param Order $obOrder
     * @return Order the same order, freshly loaded, with no relation bound to another locale
     */
    protected static function reload(Order $obOrder): Order
    {
        return $obOrder->exists ? (Order::find($obOrder->id) ?: $obOrder) : $obOrder;
    }

    /**
     * @param Order $obOrder
     * @param string $sState a key of OrderMailState::VIEW_MAP
     * @param string $sLocale the locale the mail renders in
     * @return array
     */
    protected static function build(Order $obOrder, string $sState, string $sLocale): array
    {
        $arView = OrderMailState::view($sState);

        return [
            'mail_state' => $sState,
            'mail_view' => $arView,
            'can_pay_online' => $arView['pay_online'] && self::hasOnlinePaymentMethod(),
            '_current_locale' => $sLocale,
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

        return self::localeOf($obSite);
    }

    /**
     * The shop's own language: the primary site, or the first enabled one when the primary
     * site is disabled, which is how .no runs - its primary site is the Latvian source.
     * @return string
     */
    protected static function getShopLocale(): string
    {
        $obSite = Site::getPrimarySite();
        if (empty($obSite) || !$obSite->is_enabled) {
            $obSite = Site::listEnabled()->first();
        }

        return self::localeOf($obSite);
    }

    /**
     * @param \System\Models\SiteDefinition|null $obSite
     * @return string the app locale when the shop defines no site
     */
    protected static function localeOf($obSite): string
    {
        return $obSite ? (string) $obSite->hard_locale : App::getLocale();
    }
}
