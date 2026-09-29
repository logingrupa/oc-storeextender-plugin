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
     * The primary group alone sets the price tier and the secondary groups mirror it,
     * so the form offers the primary group only.
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->removeField('groups');
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
}
