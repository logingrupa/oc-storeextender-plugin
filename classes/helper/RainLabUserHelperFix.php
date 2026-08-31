<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Toolbox\Classes\Helper\Users\RainLabUserHelper;

/**
 * Class RainLabUserHelperFix
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Toolbox's RainLabUserHelper::findUserByEmail() calls RainLab\User\Models\User::findByEmail(),
 * a method RainLab.User 3.5.3 does not have. Every call throws BadMethodCallException, and
 * MakeOrder::findUserByEmail() is on the checkout path, so ordering fails outright with
 * "Call to undefined method" until this is replaced.
 *
 * Bound over the Toolbox class in Plugin::register(); UserHelper resolves its inner helper
 * through the container, so the override reaches every caller.
 *
 * Guests are excluded to match what Buddies did: it had no guest account concept, so an order
 * must never attach to one.
 */
class RainLabUserHelperFix extends RainLabUserHelper
{
    /**
     * @param string $sEmail
     * @return \RainLab\User\Models\User|null
     */
    public function findUserByEmail($sEmail)
    {
        if (empty($sEmail)) {
            return null;
        }

        $sUserModelClass = $this->getUserModel();

        return $sUserModelClass::applyRegistered()->where('email', $sEmail)->first();
    }
}
