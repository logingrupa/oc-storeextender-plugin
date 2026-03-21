<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cookie;
use Cms\Classes\Theme;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Toolbox\Classes\Helper\PriceHelper;

/**
 * Class CurrencyHelperSwapper
 *
 * Handles the singleton swap of CurrencyHelper with RoundedCurrencyHelper
 * and adjusts PriceHelper decimal settings for whole-number currencies.
 *
 * If the theme setting `default_currency_code` is configured and the visitor
 * has no active_currency_code cookie, the theme default is applied automatically.
 *
 * Reflection is used intentionally in two places:
 * - CurrencyHelper::$instance: October's Singleton trait binds static::$instance
 *   to the declaring class. RoundedCurrencyHelper::instance() sets its own $instance
 *   but NOT CurrencyHelper::$instance. Reflection is the only way to inject.
 * - PriceHelper::$iDecimal: PriceHelper::init() reads from DB settings. Using
 *   Settings::set('decimals', 0) would persist to DB and affect all currencies
 *   for all users. Reflection avoids this side-effect.
 *
 * @package Logingrupa\StoreExtender\Classes\Helper
 */
class CurrencyHelperSwapper
{
    /**
     * Swap CurrencyHelper singleton with RoundedCurrencyHelper
     * and adjust PriceHelper decimals for whole-number currencies.
     *
     * @return RoundedCurrencyHelper
     */
    public static function swap()
    {
        self::applyThemeDefaultCurrency();

        CurrencyHelper::forgetInstance();
        RoundedCurrencyHelper::forgetInstance();

        $obRoundedHelper = RoundedCurrencyHelper::instance();

        $obReflection = new \ReflectionClass(CurrencyHelper::class);
        $obProperty = $obReflection->getProperty('instance');
        $obProperty->setValue(null, $obRoundedHelper);

        $sActiveCurrencyCode = $obRoundedHelper->getActiveCurrencyCode();

        if (WholeNumberCurrencyConfig::isWholeNumberCurrency($sActiveCurrencyCode)) {
            $obPriceHelper = PriceHelper::instance();
            $obReflection = new \ReflectionClass(PriceHelper::class);
            $obDecimalProperty = $obReflection->getProperty('iDecimal');
            $obDecimalProperty->setValue($obPriceHelper, 0);
        }

        return $obRoundedHelper;
    }

    /**
     * If no active_currency_code cookie exists and the theme has a
     * default_currency_code setting, queue the cookie so CurrencyHelper
     * picks it up on init().
     */
    protected static function applyThemeDefaultCurrency()
    {
        $sExistingCookie = Cookie::get('active_currency_code');

        if (!empty($sExistingCookie)) {
            return;
        }

        $obTheme = Theme::getActiveTheme();
        if (empty($obTheme)) {
            return;
        }

        $sDefaultCurrencyCode = $obTheme->getCustomData()->default_currency_code;

        if (empty($sDefaultCurrencyCode)) {
            return;
        }

        $sDefaultCurrencyCode = strtoupper(trim($sDefaultCurrencyCode));

        Cookie::queue('active_currency_code', $sDefaultCurrencyCode, 1440);

        request()->cookies->set('active_currency_code', $sDefaultCurrencyCode);
    }
}
