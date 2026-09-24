<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use System\Models\SiteDefinition;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTablePaymentMethodsBankPreviewText
 *
 * The bank transfer payment method promised payment "via the online bank" in every locale.
 * The shops have no bank link, the customer pays the invoice by a manual transfer, so the
 * preview text now says that. Written through the Translatable behavior: the site default
 * locale lands in the base column, the other site locales in rainlab_translate_attributes.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTablePaymentMethodsBankPreviewText extends Migration
{
    const PAYMENT_METHOD_CODE = 'bank';

    const PREVIEW_TEXT_LIST = [
        'lv'    => 'Pēc pasūtījuma noformēšanas rēķinu varēsiet apmaksāt ar manuālu bankas pārskaitījumu.',
        'lt'    => 'Pateikę užsakymą, sąskaitą galėsite apmokėti rankiniu banko pavedimu.',
        'en'    => 'After placing the order, you can pay the invoice by manual bank transfer.',
        'ru'    => 'После оформления заказа вы сможете оплатить счёт обычным банковским переводом.',
        'nb-no' => 'Etter at bestillingen er lagt inn, kan du betale fakturaen med manuell bankoverføring.',
    ];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable('lovata_orders_shopaholic_payment_methods') || !Schema::hasTable('system_site_definitions')) {
            return;
        }

        $obPaymentMethod = PaymentMethod::where('code', self::PAYMENT_METHOD_CODE)->first();
        if (empty($obPaymentMethod)) {
            return;
        }

        $arLocaleList = SiteDefinition::pluck('locale')->unique()->all();
        foreach ($arLocaleList as $sLocale) {
            $sPreviewText = self::PREVIEW_TEXT_LIST[$sLocale] ?? null;
            if ($sPreviewText === null) {
                continue;
            }

            $obPaymentMethod->setAttributeTranslated('preview_text', $sPreviewText, $sLocale);
        }

        $obPaymentMethod->save();
    }
}
