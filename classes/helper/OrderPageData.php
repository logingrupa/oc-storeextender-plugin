<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Classes\Item\OrderItem;

/**
 * Class OrderPageData
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Twig functions for the order page (/checkout/<secret_key>). The page holds the OrderItem
 * the OrderPage component returns; the helpers read the Order model behind it.
 */
class OrderPageData
{
    /**
     * Twig: order_payment_state(obOrder)
     * @param OrderItem|Order $mOrder
     * @return string one of OrderPaymentState::STATE_LIST
     */
    public static function paymentState($mOrder): string
    {
        return OrderPaymentState::forOrder(self::toModel($mOrder));
    }

    /**
     * Twig: order_bank_details(obOrder)
     * @param OrderItem|Order $mOrder
     * @return array see BankTransferDetails::forOrder()
     */
    public static function bankDetails($mOrder): array
    {
        return BankTransferDetails::forOrder(self::toModel($mOrder));
    }

    /**
     * @param OrderItem|Order $mOrder
     * @return Order
     */
    protected static function toModel($mOrder): Order
    {
        if ($mOrder instanceof Order) {
            return $mOrder;
        }

        if ($mOrder instanceof OrderItem && $mOrder->getObject() instanceof Order) {
            return $mOrder->getObject();
        }

        throw new \InvalidArgumentException('Expected an Order or a loaded OrderItem, got '.get_debug_type($mOrder));
    }
}
