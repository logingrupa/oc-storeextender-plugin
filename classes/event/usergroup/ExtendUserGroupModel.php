<?php namespace Logingrupa\StoreExtender\Classes\Event\UserGroup;

use Lovata\Shopaholic\Models\PriceType;

/**
 * Class ExtendUserGroupModel
 * @package Logingrupa\StoreExtender\Classes\Event\UserGroup
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendUserGroupModel
{
    public function subscribe()
    {
        $pluginManager = \System\Classes\PluginManager::instance();
        
        // Check which user group plugin is available
        if ($pluginManager->hasPlugin('Lovata.Buddies')) {
            \Lovata\Buddies\Models\Group::extend(function ($obGroup) {
                $obGroup->belongsTo['price_type'] = [PriceType::class];
            });
        } elseif ($pluginManager->hasPlugin('RainLab.User')) {
            \RainLab\User\Models\UserGroup::extend(function ($obGroup) {
                $obGroup->belongsTo['price_type'] = [PriceType::class];
            });
        }
    }
}
