<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Auth;
use RainLab\User\Helpers\User as RainLabUserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserPhoneLookup;

/**
 * Class PhoneLoginHandler
 * @package Logingrupa\StoreExtender\Classes\Event\User
 *
 * Lets the login form take a phone number where it asks for the email. The checkout
 * offers "log in" when a typed phone already belongs to an account and hands that
 * phone to the login page, so the visitor only adds the password; the matching
 * email never leaves the server. Several accounts can share a number (Buddies
 * migration), the password decides which one signs in.
 *
 * rainlab.user.beforeAuthenticate: a returned user is signed in, false fails the
 * attempt (rate limited by RainLab on the typed value), null leaves the email path alone.
 */
class PhoneLoginHandler
{
    const EVENT_BEFORE_AUTHENTICATE = 'rainlab.user.beforeAuthenticate';

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(self::EVENT_BEFORE_AUTHENTICATE, function ($obComponent, array $arInput) {
            return $this->authenticateByPhone($arInput);
        });
    }

    /**
     * @param array $arInput posted login form
     * @return \RainLab\User\Models\User|false|null
     */
    public function authenticateByPhone(array $arInput)
    {
        $sLogin = trim((string) array_get($arInput, RainLabUserHelper::username()));
        $sPassword = (string) array_get($arInput, 'password');
        if ($sPassword === '' || !$this->looksLikePhone($sLogin)) {
            return null;
        }

        $obUserList = UserPhoneLookup::instance()->findUsers($sLogin);
        if ($obUserList === null || $obUserList->isEmpty()) {
            return false;
        }

        foreach ($obUserList as $obUser) {
            if (Auth::validate(['id' => $obUser->id, 'password' => $sPassword])) {
                return $obUser;
            }
        }

        return false;
    }

    /**
     * Digits with the usual separators and an optional leading plus; anything
     * with an at sign or letters is left to the email login.
     * @param string $sLogin
     * @return bool
     */
    public function looksLikePhone(string $sLogin): bool
    {
        return $sLogin !== '' && preg_match('%^\+?[\d\s().-]+$%', $sLogin) === 1;
    }
}
