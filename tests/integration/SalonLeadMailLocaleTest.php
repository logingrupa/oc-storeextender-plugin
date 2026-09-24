<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Plugin;
use System\Classes\MailManager;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * The salon application page sends two plugin views, one to the managers and one back to
 * the applicant. October picks the locale variant beside the registered view only when
 * multisite.features.backend_mail_template is on, so this renders both through the mail
 * manager per shop locale, the way the mailer does, and checks the subject and the body
 * follow the locale.
 */
class SalonLeadMailLocaleTest extends StoreExtenderPluginTestCase
{
    /** Rendering needs the core mail tables only, which the base case migrates early */
    protected $autoMigrate = false;

    const SHOP_LOCALE_LIST = ['lv', 'ru', 'lt', 'nb-no'];

    const MANAGER_SUBJECT_LIST = [
        'en' => 'Salon application - Studio B & Co',
        'lv' => 'Salona pieteikums - Studio B & Co',
        'ru' => 'Заявка салона - Studio B & Co',
        'lt' => 'Salono paraiška - Studio B & Co',
        'nb-no' => 'Salongsøknad - Studio B & Co',
    ];

    const APPLICANT_SUBJECT_LIST = [
        'en' => 'Salon application received',
        'lv' => 'Salona pieteikums saņemts',
        'ru' => 'Заявка салона получена',
        'lt' => 'Salono paraiška gauta',
        'nb-no' => 'Salongsøknaden er mottatt',
    ];

    const FORM_DATA = [
        'name' => 'Irene Roste',
        'email' => 'irene@example.com',
        'phone' => '+47 900 00 000',
        'salon_name' => 'Studio B & Co',
        'salon_address' => 'Storgata 1, Oslo',
        'number_of_masters' => '3',
        'current_product_list' => 'Brand A, Brand B',
        'product_list' => 'No',
        'nail_extensions' => 'Yes',
        'other_treatment_list' => 'pedicure',
        'profile_link' => 'https://instagram.com/studiob',
        'order_plan' => '500 EUR',
    ];

    public function setUp(): void
    {
        parent::setUp();

        Config::set('multisite.features.backend_mail_template', true);

        MailManager::instance()->registerMailTemplates((new Plugin(App::make('app')))->registerMailTemplates());
    }

    public function testPluginRegistersBothSalonLeadTemplatesAgainstTheirOwnViews()
    {
        $arTemplateList = (new Plugin(App::make('app')))->registerMailTemplates();

        $this->assertSame(Plugin::MAIL_SALON_LEAD_MANAGER, $arTemplateList[Plugin::MAIL_SALON_LEAD_MANAGER] ?? null);
        $this->assertSame(Plugin::MAIL_SALON_LEAD_APPLICANT, $arTemplateList[Plugin::MAIL_SALON_LEAD_APPLICANT] ?? null);
    }

    public function testEveryShopLocaleHasBothViews()
    {
        foreach (self::SHOP_LOCALE_LIST as $sLocale) {
            $this->assertFileExists(__DIR__.'/../../views/mail/'.$sLocale.'/salon_lead_manager.htm');
            $this->assertFileExists(__DIR__.'/../../views/mail/'.$sLocale.'/salon_lead_applicant.htm');
        }
    }

    public function testManagerMailFollowsTheLocaleAndCarriesTheFormData()
    {
        foreach (self::MANAGER_SUBJECT_LIST as $sLocale => $sSubject) {
            $obMessage = $this->renderThroughMailManager(Plugin::MAIL_SALON_LEAD_MANAGER, $sLocale);

            $this->assertSame($sSubject, $obMessage->getSubject(), $sLocale.' subject');

            $sHtml = (string) $obMessage->getHtmlBody();
            foreach (self::FORM_DATA as $sField => $sValue) {
                $this->assertStringContainsString(e($sValue), $sHtml, $sLocale.' body lacks '.$sField);
            }
        }
    }

    public function testApplicantMailFollowsTheLocale()
    {
        foreach (self::APPLICANT_SUBJECT_LIST as $sLocale => $sSubject) {
            $obMessage = $this->renderThroughMailManager(Plugin::MAIL_SALON_LEAD_APPLICANT, $sLocale);

            $this->assertSame($sSubject, $obMessage->getSubject(), $sLocale.' subject');
            $this->assertStringContainsString('NAI_S cosmetics', (string) $obMessage->getHtmlBody());
        }
    }

    public function testFeatureOffFallsBackToTheEnglishView()
    {
        Config::set('multisite.features.backend_mail_template', false);

        $obMessage = $this->renderThroughMailManager(Plugin::MAIL_SALON_LEAD_APPLICANT, 'lv');

        $this->assertSame(self::APPLICANT_SUBJECT_LIST['en'], $obMessage->getSubject());
    }

    /**
     * @param string $sCode
     * @param string $sLocale
     * @return \Symfony\Component\Mime\Email
     */
    protected function renderThroughMailManager($sCode, $sLocale)
    {
        $obMessage = new Message(new Email);

        $bAdded = MailManager::instance()->addContentToMailer($obMessage, $sCode, self::FORM_DATA + ['_current_locale' => $sLocale]);

        $this->assertTrue($bAdded, $sCode.' for '.$sLocale.' did not resolve to a template');

        return $obMessage->getSymfonyMessage();
    }
}
