<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Lovata\Toolbox\Classes\Helper\UserHelper;
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
                'tab' => $this->getTabName(),
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
        return (string) UserHelper::instance()->getUserModel();
    }

    /**
     * Get controller class name
     * @return string
     */
    protected function getControllerClass(): string
    {
        return (string) UserHelper::instance()->getUserController();
    }

    /**
     * Adding the field to a tab that does not exist would render it in a tab of its own.
     * @return string
     */
    protected function getTabName(): string
    {
        return 'Account';
    }
}
