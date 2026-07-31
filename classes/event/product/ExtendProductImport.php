<?php namespace Logingrupa\StoreExtender\Classes\Event\Product;

use Lovata\Shopaholic\Classes\Import\ImportProductModelFromXML;

/**
 * Class ExtendProductImport
 * @package Logingrupa\StoreExtender\Classes\Event\Product
 */
class ExtendProductImport
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen(ImportProductModelFromXML::EXTEND_FIELD_LIST, function ($arFieldList) {
            array_set($arFieldList, 'manufacturer', trans('logingrupa.storeextender::lang.field.manufacturer'));
            array_set($arFieldList, 'ingredients', trans('logingrupa.storeextender::lang.field.ingredients'));
            array_set($arFieldList, 'warning', trans('logingrupa.storeextender::lang.field.warning'));

            return $arFieldList;
        }, 1000);

        $obEvent->listen(ImportProductModelFromXML::EXTEND_IMPORT_DATA, function ($arImportData) {
            $sManufacturer = array_get($arImportData, 'manufacturer');
            $sIngredients = array_get($arImportData, 'ingredients');
            $sWarning = array_get($arImportData, 'warning');

            if (is_string($sManufacturer)) {
                $arImportData['manufacturer'] = trim($sManufacturer);
            }
            if (is_string($sIngredients)) {
                $arImportData['ingredients'] = trim($sIngredients);
            }
            if (is_string($sWarning)) {
                $arImportData['warning'] = trim($sWarning);
            }

            return $arImportData;
        });
    }
}