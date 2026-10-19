<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use Site;
use Backend;
use InvalidArgumentException;
use RuntimeException;
use System\Models\SiteDefinition;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;
use Logingrupa\StoreExtender\Classes\Helper\BankTransferDetails;
use Logingrupa\StoreExtender\Classes\Helper\SitePageUrl;

/**
 * Class OrderMailData
 * @package Logingrupa\StoreExtender\Classes\Mail
 *
 * The variables every order mail view reads besides the order itself. The paid mail is
 * sent from a gateway webhook, where the request carries no shop locale (.lt runs
 * APP_LOCALE=en), so the mail is written for the site the order was placed on: its locale
 * and its links to the order page and the proforma. A link without the site's route prefix
 * redirects to the language of the reader's browser.
 *
 * Manager mails are the exception: they stay on the shop's own site whatever language the
 * customer ordered in.
 */
class OrderMailData
{
    const ORDER_PAGE = 'order-complete';

    const PROFORMA_PAGE = 'order-complete-proforma';

    /**
     * @param Order $obOrder
     * @param string $sState a key of OrderMailState::VIEW_MAP
     * @return array
     */
    public static function make(Order $obOrder, string $sState): array
    {
        return self::build($obOrder, $sState, self::getOrderSite($obOrder));
    }

    /**
     * The same data for the shop's site: an order from the ru site must not reach the
     * shop staff in Russian.
     * @param Order $obOrder
     * @param string $sState a key of OrderMailState::VIEW_MAP
     * @return array
     */
    public static function forManager(Order $obOrder, string $sState): array
    {
        // the customer copy renders first and pins every relation it reads to its own language
        return ['order' => self::reload($obOrder)] + self::build($obOrder, $sState, self::getShopSite());
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
     * @param SiteDefinition $obSite the site the mail is written for
     * @return array
     */
    protected static function build(Order $obOrder, string $sState, SiteDefinition $obSite): array
    {
        $arView = OrderMailState::view($sState);

        return [
            'mail_state' => $sState,
            'mail_view' => $arView,
            'can_pay_online' => $arView['pay_online'] && self::hasOnlinePaymentMethod(),
            '_current_locale' => (string) $obSite->hard_locale,
            'backend_order_url' => Backend::url('lovata/ordersshopaholic/orders/update/'.$obOrder->id),
            'bank_detail_list' => $arView['bank_details'] ? BankTransferDetails::forOrder($obOrder) : [],
        ] + self::getLinkList($obOrder, $obSite);
    }

    /**
     * @param Order $obOrder
     * @param SiteDefinition $obSite
     * @return array order_url, proforma_url and proforma_print_url, absolute, on $obSite
     */
    protected static function getLinkList(Order $obOrder, SiteDefinition $obSite): array
    {
        $sSecretKey = (string) $obOrder->secret_key;
        if ($sSecretKey === '') {
            throw new InvalidArgumentException('Order '.$obOrder->id.' has no secret_key to link the mail to');
        }

        return [
            'order_url' => SitePageUrl::make($obSite, self::ORDER_PAGE, ['slug' => $sSecretKey]),
            'proforma_url' => SitePageUrl::make($obSite, self::PROFORMA_PAGE, ['slug' => $sSecretKey, 'print' => null]),
            'proforma_print_url' => SitePageUrl::make($obSite, self::PROFORMA_PAGE, ['slug' => $sSecretKey, 'print' => 'print']),
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
     * The site the order was placed on. Orders ported from the old shop carry none and
     * belong to the shop's own site.
     * @param Order $obOrder
     * @return SiteDefinition
     */
    protected static function getOrderSite(Order $obOrder): SiteDefinition
    {
        $obSite = empty($obOrder->site_id) ? null : Site::getSiteFromId($obOrder->site_id);

        return $obSite ?: self::getShopSite();
    }

    /**
     * The shop's own site: the primary site, or the first enabled one when the primary
     * site is disabled, which is how .no runs - its primary site is the Latvian source.
     * @return SiteDefinition
     */
    protected static function getShopSite(): SiteDefinition
    {
        $obSite = Site::getPrimarySite();
        if (empty($obSite) || !$obSite->is_enabled) {
            $obSite = Site::listEnabled()->first();
        }

        if (empty($obSite)) {
            throw new RuntimeException('The shop has no enabled site to write order mails for');
        }

        return $obSite;
    }
}
