<?php namespace Logingrupa\StoreExtender\Updates;

use Db;
use Schema;
use October\Rain\Database\Updates\Migration;
use Logingrupa\StoreExtender\Models\SlugHistory;

/**
 * Product renames the 1C feed made before the slug history existed, taken
 * from the Search Console 404 report of 2026-09-09. A pair is recorded only
 * where the new slug is a product on this install and the old slug is not,
 * so a shop without the product records nothing.
 */
class SeedSlugHistory202609 extends Migration
{
    const RENAMES = [
        'liquid-polygel-uv-led' => 'liquid-polygel-uvled',
        'liquid-polygel-set' => 'liquid-polygel-komplekts',
        '3d-plastic-gel-uv-led-5g' => '3d-plastikas-gels-uvled-5gr',
        'reflectix-builder-gel-uv-led' => 'reflectix-builder-gel-uvled',
        'bottle-gel-uv-led-15ml' => 'bottle-gel-uvled-15ml',
        'pedicure-restore-base-uv-led-15ml' => 'pedicure-restore-base-uvled-15ml',
        'pedicure-elastic-base-uv-led-15ml' => 'pedicure-elastic-base-uvled-15ml',
    ];

    public function up()
    {
        if (!Schema::hasTable('lovata_shopaholic_products')) {
            return;
        }

        foreach (self::RENAMES as $sOldSlug => $sNewSlug) {
            $bTargetExists = Db::table('lovata_shopaholic_products')->where('slug', $sNewSlug)->exists();
            $bOldStillUsed = Db::table('lovata_shopaholic_products')->where('slug', $sOldSlug)->exists();
            if ($bTargetExists && !$bOldStillUsed) {
                SlugHistory::record(SlugHistory::TYPE_PRODUCT, $sOldSlug, $sNewSlug);
            }
        }
    }

    public function down()
    {
        SlugHistory::where('model_type', SlugHistory::TYPE_PRODUCT)
            ->whereIn('old_slug', array_keys(self::RENAMES))
            ->delete();
    }
}
