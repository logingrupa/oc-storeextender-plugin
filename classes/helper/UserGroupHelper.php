<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Support\Traits\Singleton;

use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class UserGroupHelper
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Toolbox UserHelper resolves the user model and controller but not the group model,
 * which the two plugins name differently. This is the single seam for that.
 */
class UserGroupHelper
{
    use Singleton;

    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';
    const BUDDIES_GROUP_MODEL = \Lovata\Buddies\Models\Group::class;
    const RAINLAB_GROUP_MODEL = \RainLab\User\Models\UserGroup::class;

    /**
     * Get the user group model class for the active user plugin
     * @return string|null
     */
    public function getGroupModel()
    {
        $sModelClass = UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME
            ? self::BUDDIES_GROUP_MODEL
            : self::RAINLAB_GROUP_MODEL;

        return class_exists($sModelClass) ? $sModelClass : null;
    }

    /**
     * Find a group by its code
     * @param string $sCode
     * @return \October\Rain\Database\Model|null
     */
    public function findByCode($sCode)
    {
        $sModelClass = $this->getGroupModel();
        if (empty($sCode) || empty($sModelClass)) {
            return null;
        }

        return $sModelClass::where('code', $sCode)->first();
    }
}
