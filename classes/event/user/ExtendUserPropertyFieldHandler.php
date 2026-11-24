<?php

namespace Logingrupa\StoreExtender\Classes\Event\User;

use Backend\Widgets\Form;
use Logingrupa\StoreExtender\Classes\Helper\UserPropertyHelper;
use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\Toolbox\Models\CommonProperty;

/**
 * Class ExtendUserPropertyFieldHandler
 */
class ExtendUserPropertyFieldHandler extends AbstractBackendFieldHandler
{
    /**
     * Extend backend fields
     *
     * @param  Form  $obWidget
     */
    protected function extendFields($obWidget)
    {
        if (! in_array($obWidget->context, ['update', 'preview'], true)) {
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

            // RainLab.User preview.php renders only primary tabs literally named "User" and "Details".
            if ($obWidget->context === 'preview') {
                $arPropertyData['tab'] = 'Details';
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
     */
    protected function getModelClass(): string
    {
        return (string) UserHelper::instance()->getUserModel();
    }

    /**
     * Get controller class name
     */
    protected function getControllerClass(): string
    {
        return (string) UserHelper::instance()->getUserController();
    }
}
