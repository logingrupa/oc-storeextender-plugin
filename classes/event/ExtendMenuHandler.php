<?php namespace Logingrupa\StoreExtender\Classes\Event;

use Backend;
use Lovata\Toolbox\Classes\Event\AbstractBackendMenuHandler;

/**
 * Class ExtendMenuHandler
 * @package Logingrupa\StoreExtender\Classes\Event
 */
class ExtendMenuHandler extends AbstractBackendMenuHandler
{
    const RAINLAB_OWNER = 'RainLab.User';
    const RAINLAB_MAIN_MENU = 'user';

    /**
     * Add menu items. The stock User Groups page is the single group editor
     * (ExtendUserGroupController injects the price type field there).
     * @param \Backend\Classes\NavigationManager $obManager
     */
    protected function addMenuItems($obManager)
    {
        $obManager->addSideMenuItem(self::RAINLAB_OWNER, self::RAINLAB_MAIN_MENU, 'side-menu-user-properties', [
            'label' => 'logingrupa.storeextender::lang.menu.user_property',
            'url' => Backend::url('logingrupa/storeextender/userproperties'),
            'icon' => 'icon-list-ul',
            'order' => 1010,
        ]);
    }
}
