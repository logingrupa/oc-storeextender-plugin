<?php namespace Logingrupa\StoreExtender\Models;

use Model;

/**
 * Class OfferColor
 *
 * One row per offer UUID: color family + representative hex + hue/lightness
 * for in-family ordering. Written only by `storeextender:sync-offer-colors`.
 *
 * @package Logingrupa\StoreExtender\Models
 *
 * @property int    $id
 * @property string $offer_uuid bare 1C offer UUID (join key to lovata_shopaholic_offers.external_id)
 * @property string $family
 * @property string|null $hex strict #RRGGBB or null
 * @property float  $hue
 * @property float  $lightness
 */
class OfferColor extends Model
{
    public $table = 'logingrupa_storeextender_offer_colors';

    public $fillable = [
        'offer_uuid',
        'family',
        'hex',
        'hue',
        'lightness',
    ];
}
