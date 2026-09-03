<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Request;

/**
 * Class UserIpAddressHandler
 * @package Logingrupa\StoreExtender\Classes\Event\User
 *
 * RainLab.User ships the created_ip_address / last_ip_address columns and a
 * touchIpAddress() helper, but no code of its own ever calls it - both fields stay
 * NULL unless the host application writes them. Registration stamps both fields in
 * RainLabRegistrationHandler; this handler keeps last_ip_address current on every
 * sign-in. Two-factor logins fire the same event, so they are covered too.
 */
class UserIpAddressHandler
{
    const EVENT_LOGIN = 'rainlab.user.login';

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(self::EVENT_LOGIN, function ($obUser) {
            $obUser->touchIpAddress(Request::ip());
        });
    }
}
