<?php namespace Logingrupa\Storeextender\Components;

use Event;
use Request;
use System\Classes\PluginManager;
use Lovata\Toolbox\Classes\Component\ElementPage;

use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Models\Settings;
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
            $this->registerProductOpen($obElement);
        }

        return $obElement;
    }

    /**
     * Count a product view.
     *
     * A page render fires shopaholic.product.open as before. An AJAX postback
     * counts the view WITHOUT firing it, because of what the one listener
     * behind that event does with the two halves of its job.
     *
     * Lovata's PopularityHandler increments the product's popularity AND
     * clears the whole catalogue's popularity sorting cache, in one closure.
     * The second half is the expensive one and the wrong one to repeat here:
     * this component's page cycle re-runs on every postback the product page
     * makes, so one visitor flipping through shades drops the shop's sorted
     * product list over and over, and the next catalogue page that sorts by
     * popularity rebuilds it from scratch.
     *
     * The count is what a view is worth and it is unchanged - every request
     * that counted before still counts.
     */
    protected function registerProductOpen(Product $obElement): void
    {
        if (!Request::ajax()) {
            Event::fire('shopaholic.product.open', [$obElement]);

            return;
        }

        $this->incrementPopularity($obElement);
    }

    /**
     * The increment half of Lovata's listener, on its own.
     *
     * Deliberately duplicated rather than reached into: the listener is a
     * closure registered on the event and the work is a protected method, so
     * the alternatives were to copy these three lines or to tear the listener
     * off the event for every emitter on the site. Zero points is a no-op
     * here rather than an UPDATE that adds nothing.
     */
    protected function incrementPopularity(Product $obElement): void
    {
        if (!PluginManager::instance()->hasPlugin('Lovata.PopularityShopaholic')) {
            return;
        }

        $iPoint = (int) Settings::getValue('popularity_open_product');
        if ($iPoint < 1) {
            return;
        }

        Product::where('id', $obElement->id)->increment('popularity', $iPoint);
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
     * The offer the :offer URL segment names, when it belongs to this
     * product. Null on a canonical URL: the page then selects the first
     * shade of the strip it renders. The old fallback here - cheapest active
     * offer - picked a shade by price while the strip orders by colour
     * family, so the checked circle sat mid-strip instead of first.
     */
    public function getSelectedOfferItem(ProductItem $obProductItem): ?OfferItem
    {
        $iOfferID = (int) $this->param('offer');
        if ($iOfferID < 1) {
            return null;
        }
        $obOfferItem = $obProductItem->offer->find($iOfferID);

        return !empty($obOfferItem) && $obOfferItem->isNotEmpty() ? $obOfferItem : null;
    }
}
