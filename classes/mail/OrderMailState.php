<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use Lovata\OrdersShopaholic\Models\Order;
use Logingrupa\StoreExtender\Classes\Helper\OrderPaymentState;

/**
 * Class OrderMailState
 * @package Logingrupa\StoreExtender\Classes\Mail
 *
 * What an order mail tells the reader. Picks the copy, the badge and the call to action
 * in the order mail views.
 */
class OrderMailState
{
    const BANK = OrderPaymentState::BANK;
    const STORE = OrderPaymentState::STORE;
    const INVOICE = OrderPaymentState::INVOICE;
    const PENDING = OrderPaymentState::PENDING;
    const PAID = OrderPaymentState::PAID;
    const CANCELED = OrderPaymentState::CANCELED;

    /** Online payment still missing an hour after the order */
    const REMINDER_FIRST = 'reminder_first';

    /** Online payment still missing a day after the order, the mail offers to cancel */
    const REMINDER_LAST = 'reminder_last';

    /** Badge tone and which blocks the mail shows per state */
    const VIEW_MAP = [
        self::BANK => ['tone' => 'pending', 'action' => '', 'bank_details' => true, 'pay_online' => true, 'cancel' => false, 'manager_title' => 'title_new'],
        self::STORE => ['tone' => 'neutral', 'action' => 'view_order', 'bank_details' => false, 'pay_online' => true, 'cancel' => false, 'manager_title' => 'title_new'],
        self::INVOICE => ['tone' => 'neutral', 'action' => 'view_order', 'bank_details' => false, 'pay_online' => false, 'cancel' => false, 'manager_title' => 'title_new'],
        self::PENDING => ['tone' => 'pending', 'action' => 'view_order', 'bank_details' => false, 'pay_online' => false, 'cancel' => false, 'manager_title' => 'title_new'],
        self::PAID => ['tone' => 'paid', 'action' => 'view_order', 'bank_details' => false, 'pay_online' => false, 'cancel' => false, 'manager_title' => 'title_paid'],
        self::CANCELED => ['tone' => 'neutral', 'action' => 'view_order', 'bank_details' => false, 'pay_online' => false, 'cancel' => false, 'manager_title' => 'title_canceled'],
        self::REMINDER_FIRST => ['tone' => 'pending', 'action' => 'complete_payment', 'bank_details' => false, 'pay_online' => false, 'cancel' => false, 'manager_title' => ''],
        self::REMINDER_LAST => ['tone' => 'pending', 'action' => 'complete_payment', 'bank_details' => false, 'pay_online' => false, 'cancel' => true, 'manager_title' => ''],
    ];

    /**
     * @param string $sState
     * @return array the VIEW_MAP row
     */
    public static function view(string $sState): array
    {
        self::assertKnown($sState);

        return self::VIEW_MAP[$sState];
    }

    /**
     * @param Order $obOrder
     * @return string BANK, STORE, INVOICE or PENDING
     */
    public static function forNewOrder(Order $obOrder): string
    {
        return OrderPaymentState::atCreation($obOrder);
    }

    /**
     * @param string $sState
     * @return void
     */
    public static function assertKnown(string $sState)
    {
        if (!array_key_exists($sState, self::VIEW_MAP)) {
            throw new \InvalidArgumentException(
                "Unknown order mail state \"{$sState}\". Supported: [".implode(', ', array_keys(self::VIEW_MAP)).']'
            );
        }
    }
}
