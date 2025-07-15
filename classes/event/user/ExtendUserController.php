<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Lovata\Buddies\Controllers\Users;
use Lovata\Buddies\Models\User;
use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;

/**
 * Class ExtendUserController
 * @package Logingrupa\StoreExtender\Classes\Event\User
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendUserController extends AbstractBackendFieldHandler
{
    /**
     * Extend backend fields
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->addTabFields([
            'groups' => [
                'label' => 'logingrupa.storeextender::lang.group.list_title',
                'tab' => 'lovata.buddies::lang.tab.data',
                'type' => 'relation',
            ],
        ]);
    }

    /**
     * Get model class name
     * @return string
     */
    protected function getModelClass(): string
    {
        return User::class;
    }

    /**
     * Get controller class name
     * @return string
     */
    protected function getControllerClass(): string
    {
        return Users::class;
    }
}
