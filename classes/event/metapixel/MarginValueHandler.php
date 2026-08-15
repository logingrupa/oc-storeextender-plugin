<?php namespace Logingrupa\StoreExtender\Classes\Event\Metapixel;

use Event;
use Illuminate\Support\Facades\DB;
use Logingrupa\CustomXMLImportPricing\Classes\Helper\PriceFactorResolver;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Price;

/**
 * Class MarginValueHandler
 *
 * Rewrites the Meta `value` of every funnel event (Purchase, ViewContent,
 * AddToCart, InitiateCheckout) from the buyer-facing sales figure to the
 * store margin. The rule (owner decision 2026-08-15, generalizing the v1
 * Purchase-only rule): the buyer's gross price carries VAT while the izpl
 * cost (price type 3) is stored WITHOUT VAT, so
 *
 *     margin = gross / (1 + tax% / 100) - izpl, per unit, times quantity.
 *
 * Purchase reads the order positions (their frozen prices and the
 * tax_percent stamped at order time); the other events read the payload the
 * Metapixel adapters built - `contents` rows when present, else the single
 * offer named by `content_ids` - resolving VAT through the product's tax
 * link with the global tax as fallback. The hook mutates the payload before
 * the EventLog freeze, so CAPI and the browser twin carry the same number.
 *
 * Only the top-level custom_data.value is touched: `contents` keeps real
 * per-unit sales prices (Meta matches them against the catalog feed).
 * event_id/event_time are never touched (Metapixel dedup contract).
 *
 * Fail-safe: any anomaly - unresolvable offer, missing izpl everywhere,
 * foreign content id shape - leaves the payload untouched, because a
 * full-revenue event beats a lost or fabricated one.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Metapixel
 */
class MarginValueHandler
{
    /**
     * The Metapixel payload-mutation hooks. String literals on purpose: the
     * listeners must register even when Logingrupa.Metapixel is absent
     * (they simply never fire) without a hard class dependency.
     */
    const HOOK_BEFORE_DISPATCH = 'metapixel.event.before_dispatch';

    /** Browser-twin counterpart: fires per content-carrying fbq() block */
    const HOOK_PIXEL_BEFORE_RENDER = 'metapixel.pixel.before_render';

    /** Events whose value becomes margin */
    const MARGIN_EVENT_LIST = ['Purchase', 'ViewContent', 'AddToCart', 'InitiateCheckout'];

    /** Content id shape the Metapixel adapters emit: SKU-<product>-<offer> */
    const CONTENT_ID_PATTERN = '/^SKU-\d+-(\d+)$/';

    /** @var float|null request memo of the global tax percent */
    protected $fGlobalTaxPercent = null;

    /** @var array<int, float> request memo: offer id -> tax percent */
    protected $arOfferTaxPercent = [];

    /**
     * Subscribe to the Metapixel dispatch pipeline.
     */
    public function subscribe()
    {
        Event::listen(self::HOOK_BEFORE_DISPATCH, function ($sEventName, &$arPayload, $obSubject) {
            if (!in_array($sEventName, self::MARGIN_EVENT_LIST, true)) {
                return null;
            }

            $arPayload = $this->applyMarginValue($sEventName, $arPayload, $obSubject);

            return null;
        });

        // Browser twin: same math on the bare custom_data an fbq() block is
        // about to render, so both channels carry one number per event_id
        Event::listen(self::HOOK_PIXEL_BEFORE_RENDER, function ($sEventName, &$arCustomData) {
            $arCustomData = $this->applyMarginToCustomData((string) $sEventName, (array) $arCustomData);
        });
    }

    /**
     * Replace custom_data.value with the VAT-stripped margin for the event.
     * @param string $sEventName
     * @param array  $arPayload Meta CAPI envelope {data: [{custom_data: {...}}]}
     * @param mixed  $obSubject
     * @return array
     */
    public function applyMarginValue(string $sEventName, array $arPayload, $obSubject): array
    {
        $fSalesValue = (float) array_get($arPayload, 'data.0.custom_data.value', 0.0);
        if ($fSalesValue <= 0) {
            return $arPayload;
        }

        if ($sEventName === 'Purchase' && $obSubject instanceof Order) {
            $fMarginValue = $this->marginFromItems($this->readOrderItems($obSubject));
        } else {
            $arCustomData = (array) array_get($arPayload, 'data.0.custom_data', []);
            $fMarginValue = $this->marginFromItems($this->readCustomDataItems($arCustomData, $fSalesValue));
        }

        if ($fMarginValue === null) {
            return $arPayload;
        }

        array_set($arPayload, 'data.0.custom_data.value', round(max(0.0, $fMarginValue), 2));

        return $arPayload;
    }

    /**
     * Browser-twin variant: mutate a bare custom_data array the way the CAPI
     * listener mutates the envelope. Purchase is skipped on purpose - its
     * browser twin renders the frozen EventLog payload, which already carries
     * the before_dispatch mutation; running the math again would deduct twice.
     * @param string $sEventName
     * @param array  $arCustomData
     * @return array
     */
    public function applyMarginToCustomData(string $sEventName, array $arCustomData): array
    {
        if ($sEventName === 'Purchase' || !in_array($sEventName, self::MARGIN_EVENT_LIST, true)) {
            return $arCustomData;
        }

        $fSalesValue = (float) array_get($arCustomData, 'value', 0.0);
        if ($fSalesValue <= 0) {
            return $arCustomData;
        }

        $fMarginValue = $this->marginFromItems($this->readCustomDataItems($arCustomData, $fSalesValue));
        if ($fMarginValue === null) {
            return $arCustomData;
        }

        $arCustomData['value'] = round(max(0.0, $fMarginValue), 2);

        return $arCustomData;
    }

    /**
     * The shared margin math over normalized items. Null when the item list
     * is empty or when no item had a resolvable izpl cost (cost fully
     * unknown: report revenue rather than a fake VAT-only "margin").
     *
     * @param array $arItemList [['gross' => float, 'tax' => float, 'cost' => float, 'quantity' => int], ...]
     * @return float|null
     */
    protected function marginFromItems(array $arItemList): ?float
    {
        if (empty($arItemList)) {
            return null;
        }

        $fMarginTotal = 0.0;
        $fCostTotal = 0.0;
        foreach ($arItemList as $arItem) {
            $fNetPrice = $arItem['gross'] / (1 + max(0.0, $arItem['tax']) / 100);
            $fMarginTotal += ($fNetPrice - $arItem['cost']) * $arItem['quantity'];
            $fCostTotal += $arItem['cost'] * $arItem['quantity'];
        }

        if ($fCostTotal <= 0) {
            return null;
        }

        return $fMarginTotal;
    }

    /**
     * Normalize order positions: frozen position price + the tax_percent
     * stamped at order time (product tax fallback for legacy rows).
     * @param Order $obOrder
     * @return array
     */
    protected function readOrderItems(Order $obOrder): array
    {
        $arItemList = [];
        $obPositionList = $obOrder->order_position;
        if (empty($obPositionList)) {
            return [];
        }

        foreach ($obPositionList as $obPosition) {
            $obOffer = $obPosition->item;
            if (!$obOffer instanceof Offer) {
                continue;
            }

            $fGross = (float) $obPosition->price_value;
            if ($fGross <= 0) {
                continue;
            }

            $mTaxPercent = $obPosition->tax_percent;
            $arItemList[] = [
                'gross'    => $fGross,
                'tax'      => $mTaxPercent !== null ? (float) $mTaxPercent : $this->taxPercentForOffer((int) $obOffer->id),
                'cost'     => $this->izplCost((int) $obOffer->id),
                'quantity' => max(1, (int) $obPosition->quantity),
            ];
        }

        return $arItemList;
    }

    /**
     * Normalize the custom_data the Metapixel adapters built: `contents` rows
     * when present (AddToCart, InitiateCheckout), else the single offer of
     * `content_ids` priced by custom_data.value (ViewContent). A single
     * unresolvable row abandons the whole event (fail-safe).
     * @param array $arCustomData
     * @param float $fSalesValue
     * @return array
     */
    protected function readCustomDataItems(array $arCustomData, float $fSalesValue): array
    {
        $arContentList = array_get($arCustomData, 'contents');
        if (is_array($arContentList) && !empty($arContentList)) {
            $arItemList = [];
            foreach ($arContentList as $arContent) {
                $iOfferId = $this->offerIdFromContentId((string) array_get($arContent, 'id', ''));
                $fGross = (float) array_get($arContent, 'item_price', 0.0);
                if ($iOfferId === 0 || $fGross <= 0) {
                    return [];
                }

                $arItemList[] = [
                    'gross'    => $fGross,
                    'tax'      => $this->taxPercentForOffer($iOfferId),
                    'cost'     => $this->izplCost($iOfferId),
                    'quantity' => max(1, (int) array_get($arContent, 'quantity', 1)),
                ];
            }

            return $arItemList;
        }

        $arContentIdList = array_get($arCustomData, 'content_ids');
        if (is_array($arContentIdList) && count($arContentIdList) === 1) {
            $iOfferId = $this->offerIdFromContentId((string) reset($arContentIdList));
            if ($iOfferId === 0) {
                return [];
            }

            return [[
                'gross'    => $fSalesValue,
                'tax'      => $this->taxPercentForOffer($iOfferId),
                'cost'     => $this->izplCost($iOfferId),
                'quantity' => 1,
            ]];
        }

        return [];
    }

    /**
     * Offer id from the adapter content id shape, 0 when foreign.
     * @param string $sContentId
     * @return int
     */
    protected function offerIdFromContentId(string $sContentId): int
    {
        if (preg_match(self::CONTENT_ID_PATTERN, $sContentId, $arMatch) !== 1) {
            return 0;
        }

        return (int) $arMatch[1];
    }

    /**
     * VAT percent for an offer: its product's linked tax, else the global
     * active tax. Memoized per request.
     * @param int $iOfferId
     * @return float
     */
    protected function taxPercentForOffer(int $iOfferId): float
    {
        if (array_key_exists($iOfferId, $this->arOfferTaxPercent)) {
            return $this->arOfferTaxPercent[$iOfferId];
        }

        $mProductId = Offer::where('id', $iOfferId)->value('product_id');
        $mPercent = null;
        if (!empty($mProductId)) {
            $mPercent = DB::table('lovata_shopaholic_taxes')
                ->join('lovata_shopaholic_tax_product_link', 'lovata_shopaholic_taxes.id', '=', 'lovata_shopaholic_tax_product_link.tax_id')
                ->where('lovata_shopaholic_tax_product_link.product_id', $mProductId)
                ->where('lovata_shopaholic_taxes.active', 1)
                ->value('percent');
        }

        return $this->arOfferTaxPercent[$iOfferId] = $mPercent !== null
            ? (float) $mPercent
            : $this->globalTaxPercent();
    }

    /**
     * The global active tax percent (0.0 when none configured - gross
     * prices are then treated as net).
     * @return float
     */
    protected function globalTaxPercent(): float
    {
        if ($this->fGlobalTaxPercent !== null) {
            return $this->fGlobalTaxPercent;
        }

        $mPercent = DB::table('lovata_shopaholic_taxes')
            ->where('active', 1)
            ->where('is_global', 1)
            ->value('percent');

        return $this->fGlobalTaxPercent = $mPercent !== null ? (float) $mPercent : 0.0;
    }

    /**
     * The izpl (price type 3, cost) price of an offer, 0.0 when absent
     * (cost unknown - v1 parity).
     * @param int $iOfferId
     * @return float
     */
    protected function izplCost(int $iOfferId): float
    {
        $obPriceRow = Price::getByItemType(Offer::class)
            ->getByItemID($iOfferId)
            ->getByPriceType(PriceFactorResolver::IZPL_PRICE_TYPE_ID)
            ->first();
        if (empty($obPriceRow)) {
            return 0.0;
        }

        return (float) $obPriceRow->price_value;
    }
}
