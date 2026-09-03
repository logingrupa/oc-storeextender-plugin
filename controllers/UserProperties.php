<?php namespace Logingrupa\StoreExtender\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

/**
 * Class UserProperties
 * @package Logingrupa\StoreExtender\Controllers
 *
 * Dynamic user property definitions for RainLab.User, which has no built-in
 * property definition screen.
 */
class UserProperties extends Controller
{
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

        BackendMenu::setContext('RainLab.User', 'user', 'side-menu-user-properties');
    }
}
