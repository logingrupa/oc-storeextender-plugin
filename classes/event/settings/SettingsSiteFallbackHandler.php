<?php namespace Logingrupa\StoreExtender\Classes\Event\Settings;

use Lovata\Shopaholic\Models\Settings;

/**
 * Class SettingsSiteFallbackHandler
 *
 * Shopaholic Settings use the Multisite trait with an empty $propagatable list,
 * so system_settings rows are site-scoped and exist only for the primary site.
 * On /en and /ru Settings::getValue() returned null for every field, which
 * silently disabled product search (SearchHelper bails on empty settings).
 *
 * Instead of duplicating rows per site, copy ALL values from the first existing
 * settings record into the in-memory instance whenever the active site has no
 * own record. Saving settings under a non-primary site still creates an explicit
 * per-site override, which then takes precedence.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Settings
 */
class SettingsSiteFallbackHandler
{
    /** Raw table columns that must never be copied between site records */
    const AR_SYSTEM_FIELD_LIST = ['id', 'item', 'site_id', 'site_root_id', 'site_group_id', 'value'];

    /**
     * Add listeners
     * @return void
     */
    public function subscribe(): void
    {
        Settings::extend(function ($obSettings): void {
            /** @var Settings $obSettings */
            $obSettings->bindEvent('model.initSettingsData', function () use ($obSettings): void {
                $this->initSettingsFromOtherSite($obSettings);
            });
        });
    }

    /**
     * Copy setting values from the first existing settings record of another site
     * @param Settings $obSettings
     * @return void
     */
    protected function initSettingsFromOtherSite($obSettings): void
    {
        $obOtherSettings = $obSettings->findOtherSettingModel();
        if (empty($obOtherSettings)) {
            return;
        }

        $arValueList = array_except($obOtherSettings->getAttributes(), self::AR_SYSTEM_FIELD_LIST);
        foreach ($arValueList as $sField => $sValue) {
            $obSettings->setAttribute($sField, $sValue);
        }
    }
}
