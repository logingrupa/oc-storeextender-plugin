<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Toolbox\Classes\Item\ElementItem;
use October\Rain\Database\Attach\File;

/**
 * The picture the product page shows for a shade: its own, or the product photo
 * when it is the only shade the page draws.
 */
class OfferPicture
{
    public static function resolve(OfferItem $obOfferItem, ElementItem $obProductItem, int $iOfferCount): ?File
    {
        $obOfferImage = $obOfferItem->preview_image;
        if (!empty($obOfferImage) || $iOfferCount !== 1) {
            return $obOfferImage;
        }

        return $obProductItem->preview_image;
    }
}
