<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use RuntimeException;
use InvalidArgumentException;
use Carbon\Carbon;
use Lovata\OrdersShopaholic\Models\Cart;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class PlacedOrderMemory
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Remembers the order a checkout submit placed on the cart row, so a repeated submit
 * whose first answer never reached the browser can open that order instead of an
 * empty cart.
 *
 * Every Cart AJAX answer returns cart.property verbatim (CartProcessor::getCartData)
 * and the secret key alone opens the order page, so the record holds the order id and
 * an HMAC of the key, never the key. Cart::onSaveData lets the browser write any
 * property key, so recall treats the record as untrusted input.
 */
final class PlacedOrderMemory
{
    const PROPERTY_KEY = 'storeextender_placed_order';

    const REPLAY_WINDOW_MINUTES = 10;

    /**
     * Write the record on the cart that placed the order and on the order user's cart,
     * the row the cookie moves to once a new account is logged in.
     * @param Cart  $obCart
     * @param Order $obOrder
     * @return void
     * @throws InvalidArgumentException when the order is not persisted or has no secret key
     */
    public static function remember(Cart $obCart, Order $obOrder): void
    {
        if (empty($obOrder->id) || empty($obOrder->secret_key)) {
            throw new InvalidArgumentException('Only a persisted order with a secret key can be remembered');
        }

        $arRecord = ['id' => (int) $obOrder->id, 'mac' => self::sign($obOrder->secret_key)];
        self::writeRecord($obCart, $arRecord);

        $iUserID = (int) $obOrder->user_id;
        if ($iUserID < 1 || (int) $obCart->user_id === $iUserID) {
            return;
        }

        self::writeRecord(AccountCart::forUser($iUserID), $arRecord);
    }

    /**
     * The order the record on this cart points at, when the HMAC matches the order's
     * secret key and the order is younger than the replay window.
     * @param Cart $obCart
     * @return Order|null
     */
    public static function recall(Cart $obCart): ?Order
    {
        $arRecord = array_get((array) $obCart->property, self::PROPERTY_KEY);
        if (!self::isWellFormed($arRecord)) {
            return null;
        }

        $obOrder = Order::find($arRecord['id']);
        if (empty($obOrder) || empty($obOrder->secret_key) || empty($obOrder->created_at)) {
            return null;
        }

        if (!hash_equals(self::sign($obOrder->secret_key), $arRecord['mac'])) {
            return null;
        }

        if ($obOrder->created_at->lt(Carbon::now()->subMinutes(self::REPLAY_WINDOW_MINUTES))) {
            return null;
        }

        return $obOrder;
    }

    /**
     * @param Cart  $obCart
     * @param array $arRecord
     * @return void
     */
    private static function writeRecord(Cart $obCart, array $arRecord): void
    {
        $obCart->property = array_merge((array) $obCart->property, [self::PROPERTY_KEY => $arRecord]);
        $obCart->save();
    }

    /**
     * @param mixed $arRecord client-writable cart property value
     * @return bool
     */
    private static function isWellFormed($arRecord): bool
    {
        if (!is_array($arRecord)) {
            return false;
        }

        $iOrderID = $arRecord['id'] ?? null;
        $sMac = $arRecord['mac'] ?? null;

        return is_int($iOrderID) && $iOrderID > 0 && is_string($sMac) && $sMac !== '';
    }

    /**
     * @param string $sSecretKey
     * @return string
     * @throws RuntimeException when the install has no APP_KEY
     */
    private static function sign(string $sSecretKey): string
    {
        $sAppKey = (string) config('app.key');
        if ($sAppKey === '') {
            throw new RuntimeException('APP_KEY is empty, the placed order record cannot be signed');
        }

        return hash_hmac('sha256', $sSecretKey, $sAppKey);
    }
}
