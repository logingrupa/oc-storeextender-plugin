<?php namespace Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics;

use Logingrupa\StoreExtender\Classes\Event\Metapixel\MarginValueHandler;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class PurchaseMarginValueHandler
 *
 * Answers the GA4 plugin's purchase before-send hook with the store margin
 * of the order, computed by MarginValueHandler so Meta and GA4 report the
 * same number. Registered by the hook's string name: no class dependency in
 * either direction, so the listener is inert when Logingrupa.GoogleAnalytics
 * is absent.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics
 */
class PurchaseMarginValueHandler
{
    /** The GA4 plugin's purchase value hook */
    const HOOK_BEFORE_SEND = 'googleanalytics.purchase.before_send';

    /**
     * Subscribe to the GA4 purchase pipeline.
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(self::HOOK_BEFORE_SEND, [$this, 'valueFor']);
    }

    /**
     * Margin value for an order subject, null for any other subject or when
     * no line has a known izpl cost (the GA4 plugin then keeps its default).
     * @param mixed       $obSubject
     * @param float|null  $fDefaultValue gross sum the GA4 plugin computed
     * @param string|null $sCurrencyCode
     * @return float|null
     */
    public function valueFor($obSubject, $fDefaultValue = null, $sCurrencyCode = null): ?float
    {
        if (!$obSubject instanceof Order) {
            return null;
        }

        return $this->makeMarginValueHandler()->marginForOrder($obSubject);
    }

    /**
     * @return MarginValueHandler
     */
    protected function makeMarginValueHandler(): MarginValueHandler
    {
        return new MarginValueHandler();
    }
}
