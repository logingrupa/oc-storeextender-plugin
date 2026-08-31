<?php namespace Logingrupa\StoreExtender\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class UserProperties
 * @package Logingrupa\StoreExtender\Controllers
 *
 * Dynamic user property definitions for RainLab.User, which has no equivalent of the
 * Buddies "addition properties" screen.
 */
class UserProperties extends Controller
{
    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';

    public $implement = [
        'Backend.Behaviors.ListController',
        'Backend.Behaviors.FormController',
        'Backend.Behaviors.ReorderController',
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';
    public $reorderConfig = 'config_reorder.yaml';

    /**
     * UserProperties constructor.
     */
    public function __construct()
    {
        parent::__construct();

        if (UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME) {
            BackendMenu::setContext('Lovata.Buddies', 'main-menu-buddies', 'side-menu-user-properties');

            return;
        }

        BackendMenu::setContext('RainLab.User', 'user', 'side-menu-user-properties');
    }
}
