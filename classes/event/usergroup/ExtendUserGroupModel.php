<?php namespace Logingrupa\StoreExtender\Classes\Event\UserGroup;

use Lovata\Shopaholic\Models\PriceType;

use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;

/**
 * Class ExtendUserGroupModel
 * @package Logingrupa\StoreExtender\Classes\Event\UserGroup
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendUserGroupModel
{
    public function subscribe()
    {
        $sModelClass = UserGroupHelper::instance()->getGroupModel();
        if (empty($sModelClass)) {
            return;
        }

        $sModelClass::extend(function ($obGroup) {
            $obGroup->belongsTo['price_type'] = [PriceType::class];
        });
    }
}
