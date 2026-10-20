<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Lang;

/**
 * Laravel's Password rule fails with validation.password.<check>. October answers every
 * validation.* key from system::validation with an English fallback, so the shop
 * locales override that namespace through the plugin's lang path.
 */
class PasswordRuleMessagesTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const CHECK_LIST = ['letters', 'mixed', 'numbers', 'symbols', 'uncompromised'];

    /**
     * @param string $sLocale
     * @return void
     */
    #[DataProvider('provideShopLocale')]
    public function testEveryPasswordCheckIsTranslated($sLocale)
    {
        foreach (self::CHECK_LIST as $sCheck) {
            $sKey = 'validation.password.'.$sCheck;
            $sLine = Lang::get($sKey, [], $sLocale);

            $this->assertNotSame($sKey, $sLine, $sLocale.' '.$sCheck);
            $this->assertNotSame(Lang::get($sKey, [], 'en'), $sLine, $sLocale.' '.$sCheck.' still English');
        }
    }

    /**
     * The override adds keys; the core strings of the namespace stay.
     * @param string $sLocale
     * @return void
     */
    #[DataProvider('provideShopLocale')]
    public function testCoreValidationStringsSurviveTheOverride($sLocale)
    {
        $arCoreLineList = require base_path('modules/system/lang/'.$sLocale.'/validation.php');

        $this->assertSame($arCoreLineList['confirmed'], Lang::get('validation.confirmed', [], $sLocale));
    }

    /**
     * @return array
     */
    public static function provideShopLocale()
    {
        return [
            'lv'    => ['lv'],
            'lt'    => ['lt'],
            'ru'    => ['ru'],
            'nb-no' => ['nb-no'],
        ];
    }
}
