<?php namespace Logingrupa\StoreExtender\Classes\Mail;

use App;
use RainLab\Translate\Classes\Translator;

/**
 * Class MailRenderLocale
 * @package Logingrupa\StoreExtender\Classes\Mail
 *
 * Renders a mail in the language its data declares in _current_locale. October applies that
 * locale to the mail template and the lang files, while the translated names of products,
 * payment methods and shipping types follow the RainLab Translate locale, which stays on the
 * language of the request. Both have to agree or a manager mail arrives half translated.
 *
 * Applied for every send in SafeMailer, so a mail sent from a gateway webhook or the
 * scheduler renders in the declared language too.
 */
class MailRenderLocale
{
    /**
     * @param mixed $mMailData the view data, an array for every template send
     * @param callable $fnSend
     * @return mixed whatever the send returns
     */
    public static function apply($mMailData, callable $fnSend)
    {
        $sLocale = is_array($mMailData) ? (string) ($mMailData['_current_locale'] ?? '') : '';
        if ($sLocale === '') {
            return $fnSend();
        }

        $obTranslator = Translator::instance();
        $sPreviousAppLocale = App::getLocale();
        $sPreviousLocale = $obTranslator->getLocale() ?: $sPreviousAppLocale;
        $obTranslator->setLocale($sLocale, false);

        try {
            return $fnSend();
        } finally {
            $obTranslator->setLocale($sPreviousLocale, false);
            App::setLocale($sPreviousAppLocale);
        }
    }
}
