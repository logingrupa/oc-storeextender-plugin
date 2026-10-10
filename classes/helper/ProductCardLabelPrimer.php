<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\DB;
use Kharanenka\Helper\CCache;
use Lovata\LabelsShopaholic\Classes\Store\Label\ListByProductStore;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * Fills the label id lists of a product card list in one statement.
 *
 * A card reads obProduct.label, one ListByProductStore read per product on a cold
 * cache. This writes the entries that store reads, and its clear() drops them.
 */
class ProductCardLabelPrimer
{
    const PIVOT_TABLE = 'lovata_labels_shopaholic_product_label';

    /**
     * @param iterable|null $obProductList ProductItem list or ProductCollection the cards render from
     * @return iterable|null the same list, untouched
     */
    public static function prime($obProductList)
    {
        if (!is_iterable($obProductList)) {
            return $obProductList;
        }

        $arMissingProductIDList = static::getMissingProductIDList($obProductList);
        if (empty($arMissingProductIDList)) {
            return $obProductList;
        }

        $arLabelIDMap = array_fill_keys($arMissingProductIDList, []);
        $obPivotRowList = DB::table(self::PIVOT_TABLE)
            ->whereIn('product_id', $arMissingProductIDList)
            ->get(['product_id', 'label_id']);
        foreach ($obPivotRowList as $obPivotRow) {
            $arLabelIDMap[(int) $obPivotRow->product_id][] = $obPivotRow->label_id;
        }

        foreach ($arLabelIDMap as $iProductID => $arLabelIDList) {
            CCache::forever([ListByProductStore::class], (string) $iProductID, $arLabelIDList);
        }

        return $obProductList;
    }

    /**
     * Products of the list whose label entry is not cached yet, the hit test the store uses.
     * @param iterable $obProductList
     * @return array<int, int>
     */
    protected static function getMissingProductIDList($obProductList): array
    {
        $arMissingProductIDList = [];
        foreach ($obProductList as $obProductItem) {
            if (!$obProductItem instanceof ProductItem || $obProductItem->isEmpty()) {
                continue;
            }

            $iProductID = (int) $obProductItem->id;
            if (is_array(CCache::get([ListByProductStore::class], (string) $iProductID))) {
                continue;
            }

            $arMissingProductIDList[$iProductID] = $iProductID;
        }

        return array_values($arMissingProductIDList);
    }
}
