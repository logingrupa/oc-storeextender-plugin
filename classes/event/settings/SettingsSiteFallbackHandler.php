<?php namespace Logingrupa\StoreExtender\Classes\Event\Settings;

/**
 * Class SettingsSiteFallbackHandler
 *
 * Lovata settings models use the Multisite trait with an empty $propagatable
 * list, so system_settings rows are site-scoped and exist only for the primary
 * site. On /en and /ru Settings::getValue() returned null for every field,
 * which silently disabled product search (SearchHelper bails on empty
 * Shopaholic settings) and collapsed price decimals to 0 (Toolbox PriceHelper
 * casts the missing 'decimals' value to int 0, rendering "9" instead of
 * "9.00").
 *
 * Instead of duplicating rows per site, copy ALL values from the first existing
 * settings record into the in-memory instance whenever the active site has no
 * own record. Saving settings under a non-primary site still creates an explicit
 * per-site override, which then takes precedence.
 *
 * XmlImportSettings needs this for a different reason than the storefront pair,
 * and it stayed hidden for longer. On nailscosmetics.no the only settings row
 * belongs to site 1 while the PRIMARY site is 2, so a console command - which
 * resolves the primary site and never a request's one - read null for every
 * field: no file list, no XPaths, no field maps. The 1C import therefore could
 * not run from CLI at all on that deployment. It never showed on .lv, where
 * site 1 happens to be the primary one and the lookup finds its row directly.
 *
 * GoodsReceivedShopaholic's Settings has the same console-side problem on .no,
 * with one extra wrinkle: site 2 held a PARTIAL row carrying only 'enabled' and
 * 'auto_activate_on_stock'. A row that exists blocks this fallback entirely -
 * SettingModel::instance() fires model.initSettingsData only when
 * getSettingsRecord() returns null - so the CLI read the partial row and saw
 * auto_deactivate_on_zero as false. That row was deleted; the site 1 row is the
 * only one left and is the one both the backend and the CLI now resolve.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Settings
 */
class SettingsSiteFallbackHandler
{
    /** Raw table columns that must never be copied between site records */
    const AR_SYSTEM_FIELD_LIST = ['id', 'item', 'site_id', 'site_root_id', 'site_group_id', 'value'];

    /** Site-scoped settings models that need the primary-site fallback */
    const AR_SETTINGS_MODEL_LIST = [
        \Lovata\Shopaholic\Models\Settings::class,
        \Lovata\Toolbox\Models\Settings::class,
        \Lovata\Shopaholic\Models\XmlImportSettings::class,
        \Logingrupa\GoodsReceivedShopaholic\Models\Settings::class,
    ];

    /**
     * Add listeners
     *
     * Logingrupa.GoodsReceivedShopaholic is not in this plugin's $require, so its
     * Settings class is absent on a deployment that does not install it. Skip what
     * is missing rather than fatal the boot.
     *
     * @return void
     */
    public function subscribe(): void
    {
        foreach (self::AR_SETTINGS_MODEL_LIST as $sSettingsClass) {
            if (!class_exists($sSettingsClass)) {
                continue;
            }

            $sSettingsClass::extend(function ($obSettings): void {
                /** @var \System\Models\SettingModel $obSettings */
                $obSettings->bindEvent('model.initSettingsData', function () use ($obSettings): void {
                    $this->initSettingsFromOtherSite($obSettings);
                });
            });
        }
    }

    /**
     * Copy setting values from the first existing settings record of another site
     * @param \System\Models\SettingModel $obSettings
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
