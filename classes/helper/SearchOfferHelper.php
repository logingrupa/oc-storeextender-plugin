<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Classes\Collection\OfferCollection;
use Lovata\Shopaholic\Classes\Collection\ProductCollection;

/**
 * Class SearchOfferHelper
 *
 * The catalog search grid renders offers (shades), but the configured offer
 * search fields are narrower than the product ones: "gel" matches hundreds
 * of products and only a handful of offers by their own fields. This helper
 * unions the two match sets into one offer id list - offers matching the
 * query directly, plus every offer of a product that matches - exposed as
 * the Twig function search_offer_filter. The caller intersects the result
 * with the active offer list, which also drops inactive ids from the raw
 * product_id lookup.
 *
 * @package Logingrupa\StoreExtender\Classes\Helper
 */
class SearchOfferHelper
{
    /**
     * Offer ids matching the query: direct offer matches first, then the
     * offers of matching products. Empty/invalid query answers an empty
     * list - the search grid renders "not found", never everything.
     *
     * @param mixed $sQuery raw search input
     * @return array
     */
    public static function searchOfferIds($sQuery): array
    {
        if (!is_string($sQuery) || trim($sQuery) === '') {
            return [];
        }

        $sQuery = trim($sQuery);
        $arOfferIdList = OfferCollection::make()->active()->search($sQuery)->getIDList();

        $arProductIdList = ProductCollection::make()->active()->search($sQuery)->getIDList();
        if (!empty($arProductIdList)) {
            $arProductOfferIdList = Offer::whereIn('product_id', $arProductIdList)
                ->pluck('id')
                ->all();
            $arOfferIdList = array_merge($arOfferIdList, $arProductOfferIdList);
        }

        return array_values(array_unique(array_map('intval', $arOfferIdList)));
    }
}
