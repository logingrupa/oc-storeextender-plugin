<?php namespace Logingrupa\Storeextender\Components;

use Event;
use Lovata\Toolbox\Classes\Component\ElementPage;

use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * Class ProductPage
 * @package Logingrupa\Storeextender\Components
 * @author Rolands Zeltiņš, hi@logingrupa.lv, Logingrupa
 *
 * Compare for Shopaholic
 * @method array onAddToCompare()
 * @method array onRemoveFromCompare()
 * @method void onClearCompareList()
 *
 * Viewed products for Shopaholic
 * @method array onRemoveFromViewedProductList()
 * @method void onClearViewedProductList()
 *
 * Wish list for Shopaholic
 * @method array onAddToWishList()
 * @method array onRemoveFromWishList()
 * @method void onClearWishList()
 */
class CustomProductPage extends ElementPage
{
    protected $bNeedSmartURLCheck = true;

    /** @var \Lovata\Shopaholic\Models\Product */
    protected $obElement;

    /** @var \Lovata\Shopaholic\Classes\Item\ProductItem */
    protected $obElementItem;

    /**
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name' => 'lovata.shopaholic::lang.component.product_page_name',
            'description' => 'lovata.shopaholic::lang.component.product_page_description',
        ];
    }

    /**
     * Get element object
     * @param string $sElementSlug
     * @return Product
     */
    protected function getElementObject($sElementSlug)
    {
        if (empty($sElementSlug)) {
            return null;
        }

        if ($this->isSlugTranslatable()) {
            $obElement = Product::active()->transWhere('slug', $sElementSlug)->first();
            if (!$this->checkTransSlug($obElement, $sElementSlug)) {
                $obElement = null;
            }
        } else {
            $obElement = Product::getBySlug($sElementSlug)->first();
        }
        if (!empty($obElement)) {
            Event::fire('shopaholic.product.open', [$obElement]);
        }

        return $obElement;
    }

    /**
     * Make new element item
     * @param int $iElementID
     * @param Product $obElement
     * @return ProductItem
     */
    protected function makeItem($iElementID, $obElement)
    {
        return ProductItem::make($iElementID, $obElement);
    }

    /**
     * Resolve the offer shown on page load: the :offer URL param when it
     * belongs to this product, otherwise the cheapest active offer. One
     * resolution point for the whole page - partials receive the result
     * instead of re-running offer.find() each.
     */
    public function getSelectedOfferItem(ProductItem $obProductItem): ?OfferItem
    {
        $iOfferID = (int) $this->param('offer');
        if ($iOfferID > 0) {
            $obOfferItem = $obProductItem->offer->find($iOfferID);
            if (!empty($obOfferItem) && $obOfferItem->isNotEmpty()) {
                return $obOfferItem;
            }
        }

        $obOfferItem = $obProductItem->offer->active()->sort('price|asc')->first();

        return !empty($obOfferItem) && $obOfferItem->isNotEmpty() ? $obOfferItem : null;
    }
}
