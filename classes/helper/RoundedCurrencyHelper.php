<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;

/**
 * Class RoundedCurrencyHelper
 *
 * Extends Lovata's CurrencyHelper to apply whole-number rounding
 * for currencies like NOK, SEK, DKK where decimal prices are not standard.
 *
 * The base CurrencyHelper::convert() always rounds to 2 decimals via PriceHelper::round().
 * This override applies an additional rounding step for currencies that should display
 * as whole numbers.
 *
 * @package Logingrupa\StoreExtender\Classes\Helper
 */
class RoundedCurrencyHelper extends CurrencyHelper
{
    /**
     * Convert price to target currency with appropriate rounding
     *
     * @param float $fPrice
     * @param string $sCurrencyTo
     * @return float
     */
    public function convert($fPrice, $sCurrencyTo = null)
    {
        $fConvertedPrice = parent::convert($fPrice, $sCurrencyTo);

        $sCurrencyCode = $sCurrencyTo;
        if (empty($sCurrencyCode)) {
            $sCurrencyCode = $this->getActiveCurrencyCode();
        }

        if (WholeNumberCurrencyConfig::isWholeNumberCurrency($sCurrencyCode)) {
            return round($fConvertedPrice, 0);
        }

        return $fConvertedPrice;
    }
}
