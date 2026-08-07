<?php

use Logingrupa\StoreExtender\Classes\Event\Offer\ExtendOfferImport;
use Lovata\Shopaholic\Classes\Import\ImportOfferPriceFromXML;

/**
 * Dispatches import data through the production listeners on a PRIVATE dispatcher.
 *
 * A fresh Illuminate dispatcher isolates the subscriber under test from every other
 * plugin's listeners while exercising the exact production entry point (the registered
 * closure including fixExternalID + array_forget('product_id')), not a reflection call.
 *
 * A fresh ExtendOfferImport per dispatch defeats the instance-level settings memoization
 * (fVatRateMultiplier / fConversionRate / bVatRecalculateEnabled / arVatMapping) -
 * settings MUST be applied via PricingSettingsFixture BEFORE calling any dispatch method.
 */
class PricingPipelineHarness
{
    /**
     * Dispatch through ImportOfferPriceFromXML::EXTEND_IMPORT_DATA and return the sole
     * listener's result (upstream AbstractImportModelFromXML array_merges listener
     * results; with a single listener result[0] IS the merged result - self-checked
     * via dispatchExtendImportDataRaw()).
     *
     * @param array $arImportData
     * @return array
     */
    public static function dispatchExtendImportData(array $arImportData): array
    {
        $arResultList = self::dispatchExtendImportDataRaw($arImportData);

        return $arResultList[0];
    }

    /**
     * Same dispatch, returning the FULL listener result list (harness self-check).
     *
     * @param array $arImportData
     * @return array
     */
    public static function dispatchExtendImportDataRaw(array $arImportData): array
    {
        $obDispatcher = new \Illuminate\Events\Dispatcher();
        (new ExtendOfferImport())->subscribe($obDispatcher);

        return $obDispatcher->dispatch(
            ImportOfferPriceFromXML::EXTEND_IMPORT_DATA,
            [$arImportData, null]
        );
    }

    /**
     * Dispatch through ImportOfferPriceFromXML::EVENT_BEFORE_IMPORT ('model.beforeImport',
     * shared constant from Lovata\Toolbox\Classes\Helper\AbstractImportModel), fired in
     * production with [Offer::class, $arImportData]. The listener returns null for any
     * non-Offer model class.
     *
     * @param string $sModelClass
     * @param array  $arImportData
     * @return array|null
     */
    public static function dispatchBeforeImport(string $sModelClass, array $arImportData): ?array
    {
        $obDispatcher = new \Illuminate\Events\Dispatcher();
        (new ExtendOfferImport())->subscribe($obDispatcher);

        $arResultList = $obDispatcher->dispatch(
            ImportOfferPriceFromXML::EVENT_BEFORE_IMPORT,
            [$sModelClass, $arImportData]
        );

        return $arResultList[0];
    }
}
