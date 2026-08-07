<?php namespace Logingrupa\StoreExtender\Classes\Event\Offer;

use Carbon\Carbon;
use Logingrupa\CustomXMLImportPricing\Classes\Helper\OfferExternalIdResolver;
use Lovata\DiscountsShopaholic\Models\Discount;
use Lovata\Shopaholic\Classes\Import\ImportOfferModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportOfferPriceFromXML;
use Lovata\Shopaholic\Models\Offer;

/**
 * Class ExtendOfferImportMetadata
 *
 * PASS 1 metadata listeners + discount field-list labels, kept 1:1 from the
 * legacy ExtendOfferImport (03-migration.md C.1). The PASS 2 price pipeline
 * moved to Logingrupa.CustomXMLImportPricing; this class owns everything that
 * STAYS: EXTEND_FIELD_LIST labels (priority 1000), the PASS 1 metadata fixes,
 * and the once-per-process expired-discount cleanup.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Offer
 */
class ExtendOfferImportMetadata
{
    /** @var bool Flag to run expired discount cleanup only once per import */
    protected $bExpiredDiscountsCleaned = false;

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
            $arImportData = OfferExternalIdResolver::splitCompositeId($arImportData);
            $arImportData = $this->fixQuantity($arImportData);
            $arImportData = $this->fixVariationText($arImportData);
            $arImportData = $this->fixWeight($arImportData);
            $arImportData = $this->fixHeight($arImportData);
            $arImportData = $this->fixLength($arImportData);
            $arImportData = $this->fixWidth($arImportData);

            // PASS 1 is metadata-only — every price-related write happens in PASS 2 via
            // the CustomXMLImportPricing pipeline. The actual strip of price/old_price/
            // price_list/discount_* from PASS 1's import data is done in
            // LoginGrupa\ExtendShopaholic\Classes\Import\ImportOfferModelFromXML::prepareImportDataBeforeSave()
            // — NOT here. Stripping in an EXTEND_IMPORT_DATA listener is a no-op because
            // upstream Lovata.Toolbox merges the listener return value with array_merge,
            // which cannot delete keys (only add/override). See the override's docblock
            // and .planning/debug/xml-import-s3-price-flicker.md "Continuation (2026-05-20)".

            return $arImportData;
        });
    }

    /**
     * Fix quantity value - remove spaces from thousnds
     * @param array $arImportData
     * @return mixed
     */
    protected function fixQuantity($arImportData)
    {
        $sQuantity = array_pull($arImportData, 'quantity');
        $arImportData['quantity'] = preg_replace("/ /", "", (string) ($sQuantity ?? ''));
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
        $sNewVariation = preg_match('/\((.*?)\)/', (string) ($sOldVariation ?? ''), $matches);
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
