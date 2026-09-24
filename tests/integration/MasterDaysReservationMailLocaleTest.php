<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Plugin;
use System\Classes\MailManager;
use Illuminate\Mail\Message;
use Symfony\Component\Mime\Email;

/**
 * The Master Days summary page mails attendees whose reservation was removed and attendees
 * asked to confirm a full group. Both are plugin views with a Latvian variant; this renders
 * them through the mail manager and checks the subject, the links and the locale.
 */
class MasterDaysReservationMailLocaleTest extends StoreExtenderPluginTestCase
{
    /** Rendering needs the core mail tables only, which the base case migrates early */
    protected $autoMigrate = false;

    const DELETED_DATA = [
        'location_name' => 'RĪGA LV',
        'checker_link' => 'https://nailscosmetics.lv/lv/rezervacija/%2B37120000000',
        'register_link' => 'https://nailscosmetics.lv/lv/md-latvija?name=Anna&email=anna%40example.com',
        'name' => 'Anna',
    ];

    const REMINDER_DATA = [
        'event' => 'RĪGA LV',
        'confirmation_link' => 'https://nailscosmetics.lv/lv/rezervacija/%2B37120000000',
    ];

    public function setUp(): void
    {
        parent::setUp();

        Config::set('multisite.features.backend_mail_template', true);

        MailManager::instance()->registerMailTemplates((new Plugin(App::make('app')))->registerMailTemplates());
    }

    public function testPluginRegistersBothReservationTemplatesAgainstTheirOwnViews()
    {
        $arTemplateList = (new Plugin(App::make('app')))->registerMailTemplates();

        $this->assertSame(Plugin::MAIL_MD_RESERVATION_DELETED, $arTemplateList[Plugin::MAIL_MD_RESERVATION_DELETED] ?? null);
        $this->assertSame(Plugin::MAIL_MD_RESERVATION_REMINDER, $arTemplateList[Plugin::MAIL_MD_RESERVATION_REMINDER] ?? null);
    }

    public function testDeletedMailFollowsTheLocaleAndCarriesBothLinks()
    {
        $arSubjectList = [
            'en' => 'About your Master Days reservation (RĪGA LV)',
            'lv' => 'Par Jūsu rezervāciju Meistaru dienām (RĪGA LV)!',
        ];

        foreach ($arSubjectList as $sLocale => $sSubject) {
            $obMessage = $this->renderThroughMailManager(Plugin::MAIL_MD_RESERVATION_DELETED, self::DELETED_DATA, $sLocale);
            $sHtml = (string) $obMessage->getHtmlBody();

            $this->assertSame($sSubject, $obMessage->getSubject(), $sLocale.' subject');
            $this->assertStringContainsString('href="'.e(self::DELETED_DATA['register_link']).'"', $sHtml, $sLocale.' register link');
            $this->assertStringContainsString('href="'.self::DELETED_DATA['checker_link'].'"', $sHtml, $sLocale.' checker link');
            $this->assertStringContainsString('Anna', $sHtml);
        }
    }

    public function testDeletedMailFallsBackToAGenericGreetingWithoutAName()
    {
        $arData = self::DELETED_DATA;
        unset($arData['name']);

        $obMessage = $this->renderThroughMailManager(Plugin::MAIL_MD_RESERVATION_DELETED, $arData, 'lv');

        $this->assertStringContainsString('Sveiki, cienījamais klient!', (string) $obMessage->getHtmlBody());
    }

    public function testReminderMailFollowsTheLocaleAndCarriesTheConfirmationLink()
    {
        $arSubjectList = [
            'en' => 'Reminder about your Master Days reservation (RĪGA LV)!',
            'lv' => 'Atgādinājums par Jūsu rezervāciju Meistaru dienām (RĪGA LV)!',
        ];

        foreach ($arSubjectList as $sLocale => $sSubject) {
            $obMessage = $this->renderThroughMailManager(Plugin::MAIL_MD_RESERVATION_REMINDER, self::REMINDER_DATA, $sLocale);

            $this->assertSame($sSubject, $obMessage->getSubject(), $sLocale.' subject');
            $this->assertStringContainsString('href="'.self::REMINDER_DATA['confirmation_link'].'"', (string) $obMessage->getHtmlBody(), $sLocale.' confirmation link');
        }
    }

    /**
     * @param string $sCode
     * @param array $arData
     * @param string $sLocale
     * @return \Symfony\Component\Mime\Email
     */
    protected function renderThroughMailManager($sCode, $arData, $sLocale)
    {
        $obMessage = new Message(new Email);

        $bAdded = MailManager::instance()->addContentToMailer($obMessage, $sCode, $arData + ['_current_locale' => $sLocale]);

        $this->assertTrue($bAdded, $sCode.' for '.$sLocale.' did not resolve to a template');

        return $obMessage->getSymfonyMessage();
    }
}
