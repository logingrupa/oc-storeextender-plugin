<?php namespace Logingrupa\StoreExtender\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;

/**
 * Class Groups
 * @package Logingrupa\StoreExtender\Controllers
 * @author Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group1
 */
class Groups extends Controller
{
    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';

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
        // The yaml files name no model; the two user plugins call the group model
        // differently. Behaviours read these properties inside parent::__construct(),
        // so the override has to happen first.
        $this->listConfig = $this->makeUserGroupConfig('config_list.yaml');
        $this->formConfig = $this->makeUserGroupConfig('config_form.yaml');

        // The related-user list points at the active plugin's own column and field yaml.
        $this->relationConfig = $this->isBuddies()
            ? 'config_relation.yaml'
            : 'config_relation_rainlab.yaml';

        parent::__construct();

        $this->setMenuContext();
    }

    /**
     * Load a config file and bind it to the active user plugin's group model
     * @param string $sFileName
     * @return \October\Rain\Support\Collection
     */
    protected function makeUserGroupConfig($sFileName)
    {
        $obConfig = $this->makeConfig($sFileName);
        $obConfig->modelClass = UserGroupHelper::instance()->getGroupModel();

        return $obConfig;
    }

    /**
     * @return bool
     */
    protected function isBuddies(): bool
    {
        return UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME;
    }

    /**
     * Highlight the side menu entry ExtendMenuHandler registered for the active plugin
     */
    protected function setMenuContext()
    {
        if ($this->isBuddies()) {
            BackendMenu::setContext('Lovata.Buddies', 'main-menu-buddies', 'side-menu-buddies-group');

            return;
        }

        BackendMenu::setContext('RainLab.User', 'user', 'side-menu-buddies-group');
    }
}
