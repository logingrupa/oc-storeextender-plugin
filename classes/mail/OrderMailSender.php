<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use App;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Toolbox\Classes\Helper\SendMailHelper;
use Lovata\OrdersShopaholic\Models\Order;
use RainLab\Translate\Classes\Translator;

/**
 * Class OrderMailSender
 * @package Logingrupa\StoreExtender\Classes\Mail
 *
 * Sends the order mails Lovata does not: paid, payment reminders, customer cancel and the
 * switch to bank transfer. Several leave from a gateway webhook or the scheduler, where no
 * shop language is active, so the order's own site language is switched on for the render;
 * product and method names follow it through RainLab Translate.
 *
 * Follows the shop's "send email after creating order" switch and manager list, the same
 * settings Lovata's new-order mails read.
 */
class OrderMailSender
{
    /** Lovata skips the placeholder addresses it generates for guests without an email */
    const FAKE_EMAIL_PATTERN = '%^fake.*@fake\.com$%';

    /**
     * @param Order $obOrder
     * @param string $sTemplate mail template code
     * @param string $sState one of OrderMailState::STATE_LIST
     * @return bool false when the shop sends no order mails or the order has no real address
     */
    public static function sendToCustomer(Order $obOrder, string $sTemplate, string $sState): bool
    {
        $sEmail = self::getCustomerEmail($obOrder);
        if ($sEmail === '' || !self::isEnabled()) {
            return false;
        }

        self::send($obOrder, $sTemplate, $sState, $sEmail);

        return true;
    }

    /**
     * @param Order $obOrder
     * @param string $sTemplate mail template code
     * @param string $sState one of OrderMailState::STATE_LIST
     * @return bool false when the shop sends no order mails or lists no manager
     */
    public static function sendToManagers(Order $obOrder, string $sTemplate, string $sState): bool
    {
        $sEmailList = trim((string) Settings::getValue('creating_order_manager_email_list'));
        if ($sEmailList === '' || !self::isEnabled()) {
            return false;
        }

        self::send($obOrder, $sTemplate, $sState, $sEmailList);

        return true;
    }

    /**
     * @param Order $obOrder
     * @return string empty when the order has no real address
     */
    public static function getCustomerEmail(Order $obOrder): string
    {
        $sEmail = trim((string) array_get((array) $obOrder->property, 'email'));

        return preg_match(self::FAKE_EMAIL_PATTERN, $sEmail) ? '' : $sEmail;
    }

    /**
     * @return bool
     */
    protected static function isEnabled(): bool
    {
        return (bool) Settings::getValue('send_email_after_creating_order');
    }

    /**
     * @param Order $obOrder
     * @param string $sTemplate
     * @param string $sState
     * @param string $sEmailList comma separated
     * @return void
     */
    protected static function send(Order $obOrder, string $sTemplate, string $sState, string $sEmailList)
    {
        $arMailData = [
            'order' => $obOrder,
            'order_number' => $obOrder->order_number,
            'site_url' => config('app.url'),
        ] + OrderMailData::make($obOrder, $sState);

        $obTranslator = Translator::instance();
        $sPreviousAppLocale = App::getLocale();
        $sPreviousTranslatorLocale = $obTranslator->getLocale() ?: $sPreviousAppLocale;
        $obTranslator->setLocale($arMailData['_current_locale'], false);

        try {
            SendMailHelper::instance()->send($sTemplate, $sEmailList, $arMailData);
        } finally {
            $obTranslator->setLocale($sPreviousTranslatorLocale, false);
            App::setLocale($sPreviousAppLocale);
        }
    }
}
