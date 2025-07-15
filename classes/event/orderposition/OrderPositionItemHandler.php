<?php namespace Logingrupa\StoreExtender\Classes\Event\OrderPosition;

use Lovata\OrdersShopaholic\Classes\Item\OrderPositionItem;
use Lovata\CouponsShopaholic\Classes\Store\ProductListStore;
use Lovata\CouponsShopaholic\Classes\Store\OfferListStore;

/**
 * Class OrderPositionItemHandler
 * @package Logingrupa\StoreExtender\Classes\Event\OrderPosition
 */
class OrderPositionItemHandler
{
    /**
     * Subscribe
     */
    public function subscribe()
    {
        OrderPositionItem::extend(function ($obOrderPositionItem) {
            $obOrderPositionItem->addDynamicMethod('checkDiscountByCouponForProducts', function () use ($obOrderPositionItem) {
                /** @var \Lovata\OrdersShopaholic\Classes\Item\OrderPositionItem $obOrderPositionItem */
                return $this->checkDiscountByCouponForProducts($obOrderPositionItem);
            });
        });
    }

    /**
     * Check discount by coupon for products
     * @param OrderPositionItem $obOrderPositionItem
     * @return bool
     */
    protected function checkDiscountByCouponForProducts($obOrderPositionItem)
    {
        $obOrderItem = $obOrderPositionItem->order;

        if ($obOrderItem->isEmpty()) {
            return true;
        }

        $obOrder = $obOrderItem->getObject();

        if (empty($obOrder) || empty($obOrder->coupon)) {
            return true;
        }

        $obCoupon = $obOrder->coupon->first();
        $obOffer = $obOrderPositionItem->offer;

        if (empty($obCoupon) || $obOffer->isEmpty() || empty($obOffer->product_id)) {
            return false;
        }

        $arProductIDList = ProductListStore::instance()->coupon_group->get($obCoupon->group_id);
        $arOfferIDList = OfferListStore::instance()->coupon_group->get($obCoupon->group_id);

        return !(in_array($obOffer->product_id, $arProductIDList) || in_array($obOffer->id, $arOfferIDList));
    }
}
