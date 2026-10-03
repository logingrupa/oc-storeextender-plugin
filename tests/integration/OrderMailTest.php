<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Plugin;
use Logingrupa\StoreExtender\Classes\Mail\OrderMailState;
use Lovata\Shopaholic\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Classes\Helper\AbstractPaymentGateway;
use System\Classes\MailManager;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * .lt order 434 was paid by card through Paysera and the customer still got bank transfer
 * instructions: the new-order mail goes out before the gateway redirect, and the view chose
 * the bank block from the order status alone. These cases pin the payment method as the
 * deciding input, the paid confirmation that follows the gateway, and the five shop locales.
 */
class OrderMailTest extends StoreExtenderPluginTestCase
{
    /** Rendering needs the core mail tables only, which the base case migrates early */
    protected $autoMigrate = false;

    const SHOP_LOCALE_LIST = ['en', 'lv', 'lt', 'ru', 'nb-no'];

    const ORDER_NUMBER = '261002-0001';

    const IBAN = 'LT197300010152721946';

    /** @var array list of [view, data, recipient list] captured from the mailer */
    protected $arSentMailList = [];

    public function setUp(): void
    {
        parent::setUp();

        Config::set('multisite.features.backend_mail_template', true);

        $obPlugin = new Plugin(App::make('app'));
        MailManager::instance()->registerMailTemplates($obPlugin->registerMailTemplates());
        MailManager::instance()->registerMailPartials($obPlugin->registerMailPartials());
    }

    public function testNewOrderStateFollowsThePaymentMethodNotTheStatus()
    {
        $this->assertSame(OrderMailState::PENDING, OrderMailState::forNewOrder($this->makeOrder('PayseraCheckout')));
        $this->assertSame(OrderMailState::PENDING, OrderMailState::forNewOrder($this->makeOrder('PayseraCheckout', ['out_of_stock' => '1'])));
        $this->assertSame(OrderMailState::BANK, OrderMailState::forNewOrder($this->makeOrder('', [], 'bank')));
        $this->assertSame(OrderMailState::STORE, OrderMailState::forNewOrder($this->makeOrder('', [], 'pay-at-store')));
        $this->assertSame(OrderMailState::INVOICE, OrderMailState::forNewOrder($this->makeOrder('', ['out_of_stock' => '1'], 'bank')));
    }

    public function testPayAtStoreMailCarriesNoBankDetailsAndOffersToPayOnline()
    {
        $obEmail = $this->render(Plugin::MAIL_ORDER_CREATED_USER, OrderMailState::STORE, 'lv');
        $sHtml = (string) $obEmail->getHtmlBody();

        $this->assertStringContainsString('Apmaksa saņemot', $sHtml);
        $this->assertStringContainsString('Maksāt tiešsaistē', $sHtml);
        $this->assertStringNotContainsString('Bankas pārskaitījuma rekvizīti', $sHtml);
        $this->assertStringNotContainsString(self::IBAN, $sHtml);
    }

    public function testUnknownStateIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        OrderMailState::assertKnown('awaiting');
    }

    public function testOnlinePaymentOrderMailCarriesNoBankDetails()
    {
        $obEmail = $this->render(Plugin::MAIL_ORDER_CREATED_USER, OrderMailState::PENDING, 'lt');
        $sHtml = (string) $obEmail->getHtmlBody();

        $this->assertSame('Užsakymas '.self::ORDER_NUMBER.' gautas', $obEmail->getSubject());
        $this->assertStringContainsString('Laukiama apmokėjimo', $sHtml);
        $this->assertStringNotContainsString('Banko pavedimo rekvizitai', $sHtml);
        $this->assertStringNotContainsString(self::IBAN, $sHtml);
        $this->assertStringNotContainsString(self::IBAN, (string) $obEmail->getTextBody());
    }

    public function testBankTransferOrderMailCarriesTheBankDetails()
    {
        $obEmail = $this->render(Plugin::MAIL_ORDER_CREATED_USER, OrderMailState::BANK, 'lt');
        $sHtml = (string) $obEmail->getHtmlBody();

        $this->assertStringContainsString('Banko pavedimo rekvizitai', $sHtml);
        $this->assertStringContainsString(self::IBAN, $sHtml);
        $this->assertStringContainsString('€8.18', $sHtml);
        $this->assertStringContainsString(self::IBAN, (string) $obEmail->getTextBody());
    }

    public function testEveryOrderMailRendersInEveryShopLocale()
    {
        $arCaseList = [
            [Plugin::MAIL_ORDER_CREATED_USER, OrderMailState::BANK],
            [Plugin::MAIL_ORDER_CREATED_MANAGER, OrderMailState::PENDING],
            [Plugin::MAIL_ORDER_PAID_USER, OrderMailState::PAID],
            [Plugin::MAIL_ORDER_PAID_MANAGER, OrderMailState::PAID],
            [Plugin::MAIL_ORDER_METHOD_CHANGED_USER, OrderMailState::BANK],
            [Plugin::MAIL_ORDER_METHOD_CHANGED_USER, OrderMailState::STORE],
            [Plugin::MAIL_ORDER_CREATED_MANAGER, OrderMailState::STORE],
            [Plugin::MAIL_ORDER_CANCELED_USER, OrderMailState::CANCELED],
            [Plugin::MAIL_ORDER_CANCELED_MANAGER, OrderMailState::CANCELED],
            [Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, OrderMailState::REMINDER_FIRST],
            [Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, OrderMailState::REMINDER_LAST],
        ];

        foreach (self::SHOP_LOCALE_LIST as $sLocale) {
            foreach ($arCaseList as [$sCode, $sState]) {
                $obEmail = $this->render($sCode, $sState, $sLocale);
                $sLabel = $sLocale.' '.$sCode;

                $this->assertStringContainsString(self::ORDER_NUMBER, $obEmail->getSubject(), $sLabel);
                $this->assertStringNotContainsString('order_mail.', $obEmail->getSubject(), $sLabel.' subject prints a raw key');
                $this->assertStringNotContainsString('order_mail.', (string) $obEmail->getHtmlBody(), $sLabel.' body prints a raw key');
                $this->assertStringNotContainsString('Missing partial', (string) $obEmail->getHtmlBody(), $sLabel);
            }
        }
    }

    public function testEveryLocaleTranslatesEveryKey()
    {
        $arEnglishKeyList = array_keys(array_dot(require __DIR__.'/../../lang/en/order_mail.php'));

        foreach (array_diff(self::SHOP_LOCALE_LIST, ['en']) as $sLocale) {
            $arKeyList = array_keys(array_dot(require __DIR__.'/../../lang/'.$sLocale.'/order_mail.php'));

            $this->assertSame([], array_values(array_diff($arEnglishKeyList, $arKeyList)), $sLocale.' misses keys');
        }
    }

    public function testLastReminderOffersToCancelBehindAConfirmationPage()
    {
        $sHtml = (string) $this->render(Plugin::MAIL_ORDER_PAYMENT_REMINDER_USER, OrderMailState::REMINDER_LAST, 'lt')->getHtmlBody();

        $this->assertStringContainsString('/checkout/6b1763d7a3578d710e58b3c18b2e0555?cancel=1', $sHtml);
        $this->assertStringContainsString('Užbaigti mokėjimą', $sHtml);
    }

    public function testBankMailOffersToPayOnlineInstead()
    {
        $sHtml = (string) $this->render(Plugin::MAIL_ORDER_CREATED_USER, OrderMailState::BANK, 'lv')->getHtmlBody();

        $this->assertStringContainsString('Maksāt tiešsaistē', $sHtml);
    }

    public function testDarkModeRulesSurviveTheCssInliner()
    {
        $sHtml = (string) $this->render(Plugin::MAIL_ORDER_PAID_USER, OrderMailState::PAID, 'lv')->getHtmlBody();

        $this->assertStringContainsString('prefers-color-scheme: dark', $sHtml);
        $this->assertStringContainsString('name="color-scheme"', $sHtml);
    }

    public function testPaymentSuccessSendsOnePaidMailToTheCustomerAndOneToTheManagers()
    {
        $this->enableOrderMails('manager@example.com');
        $this->captureMail();

        $obOrder = $this->makeOrder('PayseraCheckout', ['email' => 'buyer@example.com']);
        $this->markStatusChanged($obOrder, 1, 5);

        Event::fire(AbstractPaymentGateway::EVENT_PAYMENT_SUCCESS, [$obOrder]);

        $this->assertSame(
            [
                [Plugin::MAIL_ORDER_PAID_USER, ['buyer@example.com']],
                [Plugin::MAIL_ORDER_PAID_MANAGER, ['manager@example.com']],
            ],
            array_map(fn ($arSent) => [$arSent[0], $arSent[2]], $this->arSentMailList)
        );
        $this->assertSame(OrderMailState::PAID, $this->arSentMailList[0][1]['mail_state']);
    }

    public function testRepeatedGatewayCallbackSendsNothing()
    {
        $this->enableOrderMails('manager@example.com');
        $this->captureMail();

        $obOrder = $this->makeOrder('PayseraCheckout', ['email' => 'buyer@example.com']);
        $this->markStatusChanged($obOrder, 5, 5);

        Event::fire(AbstractPaymentGateway::EVENT_PAYMENT_SUCCESS, [$obOrder]);

        $this->assertSame([], $this->arSentMailList);
    }

    public function testPlaceholderGuestAddressGetsNoPaidMail()
    {
        $this->enableOrderMails('');
        $this->captureMail();

        $obOrder = $this->makeOrder('PayseraCheckout', ['email' => 'fake123@fake.com']);
        $this->markStatusChanged($obOrder, 1, 5);

        Event::fire(AbstractPaymentGateway::EVENT_PAYMENT_SUCCESS, [$obOrder]);

        $this->assertSame([], $this->arSentMailList);
    }

    /**
     * @param string $sGatewayId
     * @param array $arProperty
     * @param string $sMethodCode
     * @return Order
     */
    protected function makeOrder($sGatewayId, $arProperty = [], $sMethodCode = 'card')
    {
        $obPaymentMethod = new PaymentMethod();
        $obPaymentMethod->gateway_id = $sGatewayId;
        $obPaymentMethod->code = $sMethodCode;

        $obOrder = new Order();
        $obOrder->id = 434;
        $obOrder->order_number = self::ORDER_NUMBER;
        $obOrder->property = $arProperty;
        $obOrder->setRelation('payment_method', $obPaymentMethod);

        return $obOrder;
    }

    /**
     * Stands in for the save inside AbstractPaymentGateway::setSuccessStatus().
     * @param Order $obOrder
     * @param int $iFromStatusId
     * @param int $iToStatusId
     */
    protected function markStatusChanged($obOrder, $iFromStatusId, $iToStatusId)
    {
        $obOrder->status_id = $iFromStatusId;
        $obOrder->syncOriginal();
        $obOrder->status_id = $iToStatusId;
        $obOrder->syncChanges();
    }

    /**
     * @param string $sManagerEmailList
     */
    protected function enableOrderMails($sManagerEmailList)
    {
        Settings::set('send_email_after_creating_order', true);
        Settings::set('creating_order_manager_email_list', $sManagerEmailList);
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

            $this->arSentMailList[] = [$sView, $arData, $arRecipientList];

            return false;
        });
    }

    /**
     * Renders through the mail manager the way the mailer does, with an order shaped like
     * .lt order 434. Twig reads arrays and models alike, so no order tables are needed.
     * @param string $sCode
     * @param string $sState
     * @param string $sLocale
     * @return Email
     */
    protected function render($sCode, $sState, $sLocale)
    {
        $arOrder = [
            'id' => 434,
            'order_number' => self::ORDER_NUMBER,
            'secret_key' => '6b1763d7a3578d710e58b3c18b2e0555',
            'created_at' => '2026-10-02 08:07:23',
            'currency_symbol' => '€',
            'total_price' => '8.18',
            'position_total_price' => '8.18',
            'shipping_price' => '0.00',
            'shipping_price_value' => 0,
            'payment_method' => ['name' => 'Mokėkite per Paysera'],
            'shipping_type' => ['name' => 'Pasiimkite prekę NAI_S kosmetikos parduotuvėje'],
            'property' => [
                'email' => 'buyer@example.com',
                'name' => 'Ona',
                'last_name' => 'Petraitė',
                'phone' => '+370 600 00000',
                'shipping_address2' => 'Šv. Gertrūdos g. 6, Kaunas - NEMOKAMAI',
            ],
            'order_position' => [[
                'quantity' => 1,
                'price' => '8.18',
                'total_price' => '8.18',
                'item' => ['name' => 'Quick Top viršutinis sluoksnis (8ml)', 'preview_image' => null],
            ]],
        ];

        $arData = [
            'order' => $arOrder,
            'order_number' => self::ORDER_NUMBER,
            'site_url' => 'https://nailscosmetics.lt',
            'mail_state' => $sState,
            'mail_view' => OrderMailState::view($sState),
            'can_pay_online' => OrderMailState::view($sState)['pay_online'],
            '_current_locale' => $sLocale,
            'backend_order_url' => 'https://nailscosmetics.lt/back/lovata/ordersshopaholic/orders/update/434',
            'bank_detail_list' => $sState === OrderMailState::BANK
                ? [
                    ['label' => '', 'value' => 'Jungle Fever LT, MB', 'copy' => ''],
                    ['label' => 'Konts', 'value' => self::IBAN.', EUR', 'copy' => self::IBAN],
                ]
                : [],
        ];

        $obMessage = new Message(new Email);
        $this->assertTrue(MailManager::instance()->addContentToMailer($obMessage, $sCode, $arData), $sCode.' did not resolve');

        return $obMessage->getSymfonyMessage();
    }
}
