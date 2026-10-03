<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/OrderStubTables.php';

use Carbon\Carbon;
use Logingrupa\StoreExtender\Plugin;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailState;
use Logingrupa\StoreExtender\Classes\Mail\PaymentReminderSender;
use Logingrupa\StoreExtender\Classes\Helper\OrderPaymentState;
use Logingrupa\RetrypaymentShopaholic\Classes\Helper\RetryPaymentHelper;
use Lovata\Shopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * Unpaid online orders get a reminder an hour and a day after the order, once each, and
 * the order page actions (switch to bank transfer, cancel) mail the customer. Orders paid
 * by bank, already paid, or replaced by a newer order from the same customer get nothing.
 */
class PaymentReminderTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const NOW = '2026-10-02 12:00:00';

    const STATUS_ID_MAP = [
        'new' => 1,
        'payment-pending' => 9,
        'new-payment-error' => 7,
        'new-payment-received' => 5,
        'canceled' => 4,
    ];

    /** @var array list of [view, mail state, recipient list] */
    protected $arSentMailList = [];

    /** @var PaymentMethod */
    protected $obCardMethod;

    /** @var PaymentMethod */
    protected $obBankMethod;

    public function setUp(): void
    {
        parent::setUp();

        OrderStubTables::create();
        foreach (self::STATUS_ID_MAP as $sCode => $iId) {
            Status::forceCreate(['id' => $iId, 'name' => $sCode, 'code' => $sCode]);
        }
        $this->obCardMethod = PaymentMethod::forceCreate(['name' => 'Card', 'code' => 'card', 'active' => true, 'gateway_id' => 'PayseraCheckout']);
        $this->obBankMethod = PaymentMethod::forceCreate(['name' => 'Bank', 'code' => 'bank', 'active' => true, 'gateway_id' => '']);

        Settings::set('send_email_after_creating_order', true);
        Settings::set('creating_order_manager_email_list', 'manager@example.com');
        $this->captureMail();
    }

    public function testFirstReminderGoesOutAnHourAfterAnUnpaidOnlineOrderOnce()
    {
        $obOrder = $this->makeOrder('payment-pending', 'buyer@example.com', 70);

        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));
        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));

        $this->assertSame([[Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, OrderMailState::REMINDER_FIRST, ['buyer@example.com']]], $this->arSentMailList);
        $this->assertSame(1, Db::table(PaymentReminderSender::LOG_TABLE)->where('order_id', $obOrder->id)->count());
    }

    public function testOnlineOrderStuckInNewAfterAGatewayErrorIsRemindedToo()
    {
        $this->makeOrder('new', 'buyer@example.com', 70);

        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));

        $this->assertSame([[Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, OrderMailState::REMINDER_FIRST, ['buyer@example.com']]], $this->arSentMailList);
    }

    public function testNoReminderBeforeTheHour()
    {
        $this->makeOrder('payment-pending', 'buyer@example.com', 50);

        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));

        $this->assertSame([], $this->arSentMailList);
    }

    public function testLastReminderGoesOutADayAfterAFailedPayment()
    {
        $this->makeOrder('new-payment-error', 'buyer@example.com', 25 * 60);

        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));

        $this->assertSame([[Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, OrderMailState::REMINDER_LAST, ['buyer@example.com']]], $this->arSentMailList);
    }

    public function testOrdersOlderThanTwoDaysAreLeftAlone()
    {
        $this->makeOrder('new-payment-error', 'buyer@example.com', 48 * 60);

        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));

        $this->assertSame([], $this->arSentMailList);
    }

    public function testPaidBankAndReplacedOrdersGetNoReminder()
    {
        $this->makeOrder('new-payment-received', 'paid@example.com', 70);
        $this->makeOrder('new', 'bank@example.com', 70, $this->obBankMethod);
        $this->makeOrder('payment-pending', 'again@example.com', 90);
        $this->makeOrder('new-payment-received', 'again@example.com', 80);

        PaymentReminderSender::sendDue(Carbon::parse(self::NOW));

        $this->assertSame([], $this->arSentMailList);
    }

    public function testSwitchingToBankTransferMailsTheBankDetails()
    {
        $obOrder = $this->makeOrder('payment-pending', 'buyer@example.com', 10);

        RetryPaymentHelper::switchToOffline($obOrder, $this->obBankMethod->id);

        $this->assertSame([[Plugin::MAIL_ORDER_METHOD_CHANGED_USER, OrderMailState::BANK, ['buyer@example.com']]], $this->arSentMailList);
        $this->assertSame(OrderPaymentState::BANK, OrderPaymentState::forOrder($obOrder->fresh()));
    }

    public function testSwitchingToPayAtStoreMailsThePickupStateWithoutBankDetails()
    {
        $obStoreMethod = PaymentMethod::forceCreate(['name' => 'Store', 'code' => 'pay-at-store', 'active' => true, 'gateway_id' => '']);
        $obOrder = $this->makeOrder('payment-pending', 'buyer@example.com', 10);

        RetryPaymentHelper::switchToOffline($obOrder, $obStoreMethod->id);

        $this->assertSame([[Plugin::MAIL_ORDER_METHOD_CHANGED_USER, OrderMailState::STORE, ['buyer@example.com']]], $this->arSentMailList);
        $this->assertSame(OrderPaymentState::STORE, OrderPaymentState::forOrder($obOrder->fresh()));
    }

    public function testCancelingMailsTheCustomerAndTheManagers()
    {
        $obOrder = $this->makeOrder('new-payment-error', 'buyer@example.com', 10);

        RetryPaymentHelper::cancel($obOrder);

        $this->assertSame([
            [Plugin::MAIL_ORDER_CANCELED_USER, OrderMailState::CANCELED, ['buyer@example.com']],
            [Plugin::MAIL_ORDER_CANCELED_MANAGER, OrderMailState::CANCELED, ['manager@example.com']],
        ], $this->arSentMailList);
        $this->assertSame(OrderPaymentState::CANCELED, OrderPaymentState::forOrder($obOrder->fresh()));
    }

    public function testOrderPaymentStateReadsTheMethodThenTheStatusCode()
    {
        $this->assertSame(OrderPaymentState::PENDING, OrderPaymentState::forOrder($this->makeOrder('payment-pending', 'a@example.com', 1)));
        $this->assertSame(OrderPaymentState::FAILED, OrderPaymentState::forOrder($this->makeOrder('new-payment-error', 'b@example.com', 1)));
        $this->assertSame(OrderPaymentState::PAID, OrderPaymentState::forOrder($this->makeOrder('new-payment-received', 'c@example.com', 1)));
        $this->assertSame(OrderPaymentState::BANK, OrderPaymentState::forOrder($this->makeOrder('new', 'd@example.com', 1, $this->obBankMethod)));
    }

    /**
     * @param string $sStatusCode
     * @param string $sEmail
     * @param int $iAgeMinutes
     * @param PaymentMethod|null $obPaymentMethod
     * @return Order
     */
    protected function makeOrder($sStatusCode, $sEmail, $iAgeMinutes, $obPaymentMethod = null)
    {
        $obOrder = Order::create([
            'status_id' => self::STATUS_ID_MAP[$sStatusCode],
            'payment_method_id' => ($obPaymentMethod ?: $this->obCardMethod)->id,
            'property' => ['email' => $sEmail],
        ]);

        $sCreatedAt = Carbon::parse(self::NOW)->subMinutes($iAgeMinutes)->toDateTimeString();
        Db::table('lovata_orders_shopaholic_orders')->where('id', $obOrder->id)->update(['created_at' => $sCreatedAt]);

        return $obOrder->fresh();
    }

    /**
     * Records every send and cancels it before the transport.
     */
    protected function captureMail()
    {
        Event::listen('mailer.beforeSend', function ($sView, $arData, $fnCallback) {
            $obMessage = new Message(new Email);
            $fnCallback($obMessage);
            $arRecipientList = array_map(fn ($obAddress) => $obAddress->getAddress(), $obMessage->getSymfonyMessage()->getTo());

            $this->arSentMailList[] = [$sView, $arData['mail_state'] ?? null, $arRecipientList];

            return false;
        });
    }
}
