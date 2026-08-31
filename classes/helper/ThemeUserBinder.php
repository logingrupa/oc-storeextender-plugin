<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cms\Classes\ComponentManager;
use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class ThemeUserBinder
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Binds the live user plugin's session component onto a layout and hands the layout the
 * two variables the theme reads from it: "obUser" and "sLogoutHandler".
 *
 * Buddies exposes the current user through its own UserData component and logs out through
 * Logout::onAjax; RainLab.User uses Session and Session::onLogout. Neither component class
 * exists when the other plugin is the live one, so five layouts each carried their own copy
 * of the check.
 *
 * Buddies stays supported until the production cutover, when it is removed for good.
 */
class ThemeUserBinder
{
    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';

    const BUDDIES_COMPONENT_CLASS = 'Lovata\Buddies\Components\UserData';
    const BUDDIES_COMPONENT_ALIAS = 'UserData';
    const BUDDIES_LOGOUT_HANDLER = 'Logout::onAjax';

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

        $bIsBuddies = UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME;

        $obLayout['sLogoutHandler'] = $bIsBuddies
            ? self::BUDDIES_LOGOUT_HANDLER
            : self::RAINLAB_LOGOUT_HANDLER;

        $obComponent = self::resolveComponent($obLayout, $bIsBuddies);
        if (empty($obComponent)) {
            return;
        }

        $obLayout['obUser'] = $bIsBuddies ? $obComponent->get() : $obComponent->user();
    }

    /**
     * Reuse the instance the layout INI declared, otherwise register one
     * @param \Cms\Classes\CodeBase $obLayout
     * @param bool $bIsBuddies
     * @return \Cms\Classes\ComponentBase|null
     */
    protected static function resolveComponent($obLayout, $bIsBuddies)
    {
        $sComponentClass = $bIsBuddies ? self::BUDDIES_COMPONENT_CLASS : self::RAINLAB_COMPONENT_CLASS;
        $sComponentAlias = $bIsBuddies ? self::BUDDIES_COMPONENT_ALIAS : self::RAINLAB_COMPONENT_ALIAS;

        if (!ComponentManager::instance()->hasComponent($sComponentClass)) {
            return null;
        }

        // addComponent would shadow a configured instance with a bare one.
        $obComponent = $obLayout->{$sComponentAlias} ?? null;
        if (!empty($obComponent)) {
            return $obComponent;
        }

        return $obLayout->addComponent($sComponentClass, $sComponentAlias, []);
    }
}
