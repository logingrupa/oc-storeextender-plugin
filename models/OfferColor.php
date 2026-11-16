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
 * @property float|null $confidence classification certainty (in-family shade order, highest first); null = unscored
 * @property string|null $families JSON list of every family the offer belongs to, primary first; null = export carried none
 * @property string|null $shade_code 1C shade number such as "DISCO 30"; null = none
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
        'confidence',
        'families',
        'shade_code',
    ];

    /**
     * Every family of the offer, primary first. Falls back to the single
     * family column when the families column is absent, empty or unreadable.
     *
     * @return array
     */
    public function resolveFamilyList(): array
    {
        $arFamilyList = json_decode((string) $this->getAttribute('families'), true);
        if (!is_array($arFamilyList) || empty($arFamilyList)) {
            return [(string) $this->family];
        }

        return array_values($arFamilyList);
    }
}
