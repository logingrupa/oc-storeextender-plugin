<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Plugin;

/**
 * RainLab.User ships one English view for the password recovery mail, and October only
 * looks for a locale variant beside the view registered for the template code. Two
 * things have to hold for a shopper to read the mail in their own language: this plugin
 * must register the code against its own view, and every shop locale must have a view
 * sitting next to the base one.
 */
class RecoverPasswordMailLocaleTest extends StoreExtenderPluginTestCase
{
    /** Registrations and view files are read without touching a table */
    protected $autoMigrate = false;

    const TEMPLATE_CODE = 'user:recover_password';

    const VIEW_PREFIX = 'logingrupa.storeextender::mail.';

    const SHOP_LOCALE_LIST = ['lv', 'ru', 'de', 'lt', 'nb-no'];

    public function testRainLabUserSitsInRequireSoThisPluginSortsAfterIt()
    {
        $arRequire = (new Plugin(App::make('app')))->require;

        $this->assertContains(
            'RainLab.User',
            $arRequire,
            'registrations merge left to right; requiring RainLab.User keeps this plugin later so it owns user:recover_password'
        );
    }

    public function testPluginRegistersTheRecoverPasswordTemplateAgainstItsOwnView()
    {
        $arTemplateList = (new Plugin(App::make('app')))->registerMailTemplates();

        $this->assertSame(
            self::VIEW_PREFIX.'recover_password',
            $arTemplateList[self::TEMPLATE_CODE] ?? null
        );
    }

    public function testEveryShopLocaleHasItsOwnViewWithATranslatedSubject()
    {
        $sEnglishSubject = $this->readSubject($this->makeViewPath());

        $this->assertSame('Reset Password Notification', $sEnglishSubject);

        foreach (self::SHOP_LOCALE_LIST as $sLocale) {
            $sPath = $this->makeViewPath($sLocale);

            $this->assertFileExists($sPath);
            $this->assertNotSame(
                $sEnglishSubject,
                $this->readSubject($sPath),
                $sLocale.' still reads the English subject'
            );
        }
    }

    /**
     * @param string|null $sLocale
     * @return string
     */
    protected function makeViewPath($sLocale = null)
    {
        $sDir = __DIR__.'/../../views/mail/'.($sLocale ? $sLocale.'/' : '');

        return $sDir.'recover_password.htm';
    }

    /**
     * @param string $sPath
     * @return string
     */
    protected function readSubject($sPath)
    {
        $bFound = preg_match('/^subject = "(.*)"$/m', (string) file_get_contents($sPath), $arMatch);

        $this->assertSame(1, $bFound, $sPath.' has no subject line');

        return $arMatch[1];
    }
}
