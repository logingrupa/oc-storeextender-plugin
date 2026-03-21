<?php namespace Logingrupa\StoreExtender\Classes\Event\Currency;

use Event;
use Illuminate\Support\Str;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Logingrupa\StoreExtender\Classes\Helper\RoundedCurrencyHelper;

/**
 * Class ExtendCurrencyConversion
 *
 * Replaces Lovata's CurrencyHelper singleton with RoundedCurrencyHelper
 * to apply whole-number rounding for currencies like NOK, SEK, DKK.
 *
 * Also overrides price formatting so whole-number currencies display
 * prices as "225,-" instead of "225.00" — without modifying any Lovata code.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Currency
 */
class ExtendCurrencyConversion
{
    /** @var array Currency codes that should be rounded to whole numbers */
    const WHOLE_NUMBER_CURRENCY_CODES = ['NOK', 'SEK', 'DKK'];

    /**
     * Register a deferred swap that runs after middleware has processed cookies
     */
    public static function swapCurrencyHelper()
    {
        Event::listen('cms.page.init', function () {
            static $bSwapped = false;
            if ($bSwapped) {
                return;
            }
            $bSwapped = true;

            // Ensure clean state
            CurrencyHelper::forgetInstance();
            RoundedCurrencyHelper::forgetInstance();

            // Create RoundedCurrencyHelper (triggers init which reads cookie)
            $obRoundedHelper = RoundedCurrencyHelper::instance();

            // Inject into CurrencyHelper's static $instance
            $obReflection = new \ReflectionClass(CurrencyHelper::class);
            $obProperty = $obReflection->getProperty('instance');
            $obProperty->setValue(null, $obRoundedHelper);

            // Adjust PriceHelper formatting for whole-number currencies
            self::adjustPriceFormatting($obRoundedHelper);

            // Override OfferItem price field formatting for "225,-" display
            self::overrideItemPriceFormatting($obRoundedHelper);
        });
    }

    /**
     * Set PriceHelper iDecimal to 0 for whole-number currencies
     *
     * @param RoundedCurrencyHelper $obCurrencyHelper
     */
    protected static function adjustPriceFormatting($obCurrencyHelper)
    {
        $sActiveCurrencyCode = $obCurrencyHelper->getActiveCurrencyCode();

        if (!in_array($sActiveCurrencyCode, self::WHOLE_NUMBER_CURRENCY_CODES)) {
            return;
        }

        $obPriceHelper = PriceHelper::instance();
        $obReflection = new \ReflectionClass(PriceHelper::class);
        $obDecimalProperty = $obReflection->getProperty('iDecimal');
        $obDecimalProperty->setValue($obPriceHelper, 0);
    }

    /**
     * Override the dynamic price getter methods on OfferItem
     * to use currency-aware formatting ("225,-" for NOK).
     *
     * PriceHelperTrait adds dynamic methods like getPriceAttribute
     * which call PriceHelper::format(). We override these same methods
     * to call our formatPrice() instead.
     *
     * @param RoundedCurrencyHelper $obCurrencyHelper
     */
    protected static function overrideItemPriceFormatting($obCurrencyHelper)
    {
        $sActiveCurrencyCode = $obCurrencyHelper->getActiveCurrencyCode();

        if (!in_array($sActiveCurrencyCode, self::WHOLE_NUMBER_CURRENCY_CODES)) {
            return;
        }

        // Force OfferItem class to boot its traits (including PriceHelperTrait)
        // by triggering the extend mechanism before we override the methods
        OfferItem::make(null);

        OfferItem::extend(function ($obItem) {
            if (!isset($obItem->arPriceField) || !is_array($obItem->arPriceField)) {
                return;
            }

            foreach ($obItem->arPriceField as $sFieldName) {
                $sMethodName = 'get' . Str::studly($sFieldName) . 'Attribute';
                $sValueFieldName = $sFieldName . '_value';

                $obItem->addDynamicMethod($sMethodName, function ($obElement) use ($sValueFieldName) {
                    $fPrice = $obElement->$sValueFieldName;

                    return ExtendCurrencyConversion::formatPrice($fPrice);
                });
            }
        });
    }

    /**
     * Format a price value with currency-aware formatting
     * NOK/SEK/DKK: "225,-"
     * Others: default PriceHelper::format()
     *
     * @param float|string $fPrice
     * @return string
     */
    public static function formatPrice($fPrice)
    {
        $sActiveCurrencyCode = CurrencyHelper::instance()->getActiveCurrencyCode();

        if (in_array($sActiveCurrencyCode, self::WHOLE_NUMBER_CURRENCY_CODES)) {
            $fPrice = (float) $fPrice;

            return number_format(round($fPrice, 0), 0, '', ' ') . ',-';
        }

        return PriceHelper::format($fPrice);
    }
}
