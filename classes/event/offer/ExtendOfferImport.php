<?php namespace Logingrupa\StoreExtender\Classes\Event\Offer;

use Lovata\Toolbox\Classes\Helper\PriceHelper;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Import\ImportOfferPriceFromXML;
use Lovata\Shopaholic\Classes\Import\ImportOfferModelFromXML;
use Lovata\DiscountsShopaholic\Models\Discount;

use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
use October\Rain\Argon\Argon;
use Pheanstalk\Exception;

/**
 * Class ExtendOfferImport
 * @package Logingrupa\StoreExtender\Classes\Event\Offer
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendOfferImport
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(ImportOfferModelFromXML::EXTEND_FIELD_LIST, function ($arFieldList) {
            array_set($arFieldList, 'discount_name', trans('lovata.basecode::lang.field.discount_name'));
            array_set($arFieldList, 'discount_value', trans('lovata.basecode::lang.field.discount_value'));
            array_set($arFieldList, 'discount_date_end', trans('lovata.basecode::lang.field.discount_date_end'));
            array_set($arFieldList, 'value_condition', trans('lovata.basecode::lang.field.value_condition'));

            return $arFieldList;
        }, 1000);

        $obEvent->listen(ImportOfferPriceFromXML::EXTEND_FIELD_LIST, function ($arFieldList) {
            array_set($arFieldList, 'discount_name', trans('lovata.basecode::lang.field.discount_name'));
            array_set($arFieldList, 'discount_value', trans('lovata.basecode::lang.field.discount_value'));
            array_set($arFieldList, 'discount_date_end', trans('lovata.basecode::lang.field.discount_date_end'));
            array_set($arFieldList, 'value_condition', trans('lovata.basecode::lang.field.value_condition'));

            return $arFieldList;
        }, 1000);

        $obEvent->listen(ImportOfferModelFromXML::EXTEND_IMPORT_DATA, function ($arImportData, $obParseNode) {
            $arImportData = $this->fixExternalID($arImportData);
            $arImportData = $this->fixQuantity($arImportData);
            $arImportData = $this->fixVariationText($arImportData);
            $arImportData = $this->fixWeight($arImportData);
            $arImportData = $this->fixHeight($arImportData);
            $arImportData = $this->fixLength($arImportData);
            $arImportData = $this->fixWidth($arImportData);
            // dd($arImportData);
            return $arImportData;
        });

        $obEvent->listen(ImportOfferPriceFromXML::EXTEND_IMPORT_DATA, function ($arImportData, $obParseNode) {
            $arImportData = $this->fixExternalID($arImportData);
            array_forget($arImportData, 'product_id');
            $arImportData = $this->applyOfferDiscount($arImportData);
            $arImportData = $this->applyVatToSalonaPriceOffers($arImportData);
            $arImportData = $this->applyOldPriceToIzplatitajuPriceOffers($arImportData);
            $arImportData = $this->applyOldPriceAndApplyVatToVairumPriceOffers($arImportData);
            return $arImportData;
        });

        $obEvent->listen(ImportOfferModelFromXML::EVENT_BEFORE_IMPORT, function ($sModelClass, $arImportData) {
            if ($sModelClass != Offer::class) {
                return null;
            }

            return $this->calculatePrices($arImportData);
        });

        $obEvent->listen(ImportOfferModelFromXML::EVENT_AFTER_IMPORT, function ($obOffer, $arImportData) {
            $this->updateDiscountSync($obOffer);
        });
    }

    /**
     * Fix external ID and product ID
     * @param array $arImportData
     * @return mixed
     */
    protected function fixExternalID($arImportData)
    {
        $sExternalID = array_pull($arImportData, 'external_id');
        $arPartList = explode('#', $sExternalID);
        if (count($arPartList) == 2) {
            $arImportData['product_id'] = array_shift($arPartList);
            $arImportData['external_id'] = array_shift($arPartList);
        }

        return $arImportData;
    }

    /**
     * Fix quantity value - remove spaces from thousnds
     * @param array $arImportData
     * @return mixed
     */
    protected function fixQuantity($arImportData)
    {
        $sQuantity = array_pull($arImportData, 'quantity');
        $arImportData['quantity'] = preg_replace("/ /", "", $sQuantity);
        return $arImportData;
    }

    /**
     * Fix variation value - remove text and leave just variation color/volume/name/size
     * @param array $arImportData
     * @return mixed
     */
    protected function fixVariationText($arImportData)
    {
        $sOldVariation = array_pull($arImportData, 'variation');
        $matches = null;
        $sNewVariation = preg_match('/\((.*?)\)/', $sOldVariation, $matches);
        $arImportData['variation'] = (empty($matches)) ? null : $matches[1];
        return $arImportData;
    }

    /**
     * Fix weight value - check if its number if not number, set to null
     * @param array $arImportData
     * @return mixed
     */
    protected function fixWeight($arImportData)
    {
        $sWeight = array_pull($arImportData, 'weight');
        $arImportData['weight'] = (is_numeric($sWeight) ? $sWeight : null);
        return $arImportData;
    }

    /**
     * Fix height value - check if its number if not number, set to null
     * @param array $arImportData
     * @return mixed
     */
    protected function fixHeight($arImportData)
    {
        $sHeight = array_pull($arImportData, 'height');
        $arImportData['height'] = (is_numeric($sHeight) ? $sHeight : null);
        return $arImportData;
    }

    /**
     * Fix length value - check if its number if not number, set to null
     * @param array $arImportData
     * @return mixed
     */
    protected function fixLength($arImportData)
    {
        $sLength = array_pull($arImportData, 'length');
        $arImportData['length'] = (is_numeric($sLength) ? $sLength : null);
        return $arImportData;
    }

    /**
     * Fix width value - check if its number if not number, set to null
     * @param array $arImportData
     * @return mixed
     */
    protected function fixWidth($arImportData)
    {
        $sWidth = array_pull($arImportData, 'width');
        $arImportData['width'] = (is_numeric($sWidth) ? $sWidth : null);
        return $arImportData;
    }

    /**
     * Apply apply discount
     *
     * @param array $arImportData
     *
     * @return array
     */
    protected function applyOfferDiscount($arImportData)
    {
        $sDiscountDateEnd = array_pull($arImportData, 'discount_date_end', '');
        $fDiscountPercentage = array_pull($arImportData, 'discount_value');
        $sDiscountName = array_pull($arImportData, 'discount_name');
        $sDiscountValueCondition = array_pull($arImportData, 'value_condition');

        $arDiscountData = $this->getDiscountData($sDiscountDateEnd, $fDiscountPercentage, $sDiscountName, $sDiscountValueCondition);

        $sDiscountName = array_pull($arDiscountData, 'discount_name', '');
        $sDiscountDateEnd = array_pull($arDiscountData, 'discount_date_end', '');
        $fDiscountPercentage = PriceHelper::toFloat(array_pull($arDiscountData, 'discount_value', ''));
        $fPrice = PriceHelper::toFloat(array_get($arImportData, 'price'));

        if (empty($sDiscountDateEnd) || empty($fDiscountPercentage) || empty($fPrice)) {
            return $arImportData;
        }

        try {
            $obDiscountDateEnd = Argon::parse($sDiscountDateEnd);
        } catch (Exception $obException) {
            $obDiscountDateEnd = null;
        }

        if (empty($obDiscountDateEnd) || empty($fDiscountPercentage)) {
            return $arImportData;
        }

        /**
         * @var Discount $obDiscount
         */
        $obDiscount = Discount::where('discount_value', $fDiscountPercentage)
            ->where('date_end', $obDiscountDateEnd->toDateString())
            ->first();

        try {
            if (empty($obDiscount)) {
                $obDiscount = Discount::create([
                    'active' => true,
                    'name' => $sDiscountName,
                    'discount_value' => $fDiscountPercentage,
                    'discount_type' => Discount::PERCENT_TYPE,
                    'date_begin' => Argon::now(),
                    'date_end' => $obDiscountDateEnd,
                ]);
            } else {
                $obDiscount->update(['name' => $sDiscountName]);
            }
        } catch (Exception $obException) {
            return $arImportData;
        }

        if (empty($obDiscount)) {
            return $arImportData;
        }

        $arImportData['discount_id'] = $obDiscount->id;
        $arImportData['discount_type'] = Discount::PERCENT_TYPE;
        $arImportData['discount_value'] = $fDiscountPercentage;
        $arImportData['old_price'] = $fPrice;
        $arImportData['price'] = PriceHelper::round($fPrice - $fPrice * ($fDiscountPercentage / 100));

        return $arImportData;
    }

    /**
     * Get discount data
     *
     * @param string|array $sDiscountDateEnd
     * @param string|array $fDiscountPercentage
     * @param string|array $sDiscountName
     * @param string|array $sDiscountValueCondition
     *
     * @return array
     */
    protected function getDiscountData($sDiscountDateEnd, $fDiscountPercentage, $sDiscountName, $sDiscountValueCondition): array
    {
        if (!empty($sDiscountValueCondition)) {
            return [];
        }

        if (!is_array($sDiscountDateEnd) && !is_array($fDiscountPercentage) && !is_array($sDiscountName)) {
            return [
                'discount_name' => $sDiscountName,
                'discount_date_end' => $sDiscountDateEnd,
                'discount_value' => $fDiscountPercentage,
            ];
        }

        arsort($sDiscountDateEnd);
        $iKey = key($sDiscountDateEnd);
        $sDiscountDateEnd = array_pull($sDiscountDateEnd, $iKey);

        return [
            'discount_name' => array_pull($sDiscountName, $iKey),
            'discount_date_end' => $sDiscountDateEnd,
            'discount_value' => array_pull($fDiscountPercentage, $iKey),
        ];
    }

    /**
     * Manually add 21% VAT to salona price type
     * @param integer $iProductId
     * @param array $arImportData
     * @return array
     */
    protected function applyVatToSalonaPriceOffers($arImportData)
    {
        $obSalonaPriceType = PriceTypeHelper::instance()->findByCode('salona');

        $fOriginalPrice = PriceHelper::toFloat(array_get($arImportData, 'price')); //9.58
        $fOriginalOldPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price')); //0.00

        $fSalonaPrice = PriceHelper::toFloat(array_get($arImportData, 'price_list.2.price')); //6.61
        if ($fOriginalOldPrice == $fOriginalPrice || $fOriginalOldPrice == $fSalonaPrice) {
            $fOriginalOldPrice = 0;
        }
        
        
        $arPriceData['price'] = $fOriginalPrice; //9.58
        $arPriceData['old_price'] = $fOriginalOldPrice;  //0.00
        // $arPriceData['salon_price'] = $fSalonaPrice * 1.21;

        $fSalonaPricePlusVAT = $fSalonaPrice * 1.21; //8.00
        if ($fSalonaPricePlusVAT > $fOriginalPrice) { //8.00 > 9.58 = false
            $arPriceData['price'] = $fSalonaPrice = $fOriginalPrice; // 9.58 = 6.61 = 9.58
            $arPriceData['old_price'] = $fSalonaOldPrice = $fOriginalOldPrice; //0.00
        } else {
            // dd($arPriceData['price']);
            $arPriceData['price'] = $fSalonaPrice = PriceHelper::round($fSalonaPricePlusVAT);
            $arPriceData['old_price'] = $fSalonaOldPrice = $fOriginalPrice;
        }
            // dd($fOriginalOldPrice == $fOriginalPrice);
        if (empty($fSalonaPrice) || $fSalonaPrice == 0) {
            $arPriceData['price'] = $fSalonaPrice = $fOriginalPrice;
            $arPriceData['old_price'] = $fSalonaOldPrice = $fOriginalOldPrice;
        }

        array_set($arImportData, 'price_list.' . $obSalonaPriceType->id, $arPriceData);
        // dd($arImportData);
        return $arImportData;


        // $fPrice = PriceHelper::toFloat(array_get($arImportData, 'price'));
        // $fOldPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price'));
        // $fSalonaPrice = PriceHelper::toFloat(array_get($arImportData, 'price_list.2.price'));
        // if ($fSalonaPrice > $fPrice) {
        //     $arPriceData['price'] = $fSalonaPrice = $fPrice;
        //     $arPriceData['old_price'] = $fSalonaOldPrice = $fOldPrice;
        // } elseif (empty($fSalonaPrice) || $fSalonaPrice == 0) {
        //     $arPriceData['price'] = $fSalonaPrice = $fPrice;
        //     $arPriceData['old_price'] = $fSalonaOldPrice = $fOldPrice;
        // } else {
        //     $arPriceData['price'] = $fSalonaPrice = PriceHelper::round($fSalonaPrice * 1.21);
        //     if (empty($fOldPrice) || $fOldPrice == 0) {
        //         $arPriceData['old_price'] = $fSalonaOldPrice = $fPrice;
        //     } else {
        //         $arPriceData['old_price'] = $fSalonaOldPrice = $fOldPrice;
        //     }
        // }
        // // dd($arPriceData);
        // array_set($arImportData, 'price_list.'.$obPriceType->id, $arPriceData);

        // return $arImportData;
    }

    /**
     * Manually OldPrice to Izplatitaju price type - so Izplatitaji can see sales price
     * @param integer $iProductId
     * @param array $arImportData
     * @return array
     */
    protected function applyOldPriceToIzplatitajuPriceOffers($arImportData)
    {
        $obIzplatPriceType = PriceTypeHelper::instance()->findByCode('izpl');
        $fOriginalPrice = PriceHelper::toFloat(array_get($arImportData, 'price')); //9.58
        $fOriginalOldPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price')); //0.00
        
        $fIzplatPrice = PriceHelper::toFloat(array_get($arImportData, 'price_list.3.price')); //0.49
        if ($fOriginalOldPrice == $fOriginalPrice || $fOriginalOldPrice == $fIzplatPrice) {
            $fOriginalOldPrice = 0;
        }        
        
        $arPriceData['price'] = $fOriginalPrice; //9.58
        $arPriceData['old_price'] = $fOriginalOldPrice;  //0.00
        // $arPriceData['salon_price'] = $fIzplatPrice * 1.21;

        // dd(array_get($arImportData, 'price_list.3.price'));
        $fIzplatPricePlusVAT = $fIzplatPrice; //* 1.21; //8.00
        if ($fIzplatPricePlusVAT > $fOriginalPrice) { //8.00 > 9.58 = false
            $arPriceData['price'] = $fIzplatPrice = $fOriginalPrice; // 9.58 = 0.49 = 9.58
            $arPriceData['old_price'] = $fIzplatOldPrice = $fOriginalOldPrice; //0.00
        } else {
            $arPriceData['price'] = $fIzplatPrice = PriceHelper::round($fIzplatPricePlusVAT);
            $arPriceData['old_price'] = $fIzplatOldPrice = $fOriginalPrice;
        }
           
        if (empty($fIzplatPrice) || $fIzplatPrice == 0) {
            $arPriceData['price'] = $fIzplatPrice = $fOriginalPrice;
            $arPriceData['old_price'] = $fIzplatOldPrice = $fOriginalOldPrice;
        }

        array_set($arImportData, 'price_list.' . $obIzplatPriceType->id, $arPriceData);
        // dd($arImportData);
        return $arImportData;
    }
    protected function applyOldPriceAndApplyVatToVairumPriceOffers($arImportData)
    {
        $obVairumPriceType = PriceTypeHelper::instance()->findByCode('vairum');
        $fOriginalPrice = PriceHelper::toFloat(array_get($arImportData, 'price')); //9.58
        $fOriginalOldPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price')); //0.00
        
        $fVairumPrice = PriceHelper::toFloat(array_get($arImportData, 'price_list.1.price')); //0.49
        if ($fOriginalOldPrice == $fOriginalPrice || $fOriginalOldPrice == $fVairumPrice) {
            $fOriginalOldPrice = 0;
        }        
        
        $arPriceData['price'] = $fOriginalPrice; //9.58
        $arPriceData['old_price'] = $fOriginalOldPrice;  //0.00
        // $arPriceData['salon_price'] = $fVairumPrice * 1.21;

        // dd(array_get($arImportData, 'price_list.3.price'));
        $fVairumPricePlusVAT = $fVairumPrice * 1.21; //8.00
        if ($fVairumPricePlusVAT > $fOriginalPrice) { //8.00 > 9.58 = false
            $arPriceData['price'] = $fVairumPrice = $fOriginalPrice; // 9.58 = 0.49 = 9.58
            $arPriceData['old_price'] = $fVairumOldPrice = $fOriginalOldPrice; //0.00
        } else {
            $arPriceData['price'] = $fVairumPrice = PriceHelper::round($fVairumPricePlusVAT);
            $arPriceData['old_price'] = $fVairumOldPrice = $fOriginalPrice;
        }
           
        if (empty($fVairumPrice) || $fVairumPrice == 0) {
            $arPriceData['price'] = $fVairumPrice = $fOriginalPrice;
            $arPriceData['old_price'] = $fVairumOldPrice = $fOriginalOldPrice;
        }

        array_set($arImportData, 'price_list.' . $obVairumPriceType->id, $arPriceData);
        // dd($arImportData);
        return $arImportData;
    }

    /**
     * @param array $arImportData
     * @return array
     */
    protected function calculatePrices($arImportData)
    {
        $fOldPrice = (float)array_get($arImportData, 'old_price');
        $arImportData['old_price'] = $fOldPrice;

        $iProductId = array_get($arImportData, 'product_id');

        if (empty($iProductId)) {
            return $arImportData;
        }

        $arImportData = $this->applyOfferDiscount($arImportData);
        $arImportData = $this->applyAuthorizedDiscount($iProductId, $arImportData);
        $arImportData = $this->applyRegularDiscount($iProductId, $arImportData);

        return $arImportData;
    }

    /**
     * Apply authorized discount
     * @param integer $iProductId
     * @param array $arImportData
     * @return array
     */
    protected function applyAuthorizedDiscount($iProductId, $arImportData)
    {
        $obProductList = ActivePriceHelper::instance()->getAuthorizedDiscountProductList();

        $obPriceType = PriceTypeHelper::instance()->findByCode(ActivePriceHelper::AUTHORIZED_DISCOUNT);
        $obDiscountItem = ActivePriceHelper::instance()->getAuthorizedDiscount();
        if ($obDiscountItem->isEmpty() || empty($obPriceType)) {
            return $arImportData;
        }

        $fPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price'));

        if (empty($fPrice) || $fPrice == 0) {
            $fPrice = PriceHelper::toFloat(array_get($arImportData, 'price'));
        }

        $fOldPrice = $fPrice;

        $fDiscountValue = array_get($arImportData, 'discount_value', null);

        if ($obProductList->has($iProductId) && (empty($fDiscountValue) || $fDiscountValue < $obDiscountItem->discount_value)) {
            $fDiscountValue = $obDiscountItem->discount_value;
        }

        //Apply discount
        if (!empty($fDiscountValue)) {
            if ($obDiscountItem->discount_type == Discount::FIXED_TYPE) {
                $fPrice = PriceHelper::round($fPrice - $fDiscountValue);
            } elseif ($obDiscountItem->discount_type == Discount::PERCENT_TYPE) {
                $fPrice = PriceHelper::round($fPrice - $fPrice * ($fDiscountValue / 100));
            }
        }

        if ($fOldPrice == $fPrice) {
            $fOldPrice = 0;
        }

        $arPriceData['price'] = $fPrice;
        $arPriceData['old_price'] = $fOldPrice;

        array_set($arImportData, 'price_list.' . $obPriceType->id, $arPriceData);

        return $arImportData;
    }

    /**
     * Apply regular discount
     * @param integer $iProductId
     * @param array $arImportData
     * @return array
     */
    protected function applyRegularDiscount($iProductId, $arImportData)
    {
        $obProductList = ActivePriceHelper::instance()->getRegularDiscountProductList();

        $obPriceType = PriceTypeHelper::instance()->findByCode(ActivePriceHelper::REGULAR_DISCOUNT);
        $obDiscountItem = ActivePriceHelper::instance()->getRegularDiscount();
        if ($obDiscountItem->isEmpty() || empty($obPriceType)) {
            return $arImportData;
        }

        $fPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price'));

        if (empty($fPrice) || $fPrice == 0) {
            $fPrice = PriceHelper::toFloat(array_get($arImportData, 'price'));
        }

        $fOldPrice = $fPrice;

        $fDiscountValue = array_get($arImportData, 'discount_value', null);

        if ($obProductList->has($iProductId) && (empty($fDiscountValue) || $fDiscountValue < $obDiscountItem->discount_value)) {
            $fDiscountValue = $obDiscountItem->discount_value;
        }

        if (!empty($fDiscountValue)) {
            //Apply discount
            if ($obDiscountItem->discount_type == Discount::FIXED_TYPE) {
                $fPrice = PriceHelper::round($fPrice - $fDiscountValue);
            } elseif ($obDiscountItem->discount_type == Discount::PERCENT_TYPE) {
                $fPrice = PriceHelper::round($fPrice - $fPrice * ($fDiscountValue / 100));
            }
        }

        if ($fOldPrice == $fPrice) {
            $fOldPrice = 0;
        }

        $arPriceData['price'] = $fPrice;
        $arPriceData['old_price'] = $fOldPrice;

        array_set($arImportData, 'price_list.' . $obPriceType->id, $arPriceData);

        return $arImportData;
    }

    /**
     * Update discount sync
     *
     * @param Offer $obOffer
     */
    protected function updateDiscountSync($obOffer)
    {
        if (empty($obOffer) || !$obOffer instanceof Offer || empty($obOffer->discount_id)) {
            return;
        }

        $obDiscount = Discount::find($obOffer->discount_id);

        if (empty($obDiscount)) {
            return;
        }

        $obDiscount->offer()->sync($obOffer->id, false);
    }
}
