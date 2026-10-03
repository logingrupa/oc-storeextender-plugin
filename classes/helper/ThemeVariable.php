<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cms\Classes\Theme;

/**
 * Class ThemeVariable
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Reads one value from the active theme's customization data, the per-shop settings
 * behind the Twig function theme_var and the order mail bank details.
 */
class ThemeVariable
{
    /**
     * @param string $sKey
     * @return mixed null when no theme is active or the key is unset
     */
    public static function get(string $sKey)
    {
        if ($sKey === '') {
            throw new \InvalidArgumentException('Theme variable key must not be empty');
        }

        $obTheme = Theme::getActiveTheme();
        if (empty($obTheme)) {
            return null;
        }

        $obData = $obTheme->getCustomData();

        return $obData ? ($obData->{$sKey} ?? null) : null;
    }
}
