<?php

namespace Logingrupa\StoreExtender\Classes\Event\User;

use Illuminate\Events\Dispatcher;
use Logingrupa\StoreExtender\Classes\Helper\UserAddressHelper;

/**
 * Persists shipping and billing address sections posted alongside
 * RainLab.User's Account::onUpdateProfile form into Shopaholic's
 * UserAddress table (single source of truth).
 *
 * Only runs for address keys explicitly present in the POST payload so
 * partial profile updates never touch saved addresses.
 */
class UserAddressProfileHandler
{
    public const EVENT_UPDATE = 'rainlab.user.update';

    /**
     * @param  Dispatcher  $obEvent  untyped: October passes its own dispatcher
     */
    public function subscribe($obEvent): void
    {
        // ActionUpdateProfile returns the first non-null event response; this listener
        // must return void/null so the normal flash + redirect cycle completes.
        $obEvent->listen(self::EVENT_UPDATE, function ($obComponent, $obUser, $arInput): void {
            $this->syncProfileAddresses($obUser, $arInput);
        });
    }

    /**
     * @param  mixed  $obUser
     * @param  mixed  $arInput
     */
    public function syncProfileAddresses($obUser, $arInput): void
    {
        if (! is_object($obUser) || empty($obUser->id) || ! is_array($arInput)) {
            return;
        }

        if (array_key_exists('shipping_address', $arInput) && is_array($arInput['shipping_address'])) {
            UserAddressHelper::saveAddress($obUser, UserAddressHelper::TYPE_SHIPPING, $arInput['shipping_address']);
        }

        if (array_key_exists('billing_address', $arInput) && is_array($arInput['billing_address'])) {
            UserAddressHelper::saveAddress($obUser, UserAddressHelper::TYPE_BILLING, $arInput['billing_address']);
        }
    }
}
