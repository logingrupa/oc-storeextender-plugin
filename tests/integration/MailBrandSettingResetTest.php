<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../../updates/update_mail_brand_setting_reset_to_config.php';

use System\Models\MailBrandSetting;
use Logingrupa\StoreExtender\Updates\UpdateMailBrandSettingResetToConfig;

/**
 * The mail palette moves from a hand-set row per shop to config/brand.php. October seeds
 * MailBrandSetting from the studly brand.mail keys only while no row exists, so the
 * migration must remove the row and the cached CSS for the config to take effect.
 */
class MailBrandSettingResetTest extends StoreExtenderPluginTestCase
{
    /** system_settings comes from the core module schema the base case migrates early */
    protected $autoMigrate = false;

    public function testMigrationDropsTheRowSoTheConfigPaletteSeedsTheSettings()
    {
        Config::set('brand.mail.ButtonPrimaryBg', '#96ca4f');

        MailBrandSetting::set('button_primary_bg', '#000000');
        MailBrandSetting::clearInternalCache();
        $this->assertSame('#000000', MailBrandSetting::get('button_primary_bg'));

        Cache::forever(MailBrandSetting::instance()->cacheKey, '/* stale */');

        (new UpdateMailBrandSettingResetToConfig)->up();

        $this->assertNull(Cache::get('system::mailbrand.custom_css'));
        $this->assertFalse(MailBrandSetting::instance()->exists);
        $this->assertSame('#96ca4f', MailBrandSetting::get('button_primary_bg'));
    }
}
