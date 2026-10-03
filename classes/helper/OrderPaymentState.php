<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\OrdersShopaholic\Models\Order;

/**
 * Class OrderPaymentState
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Where an order stands with its payment, read by the order mails and the order page.
 * The payment method decides between a bank transfer and an online payment; the status
 * code decides whether the money arrived or the order is canceled.
 */
class OrderPaymentState
{
    /** Pay by bank transfer, the bank details are shown */
    const BANK = 'bank';

    /** Pay on pickup in the store: no bank details, an online payment stays on offer */
    const STORE = 'store';

    /** Cart had items needing a stock check, a proforma invoice follows by hand */
    const INVOICE = 'invoice';

    /** Online payment started, the gateway has not answered yet */
    const PENDING = 'pending';

    /** The gateway reported a canceled or failed payment */
    const FAILED = 'failed';

    const PAID = 'paid';

    const CANCELED = 'canceled';

    /** Code of the bank transfer payment method, the same on every shop */
    const BANK_TRANSFER_METHOD_CODE = 'bank';

    const STATE_LIST = [self::BANK, self::STORE, self::INVOICE, self::PENDING, self::FAILED, self::PAID, self::CANCELED];

    /**
     * @param Order $obOrder
     * @return string one of STATE_LIST
     */
    public static function forOrder(Order $obOrder): string
    {
        $sStatusCode = (string) ($obOrder->status->code ?? '');
        if ($sStatusCode === OrderStatusCode::CANCELED) {
            return self::CANCELED;
        }

        if (in_array($sStatusCode, OrderStatusCode::PAID_LIST, true)) {
            return self::PAID;
        }

        if (!self::isOnlinePayment($obOrder)) {
            return self::forOfflineOrder($obOrder);
        }

        return in_array($sStatusCode, OrderStatusCode::PAYMENT_FAILED_LIST, true) ? self::FAILED : self::PENDING;
    }

    /**
     * The new-order mail goes out the moment the order is saved, before an online payment
     * has even started, so only the payment method can tell the states apart.
     * @param Order $obOrder
     * @return string BANK, STORE, INVOICE or PENDING
     */
    public static function atCreation(Order $obOrder): string
    {
        return self::isOnlinePayment($obOrder) ? self::PENDING : self::forOfflineOrder($obOrder);
    }

    /**
     * @param Order $obOrder
     * @return bool
     */
    public static function isOnlinePayment(Order $obOrder): bool
    {
        $obPaymentMethod = $obOrder->payment_method;

        return !empty($obPaymentMethod) && !empty($obPaymentMethod->gateway_id);
    }

    /**
     * Only the bank transfer method asks for a transfer; every other method without a
     * gateway (pay at store) is settled on pickup.
     * @param Order $obOrder
     * @return string BANK, STORE or INVOICE
     */
    protected static function forOfflineOrder(Order $obOrder): string
    {
        if (!empty(array_get((array) $obOrder->property, 'out_of_stock'))) {
            return self::INVOICE;
        }

        $sMethodCode = (string) ($obOrder->payment_method->code ?? '');

        return $sMethodCode === self::BANK_TRANSFER_METHOD_CODE ? self::BANK : self::STORE;
    }
}
