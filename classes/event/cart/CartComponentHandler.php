<?php namespace Logingrupa\StoreExtender\Classes\Event\Cart;

use Input;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Lovata\OrdersShopaholic\Components\Cart;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * Class CartComponentHandler
 * Extends the Cart component with pixel/tracking purchase data handler.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Cart
 */
class CartComponentHandler
{
    /**
     * Subscribe to Cart component extension
     */
    public function subscribe()
    {
        Cart::extend(function ($obCartComponent) {
            $obCartComponent->addDynamicMethod('onGetPixelPurchaseData', function () {
                return $this->getPixelPurchaseData();
            });
        });
    }

    /**
     * Build tracking pixel data for Facebook Pixel and Google Analytics purchase events.
     *
     * Returns cart position data, pricing, shipping, tax, and per-item details
     * with SKU IDs, prices, quantities, categories (breadcrumbs), and brand info.
     *
     * @return array
     */
    protected function getPixelPurchaseData()
    {
        $obCartProcessor = CartProcessor::instance();
        $obCartPositionList = $obCartProcessor->get();

        $arPositionTotalPriceData = $obCartProcessor->getCartPositionTotalPriceData()->getData();
        $arCartTotalPriceData = $obCartProcessor->getCartTotalPriceData()->getData();
        $arShippingPriceData = $obCartProcessor->getShippingPriceData()->getData();

        $sCurrencyCode = CurrencyHelper::instance()->getActiveCurrencyCode() ?? 'NOK';

        $arResult = [
            'content_type'            => 'product',
            'transaction_id'          => $this->generateTrackingTransactionId(),
            'contents'                => [],
            'items'                   => [],
            'content_ids'             => [],
            'value'                   => 0,
            'offer_value'             => 0,
            'cart_value'              => $arPositionTotalPriceData['price_value'] ?? 0,
            'cart_value_with_shipping' => $arCartTotalPriceData['price_value'] ?? 0,
            'tax'                     => $arCartTotalPriceData['tax_price_value'] ?? 0,
            'cart_discount'           => $arCartTotalPriceData['discount_price_value'] ?? 0,
            'shipping'                => PriceHelper::toFloat($arShippingPriceData['price_value'] ?? 0),
            'num_items'               => 0,
            'currency'                => $sCurrencyCode,
            'payment_method_id'       => (int) Input::get('payment_method_id', 0),
            'shipping_type_id'        => (int) Input::get('shipping_type_id', 0),
        ];

        if ($obCartPositionList->isEmpty()) {
            return $arResult;
        }

        $fOfferValueTotal = 0;

        foreach ($obCartPositionList as $obCartPositionItem) {
            $obOfferItem = $obCartPositionItem->item;
            if (empty($obOfferItem) || $obOfferItem->isEmpty()) {
                continue;
            }

            $obProductItem = $obOfferItem->product;
            $iQuantity = (int) $obCartPositionItem->quantity;
            $fPriceValue = (float) $obCartPositionItem->price_value;
            $fOfferValueTotal += $fPriceValue;

            $sSkuId = $this->buildSkuId($obOfferItem);

            // Facebook Pixel content item
            $arResult['contents'][] = [
                'id'       => $sSkuId,
                'quantity' => $iQuantity,
                'price'    => $fPriceValue,
            ];

            $arResult['content_ids'][] = $sSkuId;

            // Google Analytics item
            $arGoogleItem = [
                'item_id'      => $sSkuId,
                'item_name'    => $obOfferItem->name,
                'price'        => $fPriceValue,
                'quantity'     => $iQuantity,
            ];

            // Add brand if available
            if (!empty($obProductItem) && !$obProductItem->isEmpty() && !empty($obProductItem->brand) && !$obProductItem->brand->isEmpty()) {
                $arGoogleItem['item_brand'] = $obProductItem->brand->name;
            }

            // Add category breadcrumb levels (up to 5)
            if (!empty($obProductItem) && !$obProductItem->isEmpty()) {
                $arCategoryNames = $this->getCategoryBreadcrumb($obProductItem);
                foreach ($arCategoryNames as $iIndex => $sCategoryName) {
                    $sKey = ($iIndex === 0) ? 'item_category' : 'item_category' . ($iIndex + 1);
                    $arGoogleItem[$sKey] = $sCategoryName;
                }
            }

            $arResult['items'][] = $arGoogleItem;
            $arResult['num_items'] += $iQuantity;
        }

        $arResult['offer_value'] = $fOfferValueTotal;
        $arResult['value'] = $arPositionTotalPriceData['price_value'] ?? $fOfferValueTotal;

        return $arResult;
    }

    /**
     * Build SKU identifier for tracking.
     * Single-offer products: SKU-{product_id}
     * Multi-offer products: SKU-{product_id}-{offer_id}
     *
     * @param \Lovata\Shopaholic\Classes\Item\OfferItem $obOfferItem
     * @return string
     */
    protected function buildSkuId($obOfferItem)
    {
        $iProductId = $obOfferItem->product_id;
        $iOfferId = $obOfferItem->id;

        $iOfferCount = Offer::where('product_id', $iProductId)->count();

        if ($iOfferCount <= 1) {
            return 'SKU-' . $iProductId;
        }

        return 'SKU-' . $iProductId . '-' . $iOfferId;
    }

    /**
     * Build category breadcrumb array from product's main category up to root.
     * Returns up to 5 category names ordered from root to leaf.
     *
     * @param \Lovata\Shopaholic\Classes\Item\ProductItem $obProductItem
     * @return array
     */
    protected function getCategoryBreadcrumb($obProductItem)
    {
        $obCategoryItem = $obProductItem->category;
        if (empty($obCategoryItem) || $obCategoryItem->isEmpty()) {
            return [];
        }

        $arCategoryNames = [];
        $obCurrentCategory = $obCategoryItem;

        // Walk up the category tree
        while (!empty($obCurrentCategory) && !$obCurrentCategory->isEmpty() && count($arCategoryNames) < 5) {
            array_unshift($arCategoryNames, $obCurrentCategory->name);
            $obCurrentCategory = $obCurrentCategory->parent;
        }

        return array_slice($arCategoryNames, 0, 5);
    }

    /**
     * Generate a unique transaction ID for tracking purposes.
     * Format: YYYYMMDD-HHMMSS-XXXX (date-time-random)
     *
     * @return string
     */
    protected function generateTrackingTransactionId()
    {
        return date('Ymd-His') . '-' . str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}
