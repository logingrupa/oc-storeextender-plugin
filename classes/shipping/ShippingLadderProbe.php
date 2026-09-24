<?php namespace Logingrupa\StoreExtender\Classes\Shipping;

use InvalidArgumentException;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\AbstractPromoMechanismProcessor;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\ItemPriceContainer;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\TotalPriceContainer;

/**
 * The processor a shipping mechanism sees when the ladder asks "what would
 * you do to this shipping type at this subtotal". AbstractPromoMechanism::check()
 * reads only getShippingType(), getPaymentMethod() and getPositionTotalPrice()
 * from its processor; the rest of the processor contract is inert here.
 * No cart, no positions, no DB.
 */
class ShippingLadderProbe extends AbstractPromoMechanismProcessor
{
    /**
     * @param object $obShippingType ShippingTypeItem, or any stand-in exposing ->id
     * @param float  $fSubtotal      position total the mechanism is asked about
     */
    public function __construct($obShippingType, float $fSubtotal)
    {
        if (!is_object($obShippingType)) {
            throw new InvalidArgumentException('ShippingLadderProbe needs a shipping type object');
        }
        if ($fSubtotal < 0.0) {
            throw new InvalidArgumentException("Subtotal must be 0 or more, got {$fSubtotal}");
        }

        $this->obShippingType = $obShippingType;
        $this->obPaymentMethod = null;
        $this->obShippingPriceData = ItemPriceContainer::makeEmpty();
        $this->obTotalPriceData = TotalPriceContainer::makeEmpty();
        $this->obPositionPriceData = TotalPriceContainer::makeEmpty();
        $this->obPositionPriceData->addPriceContainer(new ItemPriceContainer($fSubtotal, $fSubtotal, 0));
    }

    /**
     * Lovata's own priority order, so the ladder folds mechanisms exactly as
     * CartPromoMechanismProcessor::applyShippingDiscounts() does.
     * @param array $arMechanismList
     * @return array
     */
    public function sortByPriority(array $arMechanismList): array
    {
        return $this->applySortingByPriority($arMechanismList);
    }

    /**
     * @return array always empty, the probe carries no positions
     */
    public function getPositionList()
    {
        return [];
    }

    /**
     * Mechanisms are handed to the probe by the ladder, never collected here.
     * @return void
     */
    protected function initMechanismList()
    {
    }

    /**
     * @return void
     */
    protected function applyPositionDiscounts()
    {
    }

    /**
     * @return void
     */
    protected function applyShippingDiscounts()
    {
    }
}
