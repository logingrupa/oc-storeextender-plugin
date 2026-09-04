<?php namespace Logingrupa\StoreExtender\Classes\Event\Metapixel;

use Auth;
use Cookie;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Event\AccountIdentityHandler;
use Logingrupa\Metapixel\Models\Settings as MetapixelSettings;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * Class GuestCheckoutIdentityHandler
 *
 * Answers Metapixel's user_data.resolve hook for a guest whose cart row holds
 * the name, last name, email and phone typed into checkout. Logged-in accounts
 * belong to Metapixel's own AccountIdentityHandler, which also owns the
 * consent toggle and dial code this handler reads.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Metapixel
 */
class GuestCheckoutIdentityHandler
{
    /** String literal on purpose: registers without a hard dependency on Logingrupa.Metapixel */
    const HOOK_USER_DATA_RESOLVE = 'metapixel.user_data.resolve';

    /**
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(self::HOOK_USER_DATA_RESOLVE, function (array &$arUserData): void {
            $this->fillFromGuestCart($arUserData);
        });
    }

    /**
     * Fill only the keys the hook left empty; country is never supplied.
     * @param array $arUserData
     * @return void
     */
    public function fillFromGuestCart(array &$arUserData): void
    {
        if (Auth::check() || !$this->identityEnabled()) {
            return;
        }

        $arCartUserData = $this->cartUserData();
        if (empty($arCartUserData)) {
            return;
        }

        foreach ($this->identityFromCart($arCartUserData) as $sKey => $sValue) {
            if (empty($arUserData[$sKey])) {
                $arUserData[$sKey] = $sValue;
            }
        }
    }

    /**
     * Raw identity keys from the checkout fields on the cart; empty values are absent.
     * @param array $arCartUserData
     * @return array
     */
    public function identityFromCart(array $arCartUserData): array
    {
        $sDialCode = (string) MetapixelSettings::get('account_phone_dial_code', '');
        $sPhone = $this->stringValue(array_get($arCartUserData, 'phone'));

        $arIdentity = [
            'em' => $this->stringValue(array_get($arCartUserData, 'email')),
            'fn' => $this->stringValue(array_get($arCartUserData, 'name')),
            'ln' => $this->stringValue(array_get($arCartUserData, 'last_name')),
            'ph' => (new AccountIdentityHandler)->internationalPhone($sPhone, $sDialCode),
        ];

        return array_filter($arIdentity, static fn (string $sValue): bool => $sValue !== '');
    }

    /**
     * Same consent toggle as Metapixel's account listener; off without Metapixel.
     * @return bool
     */
    protected function identityEnabled(): bool
    {
        return class_exists(MetapixelSettings::class) && (bool) MetapixelSettings::get('account_identity_enabled', false);
    }

    /**
     * Cart row by cookie id, straight from the table: CartProcessor::instance()
     * would create a cart row for every visitor without one.
     * @return array
     */
    protected function cartUserData(): array
    {
        $sCartId = (string) Cookie::get(CartProcessor::COOKIE_NAME);
        if (!ctype_digit($sCartId) || (int) $sCartId <= 0) {
            return [];
        }

        $sUserData = DB::table('lovata_orders_shopaholic_carts')->where('id', (int) $sCartId)->value('user_data');

        return is_string($sUserData) ? (array) json_decode($sUserData, true) : [];
    }

    /**
     * @param mixed $mValue
     * @return string
     */
    protected function stringValue($mValue): string
    {
        return is_scalar($mValue) ? trim((string) $mValue) : '';
    }
}
