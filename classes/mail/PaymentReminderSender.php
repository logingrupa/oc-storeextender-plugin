<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use Db;
use Carbon\Carbon;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Logingrupa\StoreExtender\Plugin;
use Logingrupa\StoreExtender\Classes\Helper\OrderStatusCode;

/**
 * Class PaymentReminderSender
 * @package Logingrupa\StoreExtender\Classes\Mail
 *
 * Reminds customers of online orders whose payment never arrived: once an hour after the
 * order, once a day after it with the offer to cancel. Each order gets each stage at most
 * once; the log row is claimed before the mail leaves, so two overlapping runs cannot
 * both send.
 *
 * Orders whose customer placed a newer order since are skipped: they paid again with a
 * fresh order and a reminder would ask them to pay twice.
 */
class PaymentReminderSender
{
    const LOG_TABLE = 'logingrupa_storeextender_order_payment_reminders';

    /** Mail state => [minimum order age, maximum order age] in minutes */
    const STAGE_AGE_MAP = [
        OrderMailState::REMINDER_FIRST => [60, 23 * 60],
        OrderMailState::REMINDER_LAST => [24 * 60, 47 * 60],
    ];

    /**
     * @param Carbon $obNow
     * @return array mail state => number of reminders sent
     */
    public static function sendDue(Carbon $obNow): array
    {
        $arSentCount = [];
        foreach (array_keys(self::STAGE_AGE_MAP) as $sStage) {
            $arSentCount[$sStage] = self::sendStage($sStage, $obNow);
        }

        return $arSentCount;
    }

    /**
     * @param string $sStage
     * @param Carbon $obNow
     * @return int
     */
    protected static function sendStage(string $sStage, Carbon $obNow): int
    {
        $iSentCount = 0;
        foreach (self::findDueOrderList($sStage, $obNow) as $obOrder) {
            if (self::hasNewerOrderFromSameCustomer($obOrder) || !self::claim($obOrder, $sStage, $obNow)) {
                continue;
            }

            if (OrderMailSender::sendToCustomer($obOrder, Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, $sStage)) {
                $iSentCount++;
            }
        }

        return $iSentCount;
    }

    /**
     * @param string $sStage
     * @param Carbon $obNow
     * @return \October\Rain\Database\Collection<Order>
     */
    protected static function findDueOrderList(string $sStage, Carbon $obNow)
    {
        [$iMinAgeMinutes, $iMaxAgeMinutes] = self::STAGE_AGE_MAP[$sStage];
        $arUnpaidStatusIdList = Status::whereIn('code', OrderStatusCode::ONLINE_UNPAID_LIST)->pluck('id')->all();

        return Order::whereIn('status_id', $arUnpaidStatusIdList)
            ->whereNull('transaction_id')
            ->whereBetween('created_at', [$obNow->copy()->subMinutes($iMaxAgeMinutes), $obNow->copy()->subMinutes($iMinAgeMinutes)])
            ->whereHas('payment_method', fn ($obQuery) => $obQuery->where('gateway_id', '!=', ''))
            ->whereNotExists(fn ($obQuery) => $obQuery->from(self::LOG_TABLE)
                ->whereColumn(self::LOG_TABLE.'.order_id', 'lovata_orders_shopaholic_orders.id')
                ->where(self::LOG_TABLE.'.stage', $sStage))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Order $obOrder
     * @return bool
     */
    protected static function hasNewerOrderFromSameCustomer(Order $obOrder): bool
    {
        $sEmail = OrderMailSender::getCustomerEmail($obOrder);
        if ($sEmail === '') {
            return false;
        }

        return Order::where('id', '>', $obOrder->id)->where('property->email', $sEmail)->exists();
    }

    /**
     * @param Order $obOrder
     * @param string $sStage
     * @param Carbon $obNow
     * @return bool false when another run already claimed this stage of the order
     */
    protected static function claim(Order $obOrder, string $sStage, Carbon $obNow): bool
    {
        $arRow = ['order_id' => $obOrder->id, 'stage' => $sStage, 'sent_at' => $obNow];

        // The unique (order_id, stage) key ignores the row when another run owns this reminder
        return Db::table(self::LOG_TABLE)->insertOrIgnore($arRow) === 1;
    }
}
