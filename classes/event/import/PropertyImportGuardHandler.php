<?php namespace Logingrupa\StoreExtender\Classes\Event\Import;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Lovata\Toolbox\Classes\Helper\AbstractImportModel;

/**
 * Class PropertyImportGuardHandler
 *
 * Makes product/offer properties import-proof: the 1C XML import must never
 * write OR delete property value links again (single writer per domain -
 * external syncs own their properties, e.g. the Color Family sync).
 *
 * Why overriding (not unsetting) is the only working mechanism:
 * AbstractImportModel::fireBeforeImportEvent() merges every listener's
 * returned array key-by-key onto the import data, so a later listener can
 * only OVERWRITE the 'property' key that PropertiesShopaholic's
 * preparePropertyArrayToSave() unconditionally injects (it sets
 * $arImportData['property'] even when the XML carried no property nodes).
 * Any 'property' key reaching the model - including [] or null - invokes
 * setPropertyAttribute, whose CommonPropertyHelper::removeOldValue() DELETES
 * every existing link it did not just re-process. Clearing the import
 * settings paths alone therefore does NOT protect the links.
 *
 * The override value is the element's CURRENT property array (the exact
 * round-trip of its links): processValueList re-processes every existing
 * link in collection order, value_ids compare equal so nothing is written,
 * every link lands in arProcessedLinkList, and removeOldValue deletes
 * nothing. Whatever the XML contributed is discarded.
 *
 * Priority 0 (the dispatcher default) runs AFTER PropertiesShopaholic's 900
 * listeners - October sorts listeners by priority descending - so this
 * listener's return merges last and its 'property' key wins. The ordering is
 * pinned by an integration test, not assumed.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Import
 */
class PropertyImportGuardHandler
{
    /**
     * Subscribe to the shared model.beforeImport chain for BOTH imports.
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(AbstractImportModel::EVENT_BEFORE_IMPORT, function ($sModelClass, $arImportData) {
            if ($sModelClass != Offer::class && $sModelClass != Product::class) {
                return null;
            }

            return $this->neutralizePropertyImport($sModelClass, $arImportData);
        });
    }

    /**
     * Build the partial import-data override that freezes the element's
     * property links. New elements (unknown external_id) get [], which the
     * property mutator ignores while the model has no id yet.
     * @param string $sModelClass Offer::class or Product::class
     * @param array  $arImportData
     * @return array{property: array}
     */
    public function neutralizePropertyImport($sModelClass, $arImportData): array
    {
        $sExternalID = array_get((array) $arImportData, 'external_id');
        if (empty($sExternalID)) {
            return ['property' => []];
        }

        $obElement = $sModelClass::getByExternalID($sExternalID)->first();
        if (empty($obElement)) {
            return ['property' => []];
        }

        return ['property' => (array) $obElement->property];
    }
}
