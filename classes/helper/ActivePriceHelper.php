<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Support\Traits\Singleton;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Collection\ProductCollection;
use Lovata\DiscountsShopaholic\Classes\Item\DiscountItem;
use Lovata\DiscountsShopaholic\Models\Discount;


/**
 * Class ActivePriceHelper
 * @package Logingrupa\StoreExtender\Classes\Helper
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ActivePriceHelper
{
    use Singleton;

    const REGULAR_DISCOUNT = 'regular';
    const AUTHORIZED_DISCOUNT = 'authorized';

    /** @var ProductCollection */
    protected $obRegularDiscountProductList;

    /** @var DiscountItem */
    protected $obRegularDiscount;

    /** @var ProductCollection */
    protected $obAuthorizedDiscountProductList;

    /** @var DiscountItem */
    protected $obAuthorizedDiscount;

    /** @var \Lovata\Shopaholic\Models\PriceType */
    protected $obActivePriceType;

    /**
     * Set active price type
     */
    public function setActivePriceType()
    {
        if (!empty($this->obActivePriceType)) {
            PriceTypeHelper::instance()->switchActive($this->obActivePriceType->code);
        }
    }

    /**
     * Get product list with regular discount
     * @return ProductCollection
     */
    public function getRegularDiscountProductList()
    {
        if ($this->obRegularDiscountProductList instanceof ProductCollection) {
            return $this->obRegularDiscountProductList;
        }

        $obDiscountItem = $this->getRegularDiscount();
        $this->obRegularDiscountProductList = $obDiscountItem->product;

        return $this->obRegularDiscountProductList;
    }

    /**
     * Get regular discount item
     * @return DiscountItem
     */
    public function getRegularDiscount()
    {
        if (!empty($this->obRegularDiscount)) {
            return $this->obRegularDiscount;
        }

        $obDiscount = Discount::getByCode(self::REGULAR_DISCOUNT)->first();
        if (empty($obDiscount)) {
            return DiscountItem::make(null);
        }

        $this->obRegularDiscount = DiscountItem::make($obDiscount->id, $obDiscount);

        return $this->obRegularDiscount;
    }

    /**
     * Get product list with authorized discount
     * @return ProductCollection
     */
    public function getAuthorizedDiscountProductList()
    {
        if ($this->obAuthorizedDiscountProductList instanceof ProductCollection) {
            return $this->obAuthorizedDiscountProductList;
        }

        $obDiscountItem = $this->getAuthorizedDiscount();
        $this->obAuthorizedDiscountProductList = $obDiscountItem->product;

        return $this->obAuthorizedDiscountProductList;
    }

    /**
     * Get regular discount item
     * @return DiscountItem
     */
    public function getAuthorizedDiscount()
    {
        if (!empty($this->obAuthorizedDiscount)) {
            return $this->obAuthorizedDiscount;
        }

        $obDiscount = Discount::getByCode(self::AUTHORIZED_DISCOUNT)->first();
        if (empty($obDiscount)) {
            return DiscountItem::make(null);
        }

        $this->obAuthorizedDiscount = DiscountItem::make($obDiscount->id, $obDiscount);

        return $this->obAuthorizedDiscount;
    }

    /**
     * Set active price type
     */
    public function getActivePriceType()
    {
        return $this->obActivePriceType;
    }

    /**
     * The primary group alone sets the price tier. No group or no price type is retail.
     */
    protected function init()
    {
        $obUser = UserHelper::instance()->getUser();
        if (empty($obUser)) {
            return;
        }

        $obPrimaryGroup = $obUser->primary_group;
        if (empty($obPrimaryGroup) || empty($obPrimaryGroup->price_type_id)) {
            return;
        }

        $this->obActivePriceType = $obPrimaryGroup->price_type;
    }
}
