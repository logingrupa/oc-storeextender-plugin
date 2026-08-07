<?php namespace Logingrupa\StoreExtender\Classes\Event\Offer;

use Carbon\Carbon;
use Logingrupa\CustomXMLImportPricing\Classes\Event\Offer\OfferPriceImportSubscriber;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
use Lovata\DiscountsShopaholic\Models\Discount;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Import\ImportOfferPriceFromXML;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Toolbox\Classes\Helper\PriceHelper;
use Pheanstalk\Exception;

/**
 * Class OfferDiscountImportSubscriber
 *
 * Discount steps of the offer-price import, kept in storeextender and
 * subscribed to Logingrupa.CustomXMLImportPricing's pipeline hooks
 * (03-migration.md C.2). Bodies are 1:1 with the legacy ExtendOfferImport:
 * applyOfferDiscount (hook after_vat), applyAuthorizedDiscount +
 * applyRegularDiscount (hook after_factor, authorized-before-regular inside
 * ONE listener so the old internal order never depends on registration
 * order), and updateDiscountSync (EVENT_AFTER_IMPORT).
 *
 * The Pheanstalk\Exception import is kept deliberately - it is behavior:
 * those catches effectively never match, so real exceptions propagate
 * (Phase-4+ candidate fix, never a Phase-2 change).
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Offer
 */
class OfferDiscountImportSubscriber
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(
            OfferPriceImportSubscriber::HOOK_AFTER_VAT,
            fn (array $arImportData, ?int $iProductId) => $this->applyOfferDiscount($arImportData)
        );

        $obEvent->listen(
            OfferPriceImportSubscriber::HOOK_AFTER_FACTOR,
            function (array $arImportData, ?int $iProductId) {
                if (empty($iProductId)) {
                    return null; // was: the pipeline's !empty() guard around steps 7-8
                }

                $arImportData = $this->applyAuthorizedDiscount($iProductId, $arImportData);

                return $this->applyRegularDiscount($iProductId, $arImportData);
            }
        );

        $obEvent->listen(
            ImportOfferPriceFromXML::EVENT_AFTER_IMPORT,
            fn ($obOffer, $arImportData) => $this->updateDiscountSync($obOffer, $arImportData)
        );
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
}
