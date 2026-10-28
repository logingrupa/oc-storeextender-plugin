<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\DB;
use Lovata\Shopaholic\Models\XmlImportSettings;

/**
 * Class StockHoldPolicy
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Orders hold and give back offer stock only where the shop owns its stock. Where the 1C XML
 * import maps a quantity column (.lv), every import overwrites stock, so a give-back would
 * count the same units twice; .lt and .no keep stock by hand and through goods receipts.
 */
class StockHoldPolicy
{
    const IMPORT_FIELD = 'quantity';

    const IMPORT_MAP_LIST = ['offer', 'price'];

    /** @var bool|null once per process */
    protected static $bEnabled = null;

    /**
     * @return bool
     */
    public static function isEnabled(): bool
    {
        if (self::$bEnabled === null) {
            self::$bEnabled = !self::importOwnsQuantity();
        }

        return self::$bEnabled;
    }

    /**
     * @return void
     */
    public static function forget(): void
    {
        self::$bEnabled = null;
    }

    /**
     * Read every site's row: the import runs once for all sites, and a site without its own
     * row would otherwise answer with the primary site's settings only.
     * @return bool
     */
    protected static function importOwnsQuantity(): bool
    {
        $obValueList = DB::table('system_settings')->where('item', XmlImportSettings::SETTINGS_CODE)->pluck('value');
        foreach ($obValueList as $sValue) {
            $arSettings = (array) json_decode((string) $sValue, true);
            foreach (self::IMPORT_MAP_LIST as $sMap) {
                foreach ((array) array_get($arSettings, $sMap, []) as $arMapRow) {
                    if (is_array($arMapRow) && array_get($arMapRow, 'field') === self::IMPORT_FIELD) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
