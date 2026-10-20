<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;
use Lovata\OrdersShopaholic\Models\Cart;

/**
 * Class AccountCart
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * The cart row CartProcessor::findUserCart binds the cookie to on the account's next
 * request: the first getByUser row, created when the account has none.
 */
final class AccountCart
{
    /**
     * @param int $iUserID
     * @return Cart
     * @throws InvalidArgumentException when the user ID is not a persisted account
     */
    public static function forUser(int $iUserID): Cart
    {
        if ($iUserID < 1) {
            throw new InvalidArgumentException('An account cart needs a user ID above 0, got: '.$iUserID);
        }

        return Cart::getByUser($iUserID)->first() ?: Cart::create(['user_id' => $iUserID]);
    }
}
