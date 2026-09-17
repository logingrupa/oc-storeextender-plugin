<?php namespace Logingrupa\StoreExtender\Classes\Registrar;

use Event;

/**
 * PaymentRedirectRegistrar sends a shopper coming back from a payment gateway
 * to their own order page instead of the homepage.
 *
 * The URL is built server side from the order's secret key and a CMS page name
 * this class resolves itself. Nothing the gateway sends back is ever used as a
 * redirect target.
 */
class PaymentRedirectRegistrar
{
    /**
     * Listen to Omnipay gateway cancel/return events and redirect
     * back to the order checkout page instead of homepage.
     */
    public static function addPaymentGatewayRedirectListeners(): void
    {
        $fnGetCheckoutURL = function ($obOrder) {
            if (empty($obOrder) || empty($obOrder->secret_key)) {
                return null;
            }

            // Find CMS page with OrderPage component dynamically
            $sPageName = self::findOrderPage();

            if (!empty($sPageName)) {
                return \Cms\Classes\Page::url($sPageName, ['slug' => $obOrder->secret_key]);
            }

            return null;
        };

        Event::listen(
            \Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_CANCEL_URL,
            $fnGetCheckoutURL
        );

        Event::listen(
            \Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_RETURN_URL,
            $fnGetCheckoutURL
        );
    }

    /**
     * Find the first CMS page that has the OrderPage component.
     * Skips proforma/print pages by preferring pages without :print param.
     *
     * @return string|null CMS page file name
     */
    public static function findOrderPage(): ?string
    {
        $obTheme = \Cms\Classes\Theme::getActiveTheme();
        $arPages = \Cms\Classes\Page::listInTheme($obTheme);

        $sFirstMatch = null;

        foreach ($arPages as $obPage) {
            $arComponents = $obPage->settings['components'] ?? [];

            if (!isset($arComponents['OrderPage'])) {
                continue;
            }

            // Skip proforma/print pages
            if (str_contains($obPage->url, ':print')) {
                continue;
            }

            return $obPage->getBaseFileName();
        }

        return $sFirstMatch;
    }
}
