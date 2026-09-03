<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cms\Classes\ComponentManager;

/**
 * Class ThemeUserBinder
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Binds RainLab.User's Session component onto a layout and hands the layout the
 * two variables the theme reads from it: "obUser" and "sLogoutHandler".
 *
 * The Session component resolves the user from the session on every call, unlike
 * Auth::getUser(), which serves Illuminate's cached instance - that is why the
 * theme must read obUser from here and never through UserHelper::getUser().
 */
class ThemeUserBinder
{
    const RAINLAB_COMPONENT_CLASS = 'RainLab\User\Components\Session';
    const RAINLAB_COMPONENT_ALIAS = 'Session';
    const RAINLAB_LOGOUT_HANDLER = 'Session::onLogout';

    /**
     * Register the session component and populate the layout variables the theme reads
     * @param \Cms\Classes\CodeBase $obLayout
     * @return void
     */
    public static function bind($obLayout)
    {
        if (empty($obLayout)) {
            throw new \InvalidArgumentException('ThemeUserBinder::bind() needs the layout object');
        }

        $obLayout['sLogoutHandler'] = self::RAINLAB_LOGOUT_HANDLER;

        $obComponent = self::resolveComponent($obLayout);
        if (empty($obComponent)) {
            return;
        }

        $obLayout['obUser'] = $obComponent->user();
    }

    /**
     * Reuse the instance the layout INI declared, otherwise register one
     * @param \Cms\Classes\CodeBase $obLayout
     * @return \Cms\Classes\ComponentBase|null
     */
    protected static function resolveComponent($obLayout)
    {
        if (!ComponentManager::instance()->hasComponent(self::RAINLAB_COMPONENT_CLASS)) {
            return null;
        }

        // addComponent would shadow a configured instance with a bare one.
        $obComponent = $obLayout->{self::RAINLAB_COMPONENT_ALIAS} ?? null;
        if (!empty($obComponent)) {
            return $obComponent;
        }

        return $obLayout->addComponent(self::RAINLAB_COMPONENT_CLASS, self::RAINLAB_COMPONENT_ALIAS, []);
    }
}
