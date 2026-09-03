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

        // Under RainLab the stock User Groups page is the single group editor
        // (ExtendUserGroupController injects the price type field there); the
        // Groups controller in this plugin serves only the Buddies branch.
        if ($bIsBuddies) {
            $obManager->addSideMenuItem(self::BUDDIES_OWNER, self::BUDDIES_MAIN_MENU, 'side-menu-buddies-group', [
                'label' => 'logingrupa.storeextender::lang.menu.group',
                'url' => Backend::url('logingrupa/storeextender/groups'),
                'icon' => 'icon-users',
                'order' => 1000,
            ]);

            // Buddies ships its own property editor at lovata/buddies/properties.
            return;
        }

        $obManager->addSideMenuItem(self::RAINLAB_OWNER, self::RAINLAB_MAIN_MENU, 'side-menu-user-properties', [
            'label' => 'logingrupa.storeextender::lang.menu.user_property',
            'url' => Backend::url('logingrupa/storeextender/userproperties'),
            'icon' => 'icon-list-ul',
            'order' => 1010,
        ]);
    }
}
