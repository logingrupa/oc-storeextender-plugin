<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\OrdersShopaholic\Classes\Collection\PaymentMethodCollection;
use Lovata\OrdersShopaholic\Classes\Item\PaymentMethodItem;
use Lovata\OrdersShopaholic\Classes\Item\ShippingTypeItem;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Models\ShippingType;
use Lovata\Shopaholic\Models\Settings;

/**
 * Shared checkout shipping, payment, and overstock eligibility rules
 * used by storefront MakeOrder::onCreate and PostNord carrier detection.
 */
class OrderEligibilityValidator
{
    public const POSTNORD_API_CLASS = 'Logingrupa\\PostNordShippingShopaholic\\Classes\\Api\\PostNordShippingProcessor';

    /**
     * Validates shipping_type_id, payment_method_id, visitor visibility, and overstock-vs-online-gateway eligibility.
     * Returns an error message string when rejected, or null when valid.
     */
    public static function validateSelection(
        int $iShippingTypeId,
        int $iPaymentMethodId,
        bool $bIsOverstock,
        bool $bSyncCartShippingType = false,
    ): ?string {
        if ($iShippingTypeId <= 0) {
            return (string) __('Please select a delivery method.');
        }

        $obShippingType = ShippingTypeItem::make($iShippingTypeId);
        if ($obShippingType->isEmpty() || !$obShippingType->active) {
            return (string) __('Please select a delivery method.');
        }
        if ($bSyncCartShippingType) {
            CartProcessor::instance()->setActiveShippingType($obShippingType);
        }

        if ($iPaymentMethodId <= 0) {
            return (string) __('Please select an available payment method.');
        }

        $obPaymentMethod = PaymentMethodItem::make($iPaymentMethodId);
        if (
            $obPaymentMethod->isEmpty()
            || !$obPaymentMethod->active
            || !PaymentMethodCollection::make()->active()->has($iPaymentMethodId)
            || !$obPaymentMethod->isAvailable()
        ) {
            return (string) __('Please select an available payment method.');
        }

        return self::validatePaymentForStockState($obPaymentMethod, $bIsOverstock);
    }

    /**
     * Returns an error message when an online gateway is disallowed for an overstock cart.
     */
    public static function validatePaymentForStockState(PaymentMethodItem $obPaymentMethod, bool $bIsOverstock): ?string
    {
        $bHardStockCheck = (bool) Settings::getValue('check_offer_quantity');
        if (!$bHardStockCheck && !empty($obPaymentMethod->gateway_id) && $bIsOverstock) {
            return (string) __('Some items exceed current stock - payment is by bank transfer after invoice confirmation.');
        }

        return null;
    }

    /**
     * Identifies dynamic-pricing PostNord shipping methods (.no) while allowing flat-rate PostNord methods.
     */
    public static function isDynamicPostNordShipping(int $iShippingTypeId): bool
    {
        if ($iShippingTypeId < 1) {
            return false;
        }

        $obShippingType = ShippingTypeItem::make($iShippingTypeId);
        if ($obShippingType->isEmpty()) {
            return false;
        }

        if (trim((string) $obShippingType->api_class) !== self::POSTNORD_API_CLASS) {
            return false;
        }

        $arProperty = is_array($obShippingType->property) ? $obShippingType->property : [];
        if ($arProperty === []) {
            $obModel = ShippingType::query()->find($iShippingTypeId);
            $arProperty = ($obModel instanceof ShippingType && is_array($obModel->property)) ? $obModel->property : [];
        }

        $sCalculatorClass = 'Logingrupa\\PostNordShippingShopaholic\\Classes\\Api\\PostNordPriceCalculator';
        if (class_exists($sCalculatorClass) && method_exists($sCalculatorClass, 'isDynamicPricingEnabled')) {
            return (bool) $sCalculatorClass::isDynamicPricingEnabled($arProperty);
        }

        return !empty($arProperty['postnord_dynamic_pricing']);
    }
}
