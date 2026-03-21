<?php namespace Logingrupa\StoreExtender\Classes\Event\Offer;

use Lovata\Toolbox\Classes\Helper\PriceHelper;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Tax;
use Lovata\Shopaholic\Models\XmlImportSettings;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Helper\TaxHelper;
use Lovata\Shopaholic\Classes\Import\ImportOfferPriceFromXML;
use Lovata\Shopaholic\Classes\Import\ImportOfferModelFromXML;
use Lovata\DiscountsShopaholic\Models\Discount;

use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
use Carbon\Carbon;
use Pheanstalk\Exception;

/**
 * Class ExtendOfferImport
 * @package Logingrupa\StoreExtender\Classes\Event\Offer
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendOfferImport
{
    /** @var bool Flag to run expired discount cleanup only once per import */
    protected $bExpiredDiscountsCleaned = false;

    /** @var float|null Cached VAT rate multiplier from settings */
    protected $fVatRateMultiplier = null;

    /** @var float|null Cached conversion rate from settings */
    protected $fConversionRate = null;

    /** @var bool|null Cached VAT recalculation enabled flag */
    protected $bVatRecalculateEnabled = null;

    /** @var array|null Cached VAT mapping from settings */
    protected $arVatMapping = null;

    /**
     * Get VAT rate multiplier from import settings (cached per import run)
     * @return float
     */
    protected function getVatRateMultiplier()
    {
        if ($this->fVatRateMultiplier === null) {
            $fVatPercentage = (float) XmlImportSettings::getValue('import_vat_rate', 21);
            $this->fVatRateMultiplier = 1 + ($fVatPercentage / 100);
        }

        return $this->fVatRateMultiplier;
    }

    /**
     * Get conversion rate from import settings (cached per import run)
     * Returns 1.0 if conversion is disabled
     * @return float
     */
    protected function getConversionRate()
    {
        if ($this->fConversionRate === null) {
            $bEnabled = (bool) XmlImportSettings::getValue('import_currency_convert_enable', false);
            $this->fConversionRate = $bEnabled
                ? (float) XmlImportSettings::getValue('import_conversion_rate', 1)
                : 1.0;
        }

        return $this->fConversionRate;
    }

    /**
     * Check if VAT recalculation is enabled (cached per import run)
     * @return bool
     */
    protected function isVatRecalculateEnabled()
    {
        if ($this->bVatRecalculateEnabled === null) {
            $this->bVatRecalculateEnabled = (bool) XmlImportSettings::getValue('import_vat_recalculate_enable', false);
        }

        return $this->bVatRecalculateEnabled;
    }

    /**
     * Get VAT mapping from import settings (cached per import run)
     * @return array
     */
    protected function getVatMapping()
    {
        if ($this->arVatMapping === null) {
            $this->arVatMapping = (array) XmlImportSettings::getValue('import_vat_mapping', []);
        }

        return $this->arVatMapping;
    }

    /**
     * Find source and target VAT rates for a product based on its Tax links
     * Returns [sourceRate, targetRate] or null if recalculation is disabled
     * @param int $iProductId
     * @return array|null
     */
    protected function findVatRatesForProduct($iProductId)
    {
        $arVatMapping = $this->getVatMapping();
        if (empty($arVatMapping)) {
            return null;
        }

        // Get non-global taxes linked to this product
        $obProductTaxList = Tax::active()
            ->where('is_global', false)
            ->whereHas('product', function ($obQuery) use ($iProductId) {
                $obQuery->where('lovata_shopaholic_tax_product_link.product_id', $iProductId);
            })
            ->get();

        // If product has a specific tax link, find matching mapping row via reverse lookup
        if ($obProductTaxList->isNotEmpty()) {
            foreach ($obProductTaxList as $obTax) {
                foreach ($arVatMapping as $arMappingRow) {
                    $iTargetTaxId = (int) array_get($arMappingRow, 'target_tax_id', 0);
                    if ($iTargetTaxId == $obTax->id) {
                        $fSourceRate = (float) array_get($arMappingRow, 'source_vat_rate', 0);
                        $fTargetRate = (float) $obTax->percent;

                        return [$fSourceRate, $fTargetRate];
                    }
                }
            }
        }

        // No specific tax link — use the first mapping row as default
        $arDefaultMapping = array_first($arVatMapping);
        if (empty($arDefaultMapping)) {
            return null;
        }

        $fSourceRate = (float) array_get($arDefaultMapping, 'source_vat_rate', 0);
        $iTargetTaxId = (int) array_get($arDefaultMapping, 'target_tax_id', 0);
        $obTargetTax = Tax::find($iTargetTaxId);

        if (empty($obTargetTax)) {
            return null;
        }

        return [$fSourceRate, (float) $obTargetTax->percent];
    }

    /**
     * Apply currency conversion to all prices in import data
     * Runs as the LAST step — after all calculations in the source currency
     * @param array $arImportData
     * @return array
     */
    protected function applyCurrencyConversion($arImportData)
    {
        $fConversionRate = $this->getConversionRate();

        if ($fConversionRate == 1.0) {
            return $arImportData;
        }

        // Convert main price
        $fPrice = PriceHelper::toFloat(array_get($arImportData, 'price'));
        if (!empty($fPrice)) {
            $arImportData['price'] = PriceHelper::round($fPrice * $fConversionRate);
        }

        // Convert main old_price
        $fOldPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price'));
        if (!empty($fOldPrice)) {
            $arImportData['old_price'] = PriceHelper::round($fOldPrice * $fConversionRate);
        }

        // Convert all price_list entries
        $arPriceList = array_get($arImportData, 'price_list', []);
        foreach ($arPriceList as $iPriceTypeId => $arPriceData) {
            $fTypePrice = PriceHelper::toFloat(array_get($arPriceData, 'price'));
            if (!empty($fTypePrice)) {
                array_set($arImportData, 'price_list.' . $iPriceTypeId . '.price', PriceHelper::round($fTypePrice * $fConversionRate));
            }

            $fTypeOldPrice = PriceHelper::toFloat(array_get($arPriceData, 'old_price'));
            if (!empty($fTypeOldPrice)) {
                array_set($arImportData, 'price_list.' . $iPriceTypeId . '.old_price', PriceHelper::round($fTypeOldPrice * $fConversionRate));
            }
        }

        return $arImportData;
    }

    /**
     * Apply currency conversion to price_list entries ONLY (not main price)
     * Used in EVENT_BEFORE_IMPORT where main price will be overwritten by the price import later
     * @param array $arImportData
     * @return array
     */
    protected function convertPriceListOnly($arImportData)
    {
        $fConversionRate = $this->getConversionRate();

        if ($fConversionRate == 1.0) {
            return $arImportData;
        }

        $arPriceList = array_get($arImportData, 'price_list', []);
        foreach ($arPriceList as $iPriceTypeId => $arPriceData) {
            $fTypePrice = PriceHelper::toFloat(array_get($arPriceData, 'price'));
            if (!empty($fTypePrice)) {
                array_set($arImportData, 'price_list.' . $iPriceTypeId . '.price', PriceHelper::round($fTypePrice * $fConversionRate));
            }

            $fTypeOldPrice = PriceHelper::toFloat(array_get($arPriceData, 'old_price'));
            if (!empty($fTypeOldPrice)) {
                array_set($arImportData, 'price_list.' . $iPriceTypeId . '.old_price', PriceHelper::round($fTypeOldPrice * $fConversionRate));
            }
        }

        return $arImportData;
    }

    /**
     * Recalculate main price VAT: strip source VAT and apply target tax rate per product
     * @param array $arImportData
     * @return array
     */
    protected function recalculateMainPriceVat($arImportData)
    {
        if (!$this->isVatRecalculateEnabled()) {
            return $arImportData;
        }

        // Find the offer to get its product
        $sExternalId = array_get($arImportData, 'external_id');
        if (empty($sExternalId)) {
            return $arImportData;
        }

        $obOffer = Offer::withTrashed()->where('external_id', $sExternalId)->first();
        if (empty($obOffer) || empty($obOffer->product_id)) {
            return $arImportData;
        }

        $arVatRates = $this->findVatRatesForProduct($obOffer->product_id);
        if (empty($arVatRates)) {
            return $arImportData;
        }

        list($fSourceRate, $fTargetRate) = $arVatRates;

        if ($fSourceRate <= 0) {
            return $arImportData;
        }

        $obTaxHelper = TaxHelper::instance();

        // Recalculate main price
        $fPrice = PriceHelper::toFloat(array_get($arImportData, 'price'));
        if (!empty($fPrice)) {
            $fPriceWithoutVat = $obTaxHelper->calculatePriceWithoutTax($fPrice, $fSourceRate);
            $arImportData['price'] = $obTaxHelper->calculatePriceWithTax($fPriceWithoutVat, $fTargetRate);
        }

        // Recalculate main old_price
        $fOldPrice = PriceHelper::toFloat(array_get($arImportData, 'old_price'));
        if (!empty($fOldPrice)) {
            $fOldPriceWithoutVat = $obTaxHelper->calculatePriceWithoutTax($fOldPrice, $fSourceRate);
            $arImportData['old_price'] = $obTaxHelper->calculatePriceWithTax($fOldPriceWithoutVat, $fTargetRate);
        }

        return $arImportData;
    }

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
            $this->cleanupExpiredDiscounts();
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
            $arImportData = $this->recalculateMainPriceVat($arImportData);
            $arImportData = $this->applyOfferDiscount($arImportData);
            $arImportData = $this->applyVatToSalonaPriceOffers($arImportData);
            $arImportData = $this->applyOldPriceToIzplatitajuPriceOffers($arImportData);
            $arImportData = $this->applyOldPriceAndApplyVatToVairumPriceOffers($arImportData);
            $arImportData = $this->applyCurrencyConversion($arImportData);
            return $arImportData;
        });

        $obEvent->listen(ImportOfferModelFromXML::EVENT_BEFORE_IMPORT, function ($sModelClass, $arImportData) {
            if ($sModelClass != Offer::class) {
                return null;
            }

            $arImportData = $this->calculatePrices($arImportData);
            $arImportData = $this->convertPriceListOnly($arImportData);

            return $arImportData;
        });

        $obEvent->listen(ImportOfferModelFromXML::EVENT_AFTER_IMPORT, function ($obOffer, $arImportData) {
            $this->updateDiscountSync($obOffer, $arImportData);
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

        if (is_array($sExternalID)) {
            $sExternalID = collect($sExternalID)->first(function ($sValue) {
                return is_string($sValue) && str_contains($sValue, '#');
            }) ?? reset($sExternalID);
        }

        $arPartList = explode('#', (string) $sExternalID);
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
            $obDiscountDateEnd = Carbon::parse($sDiscountDateEnd)->endOfMonth();
        } catch (Exception $obException) {
            $obDiscountDateEnd = null;
        }

        if (empty($obDiscountDateEnd) || empty($fDiscountPercentage)) {
            return $arImportData;
        }

        // Find existing discount by percentage and matching month/year of end date
        $obDiscount = Discount::where('discount_value', $fDiscountPercentage)
            ->whereYear('date_end', $obDiscountDateEnd->year)
            ->whereMonth('date_end', $obDiscountDateEnd->month)
            ->first();

        try {
            if (empty($obDiscount)) {
                $obDiscount = Discount::create([
                    'active' => true,
                    'name' => $sDiscountName,
                    'discount_value' => $fDiscountPercentage,
                    'discount_type' => Discount::PERCENT_TYPE,
                    'date_begin' => Carbon::now()->startOfMonth(),
                    'date_end' => $obDiscountDateEnd,
                ]);
            } else {
                $obDiscount->update([
                    'name' => $sDiscountName,
                    'date_end' => $obDiscountDateEnd,
                ]);
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

        $fSalonaPricePlusVAT = $fSalonaPrice * $this->getVatRateMultiplier(); //8.00
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
        $fVairumPricePlusVAT = $fVairumPrice * $this->getVatRateMultiplier(); //8.00
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

        // Recalculate VAT on the base price before applying discounts
        // so regular/authorized price types use the correct VAT-adjusted price
        $arImportData = $this->recalculateMainPriceVat($arImportData);

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
    /**
     * Update discount sync - save discount_id on offer and link offer to discount
     *
     * @param Offer $obOffer
     * @param array $arImportData
     */
    protected function updateDiscountSync($obOffer, $arImportData = [])
    {
        if (empty($obOffer) || !$obOffer instanceof Offer) {
            return;
        }

        $iDiscountId = array_get($arImportData, 'discount_id', $obOffer->discount_id);

        if (empty($iDiscountId)) {
            return;
        }

        // Save discount_id directly on the offer (not in $fillable, so use query)
        Offer::where('id', $obOffer->id)->update(['discount_id' => $iDiscountId]);

        $obDiscount = Discount::find($iDiscountId);
        if (empty($obDiscount)) {
            return;
        }

        $obDiscount->offer()->syncWithoutDetaching([$obOffer->id]);
    }

    /**
     * Clean up expired discounts: detach offers, clear discount_id, delete discount
     * Only runs once per import (skips discounts with code like 'regular'/'authorized')
     */
    protected function cleanupExpiredDiscounts()
    {
        if ($this->bExpiredDiscountsCleaned) {
            return;
        }

        $this->bExpiredDiscountsCleaned = true;

        $obExpiredDiscounts = Discount::whereNotNull('date_end')
            ->where('date_end', '<', Carbon::now())
            ->where(function ($obQuery) {
                $obQuery->whereNull('code')->orWhere('code', '');
            })
            ->get();

        if ($obExpiredDiscounts->isEmpty()) {
            return;
        }

        $arExpiredIds = $obExpiredDiscounts->pluck('id')->toArray();

        // Clear discount_id on offers referencing expired discounts
        Offer::whereIn('discount_id', $arExpiredIds)->update([
            'discount_id' => null,
            'discount_value' => null,
            'discount_type' => null,
        ]);

        // Detach offers and delete expired discounts
        foreach ($obExpiredDiscounts as $obDiscount) {
            $obDiscount->offer()->detach();
            $obDiscount->forceDelete();
        }
    }
}
