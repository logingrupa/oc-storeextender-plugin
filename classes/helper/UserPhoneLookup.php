<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\DB;
use October\Rain\Support\Traits\Singleton;

use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class UserPhoneLookup
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Answers one question: does any account already hold this phone number.
 *
 * It deliberately returns nothing but a boolean. The checkout form is public, so anything
 * richer would turn it into a lookup service that maps a phone number to a customer.
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
        $sNormalized = $this->normalize($sPhone);
        if (!$this->isLongEnough($sNormalized)) {
            return false;
        }

        $sUserModelClass = UserHelper::instance()->getUserModel();
        if (empty($sUserModelClass)) {
            return false;
        }

        $obUserModel = new $sUserModelClass();

        return DB::table($obUserModel->getTable())
            ->whereNull('deleted_at')
            ->whereRaw('FIND_IN_SET(?, `phone_short`)', [$sNormalized])
            ->exists();
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
