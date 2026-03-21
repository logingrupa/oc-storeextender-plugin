<?php namespace Logingrupa\StoreExtender\Classes\Helper;

/**
 * Class WholeNumberCurrencyConfig
 *
 * Single source of truth for currencies that display as whole numbers.
 * Eliminates duplicated constants across RoundedCurrencyHelper and ExtendCurrencyConversion.
 *
 * @package Logingrupa\StoreExtender\Classes\Helper
 */
class WholeNumberCurrencyConfig
{
    const WHOLE_NUMBER_CURRENCY_CODES = ['NOK', 'SEK', 'DKK'];
    const PRICE_SUFFIX = ',-';

    /**
     * Check if a currency code uses whole-number formatting
     *
     * @param string $sCurrencyCode
     * @return bool
     */
    public static function isWholeNumberCurrency($sCurrencyCode)
    {
        return in_array($sCurrencyCode, self::WHOLE_NUMBER_CURRENCY_CODES);
    }
}
