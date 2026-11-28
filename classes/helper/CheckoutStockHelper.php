<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\OrdersShopaholic\Classes\Collection\CartPositionCollection;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * Authoritative cart overstock calculation shared by checkout rendering,
 * AJAX summary refreshes, and MakeOrder::onCreate eligibility checks.
 */
class CheckoutStockHelper
{
    /**
     * True when any position in the cart requests more units than the offer's
     * available quantity.
     */
    public static function isCartOverstock(?CartPositionCollection $obCartPositionList = null): bool
    {
        $obPositions = $obCartPositionList ?? CartProcessor::instance()->get();
        if ($obPositions->isEmpty()) {
            return false;
        }

        foreach ($obPositions as $obPosition) {
            $iQuantity = (int) ($obPosition->quantity ?? 0);
            $obItem = $obPosition->item;
            $iMaxQuantity = $obItem && !$obItem->isEmpty() ? (int) ($obItem->quantity ?? 0) : 0;

            if ($iQuantity > $iMaxQuantity) {
                return true;
            }
        }

        return false;
    }
}
