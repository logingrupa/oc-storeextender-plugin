<?php namespace Logingrupa\StoreExtender\Classes\Event\CartPosition;

use Lovata\OrdersShopaholic\Classes\Item\CartPositionItem;
use Lovata\CouponsShopaholic\Classes\Helper\CouponHelper;
use Lovata\CouponsShopaholic\Classes\Store\ProductListStore;
use Lovata\CouponsShopaholic\Classes\Store\OfferListStore;

/**
 * Class CartPositionItemHandler
 * @package Logingrupa\StoreExtender\Classes\Event\CartPosition
 */
class CartPositionItemHandler
{
    /**
     * Subscribe
     */
    public function subscribe()
    {
        CartPositionItem::extend(function ($obCartPositionItem) {
            $obCartPositionItem->addDynamicMethod('checkDiscountByCouponForProducts', function () use ($obCartPositionItem) {
                /** @var \Lovata\OrdersShopaholic\Classes\Item\CartPositionItem $obCartPositionItem */
                return $this->checkDiscountByCouponForProducts($obCartPositionItem);
            });
        });
    }

    /**
     * Check discount by coupon for products
     * @param CartPositionItem $obCartPositionItem
     * @return bool
     */
    protected function checkDiscountByCouponForProducts($obCartPositionItem)
    {
        $arAppliedCouponList = CouponHelper::instance()->getAppliedCouponList();

        if (empty($arAppliedCouponList) || !is_array($arAppliedCouponList)) {
            return true;
        }

        $obOffer = $obCartPositionItem->offer;
        $obCoupon = array_shift($arAppliedCouponList);

        if ($obOffer->isEmpty() || empty($obOffer->product_id) || empty($obCoupon)) {
            return false;
        }

        $arProductIDList = ProductListStore::instance()->coupon_group->get($obCoupon->group_id);
        $arOfferIDList = OfferListStore::instance()->coupon_group->get($obCoupon->group_id);

        return !(in_array($obOffer->product_id, $arProductIDList) || in_array($obOffer->id, $arOfferIDList));
    }
}
