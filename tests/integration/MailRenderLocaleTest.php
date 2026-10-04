<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Classes\Mail\MailRenderLocale;
use RainLab\Translate\Classes\Translator;

/**
 * A manager mail used to arrive half translated: October applied the declared locale to the
 * template and the lang files while product names kept following the RainLab Translate
 * locale of the request. These cases pin both to the declared locale and pin the restore,
 * so a Russian customer still gets a Russian page after the mail leaves.
 */
class MailRenderLocaleTest extends StoreExtenderPluginTestCase
{
    /** Rendering needs the core module schema only, which the base case migrates early */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->defineSites([['lv', true, true], ['ru', false, true]]);

        Translator::instance()->setLocale('ru', false);
    }

    public function testTheDeclaredLocaleIsActiveForTheSendAndRestoredAfter()
    {
        $arSeen = [];

        $mResult = MailRenderLocale::apply(['_current_locale' => 'lv'], function () use (&$arSeen) {
            $arSeen = [App::getLocale(), Translator::instance()->getLocale()];

            return 'sent';
        });

        $this->assertSame('sent', $mResult);
        $this->assertSame(['lv', 'lv'], $arSeen);
        $this->assertSame('ru', App::getLocale());
        $this->assertSame('ru', Translator::instance()->getLocale());
    }

    public function testTheLocaleIsRestoredWhenTheSendThrows()
    {
        try {
            MailRenderLocale::apply(['_current_locale' => 'lv'], function () {
                throw new RuntimeException('transport down');
            });

            $this->fail('the exception did not leave apply()');
        } catch (RuntimeException $obException) {
            $this->assertSame('transport down', $obException->getMessage());
        }

        $this->assertSame('ru', App::getLocale());
        $this->assertSame('ru', Translator::instance()->getLocale());
    }

    public function testEverySendGoesThroughTheDeclaredLocale()
    {
        $sSeenLocale = null;
        Event::listen('mailer.beforeSend', function () use (&$sSeenLocale) {
            $sSeenLocale = Translator::instance()->getLocale();

            return false;
        });

        Mail::send('logingrupa.storeextender::mail.order_paid_manager', ['_current_locale' => 'lv'], function ($obMessage) {
            $obMessage->to('manager@example.com');
        });

        $this->assertSame('lv', $sSeenLocale, 'the mailer did not apply the declared locale');
        $this->assertSame('ru', Translator::instance()->getLocale());
    }

    public function testMailDataWithoutALocaleIsLeftAlone()
    {
        $arSeen = [];

        MailRenderLocale::apply([], function () use (&$arSeen) {
            $arSeen = [App::getLocale(), Translator::instance()->getLocale()];
        });

        $this->assertSame(['ru', 'ru'], $arSeen);
    }
}
