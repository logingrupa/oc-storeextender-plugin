<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\OrdersShopaholic\Models\Status;

/**
 * Class OrderStatusCode
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Order status codes. Ids differ per shop, codes do not, so code reads statuses by code.
 */
class OrderStatusCode
{
    const NEW = 'new';
    const IN_PROGRESS = 'in_progress';
    const PAYMENT_PENDING = 'payment-pending';
    const PAID = 'new-payment-received';
    const PAYMENT_CANCELED = 'new-payment-canceled';
    const PAYMENT_FAILED = 'new-payment-error';
    const SENT = 'sent';
    const COMPLETE = 'complete';
    const CANCELED = 'canceled';

    /** Money arrived: paid online, shipped or closed */
    const PAID_LIST = [self::PAID, self::SENT, self::COMPLETE];

    /** The gateway reported that the online payment did not happen */
    const PAYMENT_FAILED_LIST = [self::PAYMENT_CANCELED, self::PAYMENT_FAILED];

    /**
     * Statuses an unpaid online order can sit in, the ones the payment reminders chase.
     * NEW is among them: a gateway that fails before its redirect never moves the order on.
     * Callers must also require a payment method with a gateway, bank orders wait in NEW too.
     */
    const ONLINE_UNPAID_LIST = [self::NEW, self::PAYMENT_PENDING, self::PAYMENT_CANCELED, self::PAYMENT_FAILED];

    /**
     * @param string $sCode
     * @return int
     */
    public static function getId(string $sCode): int
    {
        $obStatus = Status::getByCode($sCode)->first();
        if (empty($obStatus)) {
            throw new \RuntimeException("Order status \"{$sCode}\" does not exist on this shop");
        }

        return (int) $obStatus->id;
    }
}
