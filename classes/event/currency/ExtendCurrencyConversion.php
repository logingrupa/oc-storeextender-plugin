<?php namespace Logingrupa\StoreExtender\Classes\Event\Currency;

use Event;
use Logingrupa\StoreExtender\Classes\Helper\CurrencyHelperSwapper;

/**
 * Class ExtendCurrencyConversion
 *
 * Thin orchestrator that hooks into the CMS page lifecycle to swap
 * the CurrencyHelper singleton and apply whole-number price formatting.
 *
 * Delegates singleton swapping to CurrencyHelperSwapper and
 * price formatting to WholeNumberPriceFormatter (SRP).
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Currency
 */
class ExtendCurrencyConversion
{
    /**
     * Register a deferred swap that runs after middleware has processed cookies.
     * Must fire on cms.page.beforeDisplay: it precedes layout onInit, so the
     * layout's CurrencyHelper::instance() call already gets the swapped
     * RoundedCurrencyHelper. cms.page.init fired AFTER layout onInit - the
     * vendor singleton got built first, then forgetInstance() discarded it,
     * costing a duplicate currency query and a stale activeCurrencyCode on
     * first visit (no cookie) for theme-default-currency sites.
     */
    public static function swapCurrencyHelper()
    {
        Event::listen('cms.page.beforeDisplay', function () {
            static $bSwapped = false;
            if ($bSwapped) {
                return;
            }
            $bSwapped = true;

            $obCurrencyHelper = CurrencyHelperSwapper::swap();

            WholeNumberPriceFormatter::overrideAllItemPriceFormatting($obCurrencyHelper);
        });
    }

    /**
     * Format a price value with currency-aware formatting.
     * Used as Twig filter `currency_price`.
     *
     * NOK/SEK/DKK: "225,-"
     * Others: default PriceHelper::format()
     *
     * @param float|string $fPrice
     * @return string
     */
    public static function formatPrice($fPrice)
    {
        return WholeNumberPriceFormatter::formatWholeNumberPrice($fPrice);
    }
}
