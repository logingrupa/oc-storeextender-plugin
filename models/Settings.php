<?php namespace Logingrupa\StoreExtender\Models;

use October\Rain\Database\Traits\Multisite;
use October\Rain\Database\Traits\Validation;
use System\Models\SettingModel;

/**
 * Storefront settings of this plugin, one row per site (the EUR sites and
 * the NOK site hold different amounts). A site without a row reads the first
 * existing one through SettingsSiteFallbackHandler.
 *
 * @property string|float|null $nudge_catalog_min_missing
 * @property string|float|null $nudge_catalog_max_missing
 */
class Settings extends SettingModel
{
    use Multisite;
    use Validation;

    const SETTINGS_CODE = 'logingrupa_storeextender_settings';

    const NUDGE_CATALOG_MIN_MISSING_DEFAULT = 1.0;
    const NUDGE_CATALOG_MAX_MISSING_DEFAULT = 13.0;

    /** @var string */
    public $settingsCode = self::SETTINGS_CODE;

    /** @var string */
    public $settingsFields = 'fields.yaml';

    /** @var array Multisite needs the list; empty keeps every site's row independent */
    protected $propagatable = [];

    /** @var array */
    public $rules = [
        'nudge_catalog_min_missing' => 'nullable|numeric|min:0',
        'nudge_catalog_max_missing' => 'nullable|numeric|min:0',
    ];

    /**
     * Values of a site that has no settings row anywhere yet.
     * @return void
     */
    public function initSettingsData()
    {
        $this->nudge_catalog_min_missing = self::NUDGE_CATALOG_MIN_MISSING_DEFAULT;
        $this->nudge_catalog_max_missing = self::NUDGE_CATALOG_MAX_MISSING_DEFAULT;
    }
}
