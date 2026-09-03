<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Lovata\Toolbox\Models\CommonProperty;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;

use Logingrupa\StoreExtender\Classes\Helper\UserPropertyHelper;

/**
 * Class ExtendUserPropertyFieldHandler
 * @package Logingrupa\StoreExtender\Classes\Event\User
 *
 * Renders the dynamic user properties on the backend user form.
 */
class ExtendUserPropertyFieldHandler extends AbstractBackendFieldHandler
{
    /**
     * Extend backend fields
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendFields($obWidget)
    {
        if ($obWidget->context != 'update') {
            return;
        }

        $obPropertyList = UserPropertyHelper::instance()->getActiveList();
        if ($obPropertyList->isEmpty()) {
            return;
        }

        $arFieldList = [];
        foreach ($obPropertyList as $obProperty) {
            $arPropertyData = $obProperty->getWidgetData();
            if (empty($arPropertyData)) {
                continue;
            }

            $arFieldList[CommonProperty::NAME.'['.$obProperty->code.']'] = $arPropertyData;
        }

        if (empty($arFieldList)) {
            return;
        }

        $obWidget->addTabFields($arFieldList);
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
