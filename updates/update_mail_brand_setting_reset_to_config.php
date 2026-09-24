<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use System\Models\MailBrandSetting;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateMailBrandSettingResetToConfig
 *
 * Two shops carried a hand-set mail palette row and one had none, so the same message
 * rendered in different colours per shop. The palette now lives in config/brand.php
 * (the brand.mail keys October reads when no row exists), so the rows go and every shop
 * seeds the same theme colours. The rendered CSS is cached forever and the settings
 * instance is memoised per process, so both are dropped too.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateMailBrandSettingResetToConfig extends Migration
{
    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        MailBrandSetting::instance()->resetDefault();
        MailBrandSetting::clearInternalCache();
    }
}
