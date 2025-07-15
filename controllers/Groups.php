<?php namespace Logingrupa\StoreExtender\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

/**
 * Class Groups
 * @package Logingrupa\StoreExtender\Controllers
 * @author Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group1
 */
class Groups extends Controller
{
    public $implement = [
        'Backend.Behaviors.ListController',
        'Backend.Behaviors.FormController',
        'Backend.Behaviors.RelationController',
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';
    public $relationConfig = 'config_relation.yaml';

    /**
     * Users constructor.
     */
    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('RainLab.Users', 'main-menu-users', 'side-menu-users-group');
    }
}