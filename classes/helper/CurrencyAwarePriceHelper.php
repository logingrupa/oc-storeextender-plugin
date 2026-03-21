<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Logingrupa\StoreExtender\Classes\Event\Currency\ExtendCurrencyConversion;

/**
 * Class CurrencyAwarePriceHelper
 *
 * Extends PriceHelper to format prices differently per currency.
 * For NOK/SEK/DKK: "225,-"
 * For others: default "10.90"
 *
 * This class is injected into PriceHelper's singleton slot via reflection,
 * so all calls to PriceHelper::format() use this instance's properties.
 *
 * Note: PriceHelper::format() is static and uses self::instance() to get
 * the instance. By swapping PriceHelper::$instance with this object,
 * the format() method reads $this->iDecimal etc. from our object.
 * However, the format() logic itself cannot be overridden (static + self).
 * Instead, we set iDecimal=0 so format() returns "225", and then we
 * override format() in this class to append ",-".
 *
 * IMPORTANT: Since PriceHelper::format() uses self::instance(), PHP
 * will call PriceHelper::instance() not CurrencyAwarePriceHelper::instance().
 * But since we swapped the singleton, self::instance() returns THIS object.
 * The static format() method is inherited and self refers to PriceHelper.
 * So we CANNOT override format() via inheritance — self binds at compile time.
 *
 * The solution: we only use this to hold the correct iDecimal value (0),
 * and handle the ",-" suffix separately.
 *
 * @package Logingrupa\StoreExtender\Classes\Helper
 */
class CurrencyAwarePriceHelper extends PriceHelper
{
    /**
     * Format price with currency-aware decimals
     * This WON'T be called by PriceHelper::format() because of self:: binding.
     * It's here for direct calls only.
     *
     * @param float $fPrice
     * @return string
     */
    public static function formatForCurrency($fPrice)
    {
        $fPrice = (float) $fPrice;
        $sActiveCurrencyCode = CurrencyHelper::instance()->getActiveCurrencyCode();

        if (in_array($sActiveCurrencyCode, ExtendCurrencyConversion::WHOLE_NUMBER_CURRENCY_CODES)) {
            return number_format(round($fPrice, 0), 0, '', ' ') . ',-';
        }

        return parent::format($fPrice);
    }
}
