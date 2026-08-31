<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Support\Traits\Singleton;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Models\UserProperty;

/**
 * Class UserPropertyHelper
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Single seam for "which model holds the dynamic user property definitions".
 * Buddies ships its own; RainLab.User has no such feature, so this plugin supplies one.
 */
class UserPropertyHelper
{
    use Singleton;

    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';
    const BUDDIES_PROPERTY_MODEL = \Lovata\Buddies\Models\Property::class;

    /**
     * Get the property definition model class for the active user plugin
     * @return string|null
     */
    public function getPropertyModel()
    {
        if (UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME) {
            return class_exists(self::BUDDIES_PROPERTY_MODEL) ? self::BUDDIES_PROPERTY_MODEL : null;
        }

        return UserProperty::class;
    }

    /**
     * Get active property definitions in display order
     * @return \October\Rain\Database\Collection
     */
    public function getActiveList()
    {
        $sModelClass = $this->getPropertyModel();
        if (empty($sModelClass)) {
            return new \October\Rain\Database\Collection();
        }

        return $sModelClass::active()->orderBy('sort_order', 'asc')->get();
    }

    /**
     * Get "code => name" pairs, for backend dropdowns
     * @return array
     */
    public function getCodeNameList()
    {
        $sModelClass = $this->getPropertyModel();
        if (empty($sModelClass)) {
            return [];
        }

        return (array) $sModelClass::lists('name', 'code');
    }
}
