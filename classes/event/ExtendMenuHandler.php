<?php namespace Logingrupa\StoreExtender\Classes\Event;

use Backend;
use Lovata\Toolbox\Classes\Event\AbstractBackendMenuHandler;

/**
 * Class ExtendMenuHandler
 * @package Logingrupa\StoreExtender\Classes\Event
 */
class ExtendMenuHandler extends AbstractBackendMenuHandler
{
    /**
     * Add menu items
     * @param \Backend\Classes\NavigationManager $obManager
     */
    protected function addMenuItems($obManager)
    {
        $obManager->addSideMenuItem('Lovata.Buddies', 'main-menu-buddies', 'side-menu-buddies-group', [
            'label' => 'logingrupa.storeextender::lang.menu.group',
            'url' => Backend::url('logingrupa/storeextender/groups'),
            'icon' => 'icon-users',
            //'permissions' => ['logingrupa.storeextender.*'],
            'order' => 1000,
        ]);
    }
}