<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Support\Traits\Singleton;

use Logingrupa\StoreExtender\Models\UserProperty;

/**
 * Class UserPropertyHelper
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Single seam for "which model holds the dynamic user property definitions".
 * RainLab.User has no such feature, so this plugin supplies the model.
 */
class UserPropertyHelper
{
    use Singleton;

    /**
     * Get the property definition model class
     * @return string
     */
    public function getPropertyModel()
    {
        return UserProperty::class;
    }

    /**
     * Get active property definitions in display order
     * @return \October\Rain\Database\Collection
     */
    public function getActiveList()
    {
        $sModelClass = $this->getPropertyModel();

        return $sModelClass::active()->orderBy('sort_order', 'asc')->get();
    }

    /**
     * Get "code => name" pairs, for backend dropdowns
     * @return array
     */
    public function getCodeNameList()
    {
        $sModelClass = $this->getPropertyModel();

        return (array) $sModelClass::lists('name', 'code');
    }
}
