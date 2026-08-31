<?php namespace Logingrupa\StoreExtender\Classes\Event;

use Backend;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\Toolbox\Classes\Event\AbstractBackendMenuHandler;

/**
 * Class ExtendMenuHandler
 * @package Logingrupa\StoreExtender\Classes\Event
 */
class ExtendMenuHandler extends AbstractBackendMenuHandler
{
    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';

    const BUDDIES_OWNER = 'Lovata.Buddies';
    const BUDDIES_MAIN_MENU = 'main-menu-buddies';

    const RAINLAB_OWNER = 'RainLab.User';
    const RAINLAB_MAIN_MENU = 'user';

    /**
     * Add menu items
     * @param \Backend\Classes\NavigationManager $obManager
     */
    protected function addMenuItems($obManager)
    {
        $bIsBuddies = UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME;

        $sOwner = $bIsBuddies ? self::BUDDIES_OWNER : self::RAINLAB_OWNER;
        $sMainMenu = $bIsBuddies ? self::BUDDIES_MAIN_MENU : self::RAINLAB_MAIN_MENU;

        $obManager->addSideMenuItem($sOwner, $sMainMenu, 'side-menu-buddies-group', [
            'label' => 'logingrupa.storeextender::lang.menu.group',
            'url' => Backend::url('logingrupa/storeextender/groups'),
            'icon' => 'icon-users',
            'order' => 1000,
        ]);

        // Buddies ships its own property editor at lovata/buddies/properties.
        if ($bIsBuddies) {
            return;
        }

        $obManager->addSideMenuItem($sOwner, $sMainMenu, 'side-menu-user-properties', [
            'label' => 'logingrupa.storeextender::lang.menu.user_property',
            'url' => Backend::url('logingrupa/storeextender/userproperties'),
            'icon' => 'icon-list-ul',
            'order' => 1010,
        ]);
    }
}
