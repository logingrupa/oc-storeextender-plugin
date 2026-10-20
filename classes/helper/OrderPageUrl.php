<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;
use Cms\Classes\Page;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class OrderPageUrl
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Builds the order-complete page URL (/checkout/:slug) of an order from its secret key,
 * through the same Page::url call MakeOrder uses for its own success redirect.
 */
final class OrderPageUrl
{
    const PAGE = 'order-complete';

    /**
     * @param Order $obOrder
     * @return string
     * @throws InvalidArgumentException when the order has no secret key
     */
    public static function make(Order $obOrder): string
    {
        if (empty($obOrder->secret_key)) {
            throw new InvalidArgumentException('Order '.$obOrder->id.' has no secret key, so it has no order page URL');
        }

        return Page::url(self::PAGE, ['slug' => $obOrder->secret_key]);
    }
}
