<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Classes\Collection\OfferCollection;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * Builds the one offer each product card shows, for a whole list in one batch.
 *
 * A card reads obProduct.offer.sort('price|asc').first(), and first() builds that
 * single OfferItem on its own: one query per relation per card on a cold item cache.
 *
 * Twig, registered in Plugin::registerMarkupTags():
 *
 *   {% for obProduct in prime_card_offers(obProductList.page(iPage, iPerPage)) %}
 */
class ProductCardOfferPrimer
{
    /**
     * @param iterable|null $obProductList ProductItem list or ProductCollection the cards render from
     * @param string        $sSorting      the sort a card picks its offer with
     * @return iterable|null the same list, untouched
     */
    public static function prime($obProductList, $sSorting = 'price|asc')
    {
        if (!is_iterable($obProductList)) {
            return $obProductList;
        }

        $arOfferIDList = [];
        foreach ($obProductList as $obProductItem) {
            if (!$obProductItem instanceof ProductItem || $obProductItem->isEmpty()) {
                continue;
            }

            // A collection of its own: the one on the item is shared and sort() mutates it
            $arProductOfferIDList = $obProductItem->offer_id_list;
            if (empty($arProductOfferIDList)) {
                continue;
            }

            $arCardOfferIDList = OfferCollection::make($arProductOfferIDList)->sort($sSorting)->getIDList();
            if (!empty($arCardOfferIDList)) {
                $arOfferIDList[] = reset($arCardOfferIDList);
            }
        }

        if (!empty($arOfferIDList)) {
            OfferCollection::make($arOfferIDList)->all();
        }

        return $obProductList;
    }
}
