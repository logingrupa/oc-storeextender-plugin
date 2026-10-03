<?php namespace Logingrupa\StoreExtender\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Mail\PaymentReminderSender;

/**
 * Remind customers of online orders whose payment never arrived, an hour and a day after
 * the order. Scheduled every ten minutes; safe to run by hand.
 * Usage: php artisan storeextender:send-payment-reminders
 */
class SendPaymentReminders extends Command
{
    /** @var string */
    protected $signature = 'storeextender:send-payment-reminders';

    /** @var string */
    protected $description = 'Send the 1 hour and 24 hour reminders for unpaid online orders';

    /**
     * @return int
     */
    public function handle(): int
    {
        foreach (PaymentReminderSender::sendDue(Carbon::now()) as $sStage => $iSentCount) {
            $this->info($sStage.': '.$iSentCount.' sent');
        }

        return 0;
    }
}
