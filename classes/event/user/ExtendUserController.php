<?php

namespace Logingrupa\StoreExtender\Classes\Event\User;

use Backend\Widgets\Form;
use Illuminate\Events\Dispatcher;
use Lang;
use Lovata\Toolbox\Classes\Event\AbstractBackendFieldHandler;
use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class ExtendUserController
 *
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendUserController extends AbstractBackendFieldHandler
{
    public const EVENT_EXTEND_PREVIEW_TABS = 'rainlab.user.view.extendPreviewTabs';

    /**
     * @param  Dispatcher  $obEvent
     */
    public function subscribe($obEvent)
    {
        $sUserPluginName = UserHelper::instance()->getPluginName();
        if (empty($sUserPluginName)) {
            return;
        }

        parent::subscribe($obEvent);

        if ($sUserPluginName === 'RainLab.User') {
            $obEvent->listen(self::EVENT_EXTEND_PREVIEW_TABS, function (): array {
                return [
                    Lang::get('logingrupa.storeextender::lang.user_address.tab') => '$/logingrupa/storeextender/views/user/_preview_addresses.php',
                    Lang::get('lovata.ordersshopaholic::lang.menu.orders') => '$/logingrupa/storeextender/views/user/_preview_orders.php',
                ];
            });

            $obEvent->listen('backend.list.extendColumns', function ($obWidget): void {
                $sControllerClass = $this->getControllerClass();
                $sModelClass = $this->getModelClass();
                if (! $obWidget->getController() instanceof $sControllerClass || ! $obWidget->model instanceof $sModelClass) {
                    return;
                }

                $obWidget->addColumns([
                    'phone' => [
                        'label' => 'logingrupa.storeextender::lang.field.phone',
                        'type' => 'text',
                        'searchable' => true,
                    ],
                ]);
            });
        }
    }

    /**
     * The primary group alone sets the price tier and the secondary groups mirror it,
     * so the form offers the primary group only. Also exposes phone and Shopaholic
     * UserAddress management on RainLab.User forms.
     *
     * @param  Form  $obWidget
     */
    protected function extendFields($obWidget)
    {
        $obWidget->removeField('groups');

        if (UserHelper::instance()->getPluginName() !== 'RainLab.User') {
            return;
        }

        if ($obWidget->context === 'preview') {
            $obWidget->addTabFields([
                'phone' => [
                    'label' => 'logingrupa.storeextender::lang.field.phone',
                    'type' => 'text',
                    'span' => 'auto',
                    'tab' => 'User',
                ],
            ]);

            return;
        }

        $arFields = [
            'phone' => [
                'label' => 'logingrupa.storeextender::lang.field.phone',
                'type' => 'text',
                'span' => 'left',
                'tab' => 'Account',
            ],
        ];

        if ($obWidget->context === 'update') {
            $arFields['address'] = [
                'type' => 'partial',
                'tab' => 'logingrupa.storeextender::lang.user_address.tab',
                'path' => '$/logingrupa/storeextender/views/user/_address.htm',
                'context' => ['update'],
            ];
        }

        $obWidget->addTabFields($arFields);
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
