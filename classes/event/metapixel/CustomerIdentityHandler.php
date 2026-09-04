<?php namespace Logingrupa\StoreExtender\Classes\Event\Metapixel;

use Auth;
use Cookie;
use Event;
use Illuminate\Support\Facades\DB;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use RainLab\User\Models\User;

/**
 * Class CustomerIdentityHandler
 *
 * Supplies the visitor's raw identity to Metapixel's metapixel.user_data.resolve
 * hook, so ViewContent, AddToCart, PageView and Search reach Meta with the same
 * customer keys Purchase already carries. Metapixel hashes every value; nothing
 * leaves the box in clear text. Adapter-supplied values win on merge, so the
 * Purchase event keeps its Order data.
 *
 * Consent rule: identity is sent for a logged-in RainLab.User account, or for a
 * guest whose current cart holds the email or phone typed at checkout. Nothing
 * is guessed from the session.
 *
 * Phone numbers stored without a country code (8 digits, the national mobile
 * format on every shop this plugin serves) get the shop's own dial code from the
 * app URL top-level domain, because Meta matches on the international form.
 * The checkout stores the address country as a free-text label, so country is
 * never supplied; city and postcode come from the latest address that has them.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Metapixel
 */
class CustomerIdentityHandler
{
    /** String literal on purpose: registers without a hard dependency on Logingrupa.Metapixel */
    const HOOK_USER_DATA_RESOLVE = 'metapixel.user_data.resolve';

    /** Dial code per shop, keyed by the app URL top-level domain */
    const DIAL_CODE_BY_TLD = ['lv' => '371', 'no' => '47', 'lt' => '370'];

    /** A number this long carries no country code yet */
    const NATIONAL_NUMBER_LENGTH = 8;

    /**
     * Subscribe to the Metapixel identity hook.
     */
    public function subscribe()
    {
        Event::listen(self::HOOK_USER_DATA_RESOLVE, function (&$arUserData) {
            $arUserData = $this->fillEmptyKeys((array) $arUserData, $this->resolve());
        });
    }

    /**
     * Raw identity of the current visitor, empty for an anonymous one.
     * @return array
     */
    public function resolve(): array
    {
        $obUser = Auth::user();
        if ($obUser instanceof User) {
            return $this->fromUser($obUser);
        }

        return $this->fromGuestCart();
    }

    /**
     * Account fields plus the latest address that carries a city or postcode.
     * @param User $obUser
     * @return array
     */
    protected function fromUser(User $obUser): array
    {
        $arIdentity = array_filter([
            'em'          => trim((string) $obUser->email),
            'ph'          => $this->internationalPhone((string) $obUser->phone),
            'fn'          => trim((string) $obUser->first_name),
            'ln'          => trim((string) $obUser->last_name),
            'external_id' => (string) $obUser->id,
        ]);

        return $arIdentity + $this->latestAddress((int) $obUser->id);
    }

    /**
     * @param int $iUserId
     * @return array
     */
    protected function latestAddress(int $iUserId): array
    {
        $obRow = DB::table('lovata_orders_shopaholic_user_addresses')
            ->where('user_id', $iUserId)
            ->where(function ($obQuery) {
                $obQuery->where('city', '<>', '')->orWhere('postcode', '<>', '');
            })
            ->orderBy('id', 'desc')
            ->first(['city', 'postcode']);
        if (empty($obRow)) {
            return [];
        }

        return array_filter([
            'ct' => trim((string) $obRow->city),
            'zp' => trim((string) $obRow->postcode),
        ]);
    }

    /**
     * Email and phone a guest typed into checkout, kept on the cart row. Read by
     * cookie id straight from the table: CartProcessor::instance() would create a
     * cart row for every visitor without one.
     * @return array
     */
    protected function fromGuestCart(): array
    {
        $iCartId = (int) Cookie::get(CartProcessor::COOKIE_NAME);
        if ($iCartId <= 0) {
            return [];
        }

        $sUserData = DB::table('lovata_orders_shopaholic_carts')->where('id', $iCartId)->value('user_data');
        $arUserData = is_string($sUserData) ? (array) json_decode($sUserData, true) : [];

        return array_filter([
            'em' => trim((string) array_get($arUserData, 'email')),
            'ph' => $this->internationalPhone((string) array_get($arUserData, 'phone')),
        ]);
    }

    /**
     * First number of the stored list as digits with a country code, or '' when
     * the number is national and the shop's dial code is unknown.
     * @param string $sPhone
     * @return string
     */
    protected function internationalPhone(string $sPhone): string
    {
        $sFirst = (string) explode(',', $sPhone)[0];
        $sDigits = ltrim((string) preg_replace('/\D+/', '', $sFirst), '0');
        if (strlen($sDigits) !== self::NATIONAL_NUMBER_LENGTH) {
            return $sDigits;
        }

        $sDialCode = $this->shopDialCode();

        return $sDialCode === '' ? '' : $sDialCode.$sDigits;
    }

    /**
     * @return string
     */
    protected function shopDialCode(): string
    {
        $sHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $sTopLevelDomain = (string) substr((string) strrchr($sHost, '.'), 1);

        return (string) array_get(self::DIAL_CODE_BY_TLD, $sTopLevelDomain, '');
    }

    /**
     * Only keys the hook left empty are filled: an earlier listener or the
     * adapter owns anything already present.
     * @param array $arUserData
     * @param array $arIdentity
     * @return array
     */
    protected function fillEmptyKeys(array $arUserData, array $arIdentity): array
    {
        foreach ($arIdentity as $sKey => $sValue) {
            if (empty($arUserData[$sKey])) {
                $arUserData[$sKey] = $sValue;
            }
        }

        return $arUserData;
    }
}
