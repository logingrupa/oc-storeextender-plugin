<?php namespace Logingrupa\StoreExtender\Classes\Event\UserGroup;

use RainLab\User\Controllers\UserGroups;
use RainLab\User\Models\UserGroup;
use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;

/**
 * Class ExtendUserGroupController
 * @package Logingrupa\StoreExtender\Classes\Event\UserGroup
 */
class ExtendUserGroupController extends AbstractBackendFieldHandler
{
    /**
     * Extend backend fields
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->addTabFields([
            'price_type' => [
                'label' => 'Price Type',
                'tab' => 'General',
                'type' => 'relation',
                'nameFrom' => 'name',
                'span' => 'left',
                'comment' => 'Select the price type for this user group',
                'emptyOption' => ''
            ],
        ]);
    }

    /**
     * Get model class name
     * @return string
     */
    protected function getModelClass(): string
    {
        return UserGroup::class;
    }

    /**
     * Get controller class name
     * @return string
     */
    protected function getControllerClass(): string
    {
        return UserGroups::class;
    }
}