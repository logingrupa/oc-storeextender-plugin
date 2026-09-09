<?php namespace Logingrupa\StoreExtender\Models;

use Model;

/**
 * One row per retired slug: which live slug it now belongs to.
 *
 * @property int $id
 * @property string $model_type
 * @property string $old_slug
 * @property string $new_slug
 */
class SlugHistory extends Model
{
    const TYPE_PRODUCT = 'product';
    const TYPE_CATEGORY = 'category';

    public $table = 'logingrupa_storeextender_slug_history';

    protected $fillable = ['model_type', 'old_slug', 'new_slug'];

    /**
     * Remember that $sOldSlug now lives at $sNewSlug. Rows that pointed at the
     * old slug are rewritten to the new one so a chain of renames stays one
     * hop, and a slug that comes back into use stops being a redirect.
     */
    public static function record(string $sType, string $sOldSlug, string $sNewSlug): void
    {
        if ($sOldSlug === '' || $sNewSlug === '' || $sOldSlug === $sNewSlug) {
            return;
        }

        static::where('model_type', $sType)->where('old_slug', $sNewSlug)->delete();
        static::where('model_type', $sType)->where('new_slug', $sOldSlug)->update(['new_slug' => $sNewSlug]);
        static::updateOrCreate(
            ['model_type' => $sType, 'old_slug' => $sOldSlug],
            ['new_slug' => $sNewSlug]
        );
    }

    public static function findTarget(string $sType, string $sOldSlug): ?string
    {
        $obRow = static::where('model_type', $sType)->where('old_slug', $sOldSlug)->first();

        return $obRow ? $obRow->new_slug : null;
    }
}
