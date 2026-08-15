<?php namespace Logingrupa\StoreExtender\Classes\Event\Metapixel;

use Event;
use Illuminate\Support\Facades\Log;
use Logingrupa\CustomXMLImportPricing\Classes\Helper\PriceFactorResolver;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Price;

/**
 * Class PurchaseMarginValueHandler
 *
 * Rewrites the Meta Purchase `value` from the full sales total to the store's
 * margin: order total minus the summed izpl (price type 3, cost price) of
 * every position. This is the business rule the v1 site enforced through a
 * hand-edited CartProcessor (getPixelPurnchesData); v2 restores it through
 * Metapixel's official before_dispatch payload-mutation hook, so the mutated
 * value lands in the frozen EventLog payload and the browser Purchase twin
 * (eventPixel) renders the exact same number as CAPI.
 *
 * v1 bug fixed on purpose: the cost price is multiplied by the position
 * quantity (v1 subtracted one unit of izpl per position regardless of qty).
 *
 * Only the top-level custom_data.value is touched: `contents` keeps real
 * per-unit sales prices (Meta matches them against the catalog feed).
 * event_id/event_time are never touched (Metapixel dedup contract).
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Metapixel
 */
class PurchaseMarginValueHandler
{
    /**
     * The Metapixel payload-mutation hook. String literal on purpose: the
     * listener must register even when Logingrupa.Metapixel is absent
     * (it simply never fires) without a hard class dependency.
     */
    const HOOK_BEFORE_DISPATCH = 'metapixel.event.before_dispatch';

    /**
     * Subscribe to the Metapixel dispatch pipeline.
     */
    public function subscribe()
    {
        Event::listen(self::HOOK_BEFORE_DISPATCH, function ($sEventName, &$arPayload, $obSubject) {
            if ($sEventName !== 'Purchase' || !$obSubject instanceof Order) {
                return null;
            }

            $arPayload = $this->applyMarginValue($arPayload, $obSubject);

            return null;
        });
    }

    /**
     * Replace custom_data.value with (value - summed izpl cost) for the order.
     * Fail-safe: any anomaly leaves the payload untouched - a full-revenue
     * Purchase beats a lost one.
     * @param array $arPayload Meta CAPI envelope {data: [{custom_data: {...}}]}
     * @param Order $obOrder
     * @return array
     */
    public function applyMarginValue(array $arPayload, Order $obOrder): array
    {
        $fSalesValue = (float) array_get($arPayload, 'data.0.custom_data.value', 0.0);
        if ($fSalesValue <= 0) {
            return $arPayload;
        }

        $fCostTotal = $this->sumIzplCost($obOrder);
        if ($fCostTotal <= 0) {
            // no cost prices resolvable: report revenue rather than a fake margin
            return $arPayload;
        }

        $fMarginValue = round(max(0.0, $fSalesValue - $fCostTotal), 2);
        array_set($arPayload, 'data.0.custom_data.value', $fMarginValue);

        return $arPayload;
    }

    /**
     * Sum izpl (cost) prices of the order's offer positions, each multiplied
     * by its quantity. A position whose offer has no izpl price contributes
     * zero (cost unknown - treated as pure margin, same as v1).
     * @param Order $obOrder
     * @return float
     */
    protected function sumIzplCost(Order $obOrder): float
    {
        $fCostTotal = 0.0;
        $obPositionList = $obOrder->order_position;
        if (empty($obPositionList)) {
            return 0.0;
        }

        foreach ($obPositionList as $obPosition) {
            $obOffer = $obPosition->item;
            if (!$obOffer instanceof Offer) {
                continue;
            }

            $obPriceRow = Price::getByItemType(Offer::class)
                ->getByItemID($obOffer->id)
                ->getByPriceType(PriceFactorResolver::IZPL_PRICE_TYPE_ID)
                ->first();
            if (empty($obPriceRow)) {
                continue;
            }

            $fCostTotal += (float) $obPriceRow->price_value * max(1, (int) $obPosition->quantity);
        }

        return $fCostTotal;
    }
}
