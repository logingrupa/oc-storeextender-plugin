<?php namespace Logingrupa\StoreExtender\Classes\Shipping;

use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\InterfacePromoMechanism;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\ItemPriceContainer;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\PositionTotalPriceGreater\AbstractPositionTotalPriceGreaterDiscount;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\WithoutCondition\AbstractWithoutConditionDiscount;
use Lovata\Toolbox\Classes\Helper\PriceHelper;

/**
 * Shipping price per subtotal tier, per active shipping type. No discount
 * arithmetic lives here: every tier price is the real mechanism's
 * calculateItemDiscount() replayed against a ShippingLadderProbe, so the
 * threshold check and the tax basis are the ones checkout uses.
 *
 * Only money-threshold mechanisms take part (PositionTotalPriceGreater and
 * WithoutCondition). Quantity and line-count mechanisms are dropped with a
 * warning: their condition is not a subtotal, so a ladder built from them
 * would print a price checkout may not charge.
 *
 * Reads only id, name, tax_percent and getFullPriceValue() off a shipping
 * type. Any other ShippingTypeItem property falls through to price_data,
 * which builds the live cart through CartProcessor.
 */
class ShippingLadder
{
    /**
     * @param iterable $obShippingTypeList active shipping types (ShippingTypeItem or stand-ins)
     * @param array    $arMechanismsByType ShippingMechanismCollector::collect() output
     * @return array [['id' => int, 'name' => string, 'base' => float, 'tiers' => [['from' => float, 'price' => float], ...]], ...]
     */
    public static function make(iterable $obShippingTypeList, array $arMechanismsByType): array
    {
        $arLadder = [];
        $arWarnedClassList = [];
        foreach ($obShippingTypeList as $obShippingType) {
            $arMechanismList = self::moneyMechanisms($arMechanismsByType[(int) $obShippingType->id] ?? [], $arWarnedClassList);
            $fBase = PriceHelper::round((float) $obShippingType->getFullPriceValue());

            $arLadder[] = [
                'id'    => (int) $obShippingType->id,
                'name'  => (string) $obShippingType->name,
                'base'  => $fBase,
                'tiers' => self::tiers($obShippingType, $fBase, $arMechanismList),
            ];
        }

        return $arLadder;
    }

    /**
     * Lowest subtotal at which a paid shipping type becomes free, null when
     * no type does. A type with a 0.00 base (local pickup) is not free delivery.
     * @param array $arLadder make() output
     * @return float|null
     */
    public static function freeShippingTarget(array $arLadder): ?float
    {
        $fTarget = null;
        foreach ($arLadder as $arType) {
            if ($arType['base'] <= 0.0) {
                continue;
            }
            foreach ($arType['tiers'] as $arTier) {
                if ($arTier['price'] > 0.0) {
                    continue;
                }
                $fTarget = $fTarget === null ? $arTier['from'] : min($fTarget, $arTier['from']);
            }
        }

        return $fTarget;
    }

    /**
     * The ladder regrouped for reading, cheapest first. A group is one
     * threshold interval ("under 60", "over 60") with every tiered type's
     * price inside it, rows ascending by price, or one single-tier type on
     * its own (from and to null). Groups order by their cheapest row; on a
     * tie the single-tier group comes first, then the lower interval.
     * @param array $arLadder make() output
     * @return array [['from' => float|null, 'to' => float|null, 'rows' => [['name' => string, 'price' => float], ...]], ...]
     */
    public static function bands(array $arLadder): array
    {
        $arTieredList = [];
        $arGroupList = [];
        foreach ($arLadder as $arType) {
            if (count($arType['tiers']) > 1) {
                $arTieredList[] = $arType;
                continue;
            }
            $arGroupList[] = ['from' => null, 'to' => null, 'rows' => [['name' => $arType['name'], 'price' => $arType['tiers'][0]['price']]]];
        }

        $arEdgeList = self::thresholdEdges($arTieredList);
        foreach ($arEdgeList as $iIndex => $fFrom) {
            $arGroupList[] = [
                'from' => $fFrom,
                'to'   => $arEdgeList[$iIndex + 1] ?? null,
                'rows' => self::bandRows($arTieredList, $fFrom),
            ];
        }

        usort($arGroupList, [self::class, 'compareGroups']);

        return $arGroupList;
    }

    /**
     * 0.0 plus every tier threshold, ascending; empty without tiered types.
     * @param array $arTieredList
     * @return float[]
     */
    protected static function thresholdEdges(array $arTieredList): array
    {
        if (empty($arTieredList)) {
            return [];
        }

        $arEdgeList = [0.0];
        foreach ($arTieredList as $arType) {
            foreach ($arType['tiers'] as $arTier) {
                $arEdgeList[] = $arTier['from'];
            }
        }
        $arEdgeList = array_values(array_unique($arEdgeList, SORT_NUMERIC));
        sort($arEdgeList, SORT_NUMERIC);

        return $arEdgeList;
    }

    /**
     * Cheapest row first; a single-tier group before an interval on a tie,
     * a lower interval before a higher one.
     * @param array $arGroupA
     * @param array $arGroupB
     * @return int
     */
    protected static function compareGroups(array $arGroupA, array $arGroupB): int
    {
        $iByPrice = $arGroupA['rows'][0]['price'] <=> $arGroupB['rows'][0]['price'];
        if ($iByPrice !== 0) {
            return $iByPrice;
        }

        return ($arGroupA['from'] ?? -1.0) <=> ($arGroupB['from'] ?? -1.0);
    }

    /**
     * Each tiered type's price at a subtotal (the last tier whose threshold
     * the subtotal reaches), ascending by price.
     * @param array $arTieredList
     * @param float $fSubtotal
     * @return array
     */
    protected static function bandRows(array $arTieredList, float $fSubtotal): array
    {
        $arRowList = [];
        foreach ($arTieredList as $arType) {
            $fPrice = $arType['tiers'][0]['price'];
            foreach ($arType['tiers'] as $arTier) {
                if ($arTier['from'] <= $fSubtotal) {
                    $fPrice = $arTier['price'];
                }
            }
            $arRowList[] = ['name' => $arType['name'], 'price' => $fPrice];
        }
        usort($arRowList, function (array $arRowA, array $arRowB) {
            return $arRowA['price'] <=> $arRowB['price'];
        });

        return $arRowList;
    }

    /**
     * Keep the money-threshold mechanisms, warn once per excluded class.
     * @param array $arMechanismList
     * @param array $arWarnedClassList
     * @return InterfacePromoMechanism[]
     */
    protected static function moneyMechanisms(array $arMechanismList, array &$arWarnedClassList): array
    {
        $arKept = [];
        foreach ($arMechanismList as $obMechanism) {
            if ($obMechanism instanceof AbstractPositionTotalPriceGreaterDiscount || $obMechanism instanceof AbstractWithoutConditionDiscount) {
                $arKept[] = $obMechanism;
                continue;
            }

            $sClass = get_class($obMechanism);
            if (isset($arWarnedClassList[$sClass])) {
                continue;
            }
            $arWarnedClassList[$sClass] = true;
            Log::warning("ShippingLadder: {$sClass} has no money threshold and is left out of the shipping ladder");
        }

        return $arKept;
    }

    /**
     * Price at every candidate subtotal, consecutive equal prices collapsed.
     * @param object                    $obShippingType
     * @param float                     $fBase
     * @param InterfacePromoMechanism[] $arMechanismList
     * @return array
     */
    protected static function tiers($obShippingType, float $fBase, array $arMechanismList): array
    {
        $arTierList = [];
        foreach (self::candidateSubtotals($arMechanismList) as $fSubtotal) {
            $fPrice = self::priceAt($obShippingType, $fBase, $arMechanismList, $fSubtotal);
            $arLast = end($arTierList);
            if ($arLast !== false && abs($arLast['price'] - $fPrice) < 0.005) {
                continue;
            }
            $arTierList[] = ['from' => $fSubtotal, 'price' => $fPrice];
        }

        return $arTierList;
    }

    /**
     * 0.0 plus every distinct money threshold, ascending.
     * @param InterfacePromoMechanism[] $arMechanismList
     * @return float[]
     */
    protected static function candidateSubtotals(array $arMechanismList): array
    {
        $arSubtotalList = [0.0];
        foreach ($arMechanismList as $obMechanism) {
            if ($obMechanism instanceof AbstractPositionTotalPriceGreaterDiscount) {
                $arSubtotalList[] = PriceHelper::toFloat($obMechanism->getProperty('amount'));
            }
        }
        $arSubtotalList = array_values(array_unique($arSubtotalList, SORT_NUMERIC));
        sort($arSubtotalList, SORT_NUMERIC);

        return $arSubtotalList;
    }

    /**
     * CartPromoMechanismProcessor::applyShippingDiscounts() replayed at one subtotal.
     * @param object                    $obShippingType
     * @param float                     $fBase
     * @param InterfacePromoMechanism[] $arMechanismList
     * @param float                     $fSubtotal
     * @return float
     */
    protected static function priceAt($obShippingType, float $fBase, array $arMechanismList, float $fSubtotal): float
    {
        $obProbe = new ShippingLadderProbe($obShippingType, $fSubtotal);
        $obContainer = new ItemPriceContainer($fBase, $fBase, $obShippingType->tax_percent);

        foreach ($obProbe->sortByPriority($arMechanismList) as $obMechanism) {
            $obContainer = $obMechanism->calculateItemDiscount($obContainer, $obProbe, $obShippingType);
            if (!$obMechanism->isApplied()) {
                continue;
            }
            if ($obMechanism->isFinal()) {
                break;
            }
        }

        return PriceHelper::round((float) $obContainer->price_value);
    }
}
