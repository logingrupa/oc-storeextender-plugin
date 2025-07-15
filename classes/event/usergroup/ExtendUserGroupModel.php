<?php namespace Logingrupa\StoreExtender\Classes\Event\UserGroup;

use Lovata\Buddies\Models\Group;
use Lovata\Shopaholic\Models\PriceType;

/**
 * Class ExtendUserGroupModel
 * @package Logingrupa\StoreExtender\Classes\Event\UserGroup
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendUserGroupModel
{
    /**
     * Add listeners
     */
    public function subscribe()
    {
        Group::extend(function ($obGroup) {
            /** @var Group $obGroup */
            $obGroup->belongsTo['price_type'] = [
                PriceType::class
            ];
        });
    }
}
