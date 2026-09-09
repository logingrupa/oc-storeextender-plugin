<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Support\Traits\Singleton;

use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class UserPhoneLookup
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Resolves the accounts holding a phone number. The public checkout check only
 * ever exposes exists(): a boolean. findUsers() serves the server-side login by
 * phone (PhoneLoginHandler), where the password check decides which match wins.
 *
 * Matching runs on "phone_short", the digits-and-plus form both user models derive from
 * "phone" on save. That column holds a comma delimited list, because accounts migrated from
 * Buddies accumulated several variants of one number, hence FIND_IN_SET rather than "=".
 */
class UserPhoneLookup
{
    use Singleton;

    /** Shorter than this and the answer would be true for half the customer base. */
    const MINIMUM_DIGIT_COUNT = 6;

    /**
     * Normalize the same way the user model's phone setter does
     * @param string $sPhone
     * @return string
     */
    public function normalize($sPhone)
    {
        return (string) preg_replace('%[^\d+]%', '', $sPhone);
    }

    /**
     * @param string $sPhone raw, as typed at checkout
     * @return bool
     */
    public function exists($sPhone)
    {
        $obUserList = $this->findUsers($sPhone);

        return $obUserList !== null && $obUserList->isNotEmpty();
    }

    /**
     * Every live account whose phone list holds the number; null when the input is
     * too short to ask or no user plugin is active.
     * @param string $sPhone raw, as typed
     * @return \October\Rain\Database\Collection|null
     */
    public function findUsers($sPhone)
    {
        $sNormalized = $this->normalize($sPhone);
        if (!$this->isLongEnough($sNormalized)) {
            return null;
        }

        $sUserModelClass = UserHelper::instance()->getUserModel();
        if (empty($sUserModelClass)) {
            return null;
        }

        return $sUserModelClass::whereRaw('FIND_IN_SET(?, `phone_short`)', [$sNormalized])->get();
    }

    /**
     * @param string $sNormalizedPhone
     * @return bool
     */
    public function isLongEnough($sNormalizedPhone)
    {
        return strlen(preg_replace('%\D%', '', $sNormalizedPhone)) >= self::MINIMUM_DIGIT_COUNT;
    }
}
