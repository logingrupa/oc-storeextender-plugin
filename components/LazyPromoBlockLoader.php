<?php namespace Logingrupa\Storeextender\Components;

use Lovata\Toolbox\Classes\Component\ElementPage;
use Lovata\Shopaholic\Classes\Collection\ProductCollection;
use Lovata\Shopaholic\Classes\Collection\PromoBlockCollection;
use Lovata\Shopaholic\Classes\Item\PromoBlockItem;

/**
 * Class LazyPromoBlockLoader
 * @package Logingrupa\Storeextender\Components
 *
 * Renders promo block tabs with lazy-loaded product content.
 * Only tab navigation and skeleton placeholders are rendered on page load.
 * Product cards are fetched via AJAX when a tab becomes active.
 */
class LazyPromoBlockLoader extends \Cms\Classes\ComponentBase
{
    /**
     * @return array
     */
    public function componentDetails()
    {
        return [
            'name'        => 'Lazy Promo Block Loader',
            'description' => 'Lazy-loads promo block product tabs via AJAX with skeleton placeholders',
        ];
    }

    /**
     * @return array
     */
    public function defineProperties()
    {
        return [
            'sorting' => [
                'title'       => 'Promo block sorting',
                'description' => 'Sorting order for promo blocks',
                'type'        => 'dropdown',
                'default'     => 'default',
                'options'     => [
                    'default' => 'Default',
                ],
            ],
            'productsPerTab' => [
                'title'       => 'Products per tab',
                'description' => 'Maximum number of products to show per tab',
                'type'        => 'string',
                'default'     => '10',
                'validationPattern' => '^[0-9]+$',
                'validationMessage' => 'Must be a number',
            ],
        ];
    }

    /**
     * Prepare component data for rendering.
     *
     * Every tab is lazy, including the first. Building the first tab's product
     * list in onRun() put ten fully hydrated products - offers, prices, images,
     * labels - on the critical path of a page that renders nothing else from
     * them, and the markup it produced was the page's largest block. The tab now
     * ships a skeleton like the others and its content arrives over the same
     * handler, fired the moment the control connects rather than on a tab click,
     * so it is still requested immediately and never waits for a scroll.
     */
    public function onRun()
    {
        $this->addJs('/plugins/logingrupa/storeextender/assets/js/lazy-tab-control.js');

        $this->page['obPromoBlockList'] = PromoBlockCollection::make()->active()->sort(
            $this->property('sorting', 'default')
        );

        // The skeleton has to reserve room for as many cards as the handler will
        // return. Rendering a fixed five while the handler sent ten made the tab
        // double in height when the response landed - 0.18 of cumulative layout
        // shift on the home page, all of it from this one block.
        $this->page['iProductsPerTab'] = (int) $this->property('productsPerTab', 10);
    }

    /**
     * AJAX handler - loads product cards for a single promo block tab.
     *
     * @return array
     */
    public function onLoadPromoTab()
    {
        $sCode = (string) input('promo_block_code');
        $iPromoBlockId = (int) input('promo_block_id');
        $iLimit = (int) $this->property('productsPerTab', 10);

        $obPromoBlockItem = PromoBlockItem::make($iPromoBlockId);

        $obProductList = $this->getProductListByPromoBlock($obPromoBlockItem, $sCode, $iLimit);

        return [
            '#lazy-tab-content-' . $iPromoBlockId => $this->renderPartial('@tab-content', [
                'obProductList'   => $obProductList,
                'obPromoBlock'    => $obPromoBlockItem,
                'iLimit'          => $iLimit,
            ]),
        ];
    }

    /**
     * Get product collection for a given promo block.
     *
     * @param PromoBlockItem $obPromoBlockItem
     * @param string $sCode
     * @param int $iLimit
     * @return ProductCollection
     */
    protected function getProductListByPromoBlock($obPromoBlockItem, $sCode, $iLimit)
    {
        if ($obPromoBlockItem->product->isEmpty() && $sCode === 'new') {
            return ProductCollection::make()->sort('new')->active()->take($iLimit);
        }

        if ($obPromoBlockItem->product->isEmpty() && $sCode === 'sale') {
            return ProductCollection::make()->filterByDiscount()->take($iLimit);
        }

        return $obPromoBlockItem->product->active()->sort('new')->take($iLimit);
    }
}
