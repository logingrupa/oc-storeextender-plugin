<?php namespace Logingrupa\StoreExtender\Models;

use Model;

/**
 * Class ColorFamilyMeta
 *
 * Companion row per Color Family PropertyValue slug: localized display
 * names, per-locale synonym lists (matcher-only data) and the canonical
 * swatch hex. Written only by `storeextender:sync-offer-colors` from the
 * color-lab families.json export; PropertyValue itself has no home for
 * synonyms or hex.
 *
 * @package Logingrupa\StoreExtender\Models
 *
 * @property int    $id
 * @property string $value_slug PropertyValue slug (stable family slug from families.json)
 * @property string $family family name as offers.json reports it (join key to OfferColor.family)
 * @property string|null $hex strict #RRGGBB or null
 * @property array  $names  ['lv' => 'Sarkans', 'en' => 'Red', ...]
 * @property array  $synonyms ['lv' => ['sarkana', ...], ...]
 * @property int|null $sort_order position in the families.json export (the pill/chip order contract); null = never synced
 * @property string $kind 'color' or 'collection'
 * @property array|null $gradient 2 or 3 strict #rrggbb stops, drawn left to right; null = flat hex
 */
class ColorFamilyMeta extends Model
{
    const KIND_COLOR = 'color';
    const KIND_COLLECTION = 'collection';

    public $table = 'logingrupa_storeextender_color_family_meta';

    public $fillable = [
        'value_slug',
        'family',
        'hex',
        'names',
        'synonyms',
        'sort_order',
        'kind',
        'gradient',
    ];

    protected $jsonable = [
        'names',
        'synonyms',
        'gradient',
    ];
}
