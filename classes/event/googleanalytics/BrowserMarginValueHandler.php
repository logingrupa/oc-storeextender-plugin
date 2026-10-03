<?php namespace Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics;

use Logingrupa\StoreExtender\Classes\Event\Metapixel\MarginValueHandler;

/**
 * Class BrowserMarginValueHandler
 *
 * Answers the GA4 plugin's browser value hook with the store margin of the
 * offer lines, computed by MarginValueHandler so Meta and GA4 report one
 * number. Registered by the hook's string name: no class dependency in
 * either direction, so the listener is inert when Logingrupa.GoogleAnalytics
 * is absent.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\GoogleAnalytics
 */
class BrowserMarginValueHandler
{
    /** The GA4 plugin's browser value hook */
    const HOOK_BEFORE_SEND = 'googleanalytics.browser.before_send';

    /**
     * Subscribe to the GA4 browser pipeline.
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(self::HOOK_BEFORE_SEND, [$this, 'valueFor']);
    }

    /**
     * Margin value for the event's offer lines, null when the lines are not a
     * list or no line has a known izpl cost (the GA4 plugin then keeps its default).
     * @param string|null $sEventName
     * @param mixed       $arLineList [['offer_id' => int, 'price' => float, 'quantity' => int], ...]
     * @param float|null  $fDefaultValue gross sum the GA4 plugin computed
     * @param string|null $sCurrencyCode
     * @return float|null
     */
    public function valueFor($sEventName = null, $arLineList = null, $fDefaultValue = null, $sCurrencyCode = null): ?float
    {
        if (!is_array($arLineList)) {
            return null;
        }

        return $this->makeMarginValueHandler()->marginForOfferLines($arLineList);
    }

    /**
     * @return MarginValueHandler
     */
    protected function makeMarginValueHandler(): MarginValueHandler
    {
        return new MarginValueHandler();
    }
}
