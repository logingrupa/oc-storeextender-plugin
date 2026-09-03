<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

/**
 * The mail footer line lives in the default mail layout, a database row seeded once at
 * install from October's stock view, so it kept the English sentence on every locale.
 * The migration swaps it for the trans() call below; this guards the key it points at,
 * because a missing key would print the raw path in the footer of every message.
 */
class MailFooterLocaleTest extends StoreExtenderPluginTestCase
{
    /** Language files are read without touching a table */
    protected $autoMigrate = false;

    const LANG_KEY = 'logingrupa.storeextender::lang.mail.rights_reserved';

    const SHOP_LOCALE_LIST = ['lv', 'ru', 'de', 'lt', 'nb-no'];

    public function testEveryShopLocaleTranslatesTheRightsReservedLine()
    {
        $sEnglish = Lang::get(self::LANG_KEY, [], 'en');

        $this->assertSame('All rights reserved.', $sEnglish);

        foreach (self::SHOP_LOCALE_LIST as $sLocale) {
            $sTranslation = Lang::get(self::LANG_KEY, [], $sLocale);

            $this->assertNotSame(
                self::LANG_KEY,
                $sTranslation,
                $sLocale.' has no mail.rights_reserved, the footer would print the raw key'
            );
            $this->assertNotSame(
                $sEnglish,
                $sTranslation,
                $sLocale.' still reads the English sentence'
            );
        }
    }

    public function testMigrationRewritesOctoberStockFooterIntoTheTranslatedOne()
    {
        require_once __DIR__.'/../../updates/update_table_system_mail_layout_footer.php';

        $sStockFooter = '&copy; {{ "now"|date("Y") }} '
            .\Logingrupa\StoreExtender\Updates\UpdateTableSystemMailLayoutFooter::SEARCH;

        $this->assertStringContainsString(
            $sStockFooter,
            file_get_contents(base_path('modules/system/views/mail/layout-default.htm')),
            'October changed its stock footer, the migration needle no longer matches'
        );

        $this->assertStringContainsString(
            self::LANG_KEY,
            \Logingrupa\StoreExtender\Updates\UpdateTableSystemMailLayoutFooter::REPLACE
        );
    }
}
