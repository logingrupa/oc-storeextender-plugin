<?php

namespace Logingrupa\StoreExtender\Classes\Event\User;

use Logingrupa\StoreExtender\Classes\Helper\UserAddressHelper;
use Lovata\OrdersShopaholic\Models\UserAddress;
use Lovata\Toolbox\Classes\Event\AbstractExtendRelationConfigHandler;
use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Wires the Shopaholic UserAddress hasMany relation into RainLab.User's
 * RelationController and gives UserAddress backend dropdown/label accessors.
 */
class ExtendUserAddressRelationHandler extends AbstractExtendRelationConfigHandler
{
    public function subscribe()
    {
        $sUserPluginName = UserHelper::instance()->getPluginName();
        if (empty($sUserPluginName)) {
            return;
        }

        parent::subscribe();
        $this->extendUserAddressModel();
    }

    protected function getControllerClass(): string
    {
        return (string) UserHelper::instance()->getUserController();
    }

    protected function getConfigPath(): string
    {
        return '$/logingrupa/storeextender/config/user/config_relation.yaml';
    }

    protected function extendUserAddressModel(): void
    {
        if (! class_exists(UserAddress::class)) {
            return;
        }

        UserAddress::extend(function ($obAddress) {
            $obAddress->addDynamicMethod('getTypeOptions', function (): array {
                return UserAddressHelper::getTypeOptions();
            });

            $obAddress->addDynamicMethod('getTypeLabelAttribute', function () use ($obAddress): string {
                return UserAddressHelper::getTypeLabel($obAddress->type);
            });
        });
    }
}
