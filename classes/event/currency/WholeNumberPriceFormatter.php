<?php namespace Logingrupa\StoreExtender\Classes\Event\Currency;

use Illuminate\Support\Str;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\OrdersShopaholic\Classes\Item\OrderPositionItem;
use Lovata\OrdersShopaholic\Classes\Item\OrderItem;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Logingrupa\StoreExtender\Classes\Helper\WholeNumberCurrencyConfig;

/**
 * Class WholeNumberPriceFormatter
 *
 * Owns all ",-" suffix formatting for whole-number currencies (NOK, SEK, DKK).
 * Extends price formatting to OfferItem, OrderPositionItem, and OrderItem.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Currency
 */
class WholeNumberPriceFormatter
{
    /**
     * Override price formatting on all Item classes that use PriceHelperTrait.
     * Covers OfferItem, OrderPositionItem, and OrderItem.
     *
     * @param \Logingrupa\StoreExtender\Classes\Helper\RoundedCurrencyHelper $obCurrencyHelper
     */
    public static function overrideAllItemPriceFormatting($obCurrencyHelper)
    {
        $sActiveCurrencyCode = $obCurrencyHelper->getActiveCurrencyCode();

        if (!WholeNumberCurrencyConfig::isWholeNumberCurrency($sActiveCurrencyCode)) {
            return;
        }

        self::overrideItemClassPriceFormatting(OfferItem::class);
        self::overrideItemClassPriceFormatting(OrderPositionItem::class);
        self::overrideItemClassPriceFormatting(OrderItem::class);
    }

    /**
     * Override dynamic price getter methods on a specific Item class
     * to use whole-number formatting ("225,-").
     *
     * PriceHelperTrait adds dynamic methods like getPriceAttribute
     * which call PriceHelper::format(). We override these same methods
     * to call our formatWholeNumberPrice() instead.
     *
     * @param string $sItemClass
     */
    protected static function overrideItemClassPriceFormatting($sItemClass)
    {
        $sItemClass::make(null);

        $sItemClass::extend(function ($obItem) {
            if (!isset($obItem->arPriceField) || !is_array($obItem->arPriceField)) {
                return;
            }

            foreach ($obItem->arPriceField as $sFieldName) {
                $sMethodName = 'get' . Str::studly($sFieldName) . 'Attribute';
                $sValueFieldName = $sFieldName . '_value';

                $obItem->addDynamicMethod($sMethodName, function ($obElement) use ($sValueFieldName) {
                    $fPrice = $obElement->$sValueFieldName;

                    return WholeNumberPriceFormatter::formatWholeNumberPrice($fPrice);
                });
            }
        });
    }

    /**
     * Format a price value for whole-number currencies.
     * NOK/SEK/DKK: "225,-" or "1 225,-"
     * Others: default PriceHelper::format()
     *
     * Handles prices >= 1000 that may already contain space separators
     * by stripping non-numeric chars before casting to float.
     *
     * @param float|string $fPrice
     * @return string
     */
    public static function formatWholeNumberPrice($fPrice)
    {
        $sActiveCurrencyCode = CurrencyHelper::instance()->getActiveCurrencyCode();

        if (WholeNumberCurrencyConfig::isWholeNumberCurrency($sActiveCurrencyCode)) {
            $fPrice = (float) str_replace(' ', '', (string) $fPrice);

            return number_format(round($fPrice, 0), 0, '', ' ') . WholeNumberCurrencyConfig::PRICE_SUFFIX;
        }

        return PriceHelper::format($fPrice);
    }
}
