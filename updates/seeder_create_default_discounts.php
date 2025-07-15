<?php namespace Logingrupa\StoreExtender\Updates;

use Seeder;

use Lovata\Shopaholic\Models\PriceType;
use Lovata\DiscountsShopaholic\Models\Discount;

/**
 * Class SeederCreateDefaultDiscounts
 * @package Logingrupa\StoreExtender\Updates
 */
class SeederCreateDefaultDiscounts extends Seeder
{
    protected $arDiscountList = [
        [
            'active' => false,
            'name' => 'Discount for all users - 10%',
            'code' => 'regular',
            'date_begin' => '2019-05-01 00:00:00',
            'discount_value' => 10,
            'discount_type' => 'percent',
        ],
        [
            'active' => false,
            'name' => 'Discount for authorized users - 20%',
            'code' => 'authorized',
            'date_begin' => '2019-05-01 00:00:00',
            'discount_value' => 20,
            'discount_type' => 'percent',
        ],
    ];

    protected $arPriceTypeList = [
        [
            'active' => true,
            'name' => 'Authorized',
            'code' => 'authorized',
        ],
    ];

    /**
     * Run seeder
     */
    public function run()
    {
        foreach ($this->arDiscountList as $arDiscountData) {
            $obDiscount = Discount::getByCode(array_get($arDiscountData, 'code'))->first();
            try {
                if (empty($obDiscount)) {
                    Discount::create($arDiscountData);
                } else {
                    $obDiscount->update($arDiscountData);
                }
            } catch (\Exception $obException) {
            }
        }

        foreach ($this->arPriceTypeList as $arPriceTypeData) {
            $obPriceType = PriceType::getByCode(array_get($arPriceTypeData, 'code'))->first();
            try {
                if (empty($obPriceType)) {
                    PriceType::create($arPriceTypeData);
                } else {
                    $obPriceType->update($arPriceTypeData);
                }
            } catch (\Exception $obException) {
            }
        }
    }
}
