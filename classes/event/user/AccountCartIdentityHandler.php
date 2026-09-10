<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Logingrupa\StoreExtender\Classes\Event\Cart\CartComponentHandler;
use Lovata\OrdersShopaholic\Models\Cart;

/**
 * Class AccountCartIdentityHandler
 * @package Logingrupa\StoreExtender\Classes\Event\User
 *
 * Copies the account name, last name, email and phone onto the account cart
 * row at login, registration and logout. A logged-in checkout never posts
 * Cart::onSaveData, so without this the row stays empty and a signed-out visitor
 * loses both the checkout prefill (Cart.getSavedUserData) and the Meta identity
 * (GuestCheckoutIdentityHandler) that a guest keeps from typing the same fields.
 * The cart cookie outlives the session, so the row is what the browser reads next.
 */
class AccountCartIdentityHandler
{
    const EVENT_LOGIN = 'rainlab.user.login';
    const EVENT_REGISTER = 'rainlab.user.register';
    const EVENT_LOGOUT = 'rainlab.user.logout';

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     */
    public function subscribe($obEvent)
    {
        // Event::fire passes the user alone, fireSystemEvent passes the component first
        $obEvent->listen(self::EVENT_LOGIN, function ($obUser) {
            $this->rememberOnCart($obUser);
        });
        $obEvent->listen(self::EVENT_REGISTER, function ($obComponent, $obUser) {
            $this->rememberOnCart($obUser);
        });
        $obEvent->listen(self::EVENT_LOGOUT, function ($obComponent, $obUser) {
            $this->rememberOnCart($obUser);
        });
    }

    /**
     * Merge the account fields into user_data of the account cart; a field the
     * account leaves empty keeps whatever the row already holds.
     * @param \RainLab\User\Models\User|null $obUser
     * @return void
     */
    public function rememberOnCart($obUser): void
    {
        if (!is_object($obUser) || empty($obUser->id)) {
            return;
        }

        $arAccountData = CartComponentHandler::savedUserData([
            'name'      => $obUser->getAttribute('first_name') ?: $obUser->getAttribute('name'),
            'last_name' => $obUser->getAttribute('last_name'),
            'email'     => $obUser->getAttribute('email'),
            'phone'     => $obUser->getAttribute('phone'),
        ]);
        if (empty($arAccountData)) {
            return;
        }

        // Same row CartProcessor::findUserCart() binds the cookie to on the next request
        $obCart = Cart::getByUser($obUser->id)->first() ?: Cart::create(['user_id' => $obUser->id]);

        $obCart->user_data = array_merge((array) $obCart->user_data, $arAccountData);
        $obCart->email = array_get($obCart->user_data, 'email');
        $obCart->save();
    }
}
