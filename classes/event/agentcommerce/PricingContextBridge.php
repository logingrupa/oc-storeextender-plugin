<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\AgentCommerce;

use Illuminate\Support\Facades\App;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderAttributionHandler;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
use Logingrupa\StoreExtender\Classes\Helper\OrderAttributionContext;
use Logingrupa\StoreExtender\Classes\Helper\OrderEligibilityValidator;
use Logingrupa\StoreExtender\Classes\Helper\ShopLayoutBinder;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * String-event bridge between Logingrupa.AgentCommerce and StoreExtender:
 * - Resolves B2B primary-group price tier + VAT flag for verified principals
 * - Stamps OrderAttributionContext for direct B2B orders (and skips replay memory)
 * - Handles handoff downgrade seam when an overlapping /apply loses completed_order_id claim
 * - Rejects .no dynamic PostNord shipping methods on direct B2B orders
 */
class PricingContextBridge
{
    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen('agentcommerce.pricing.resolveContext', function ($obPrincipal): ?array {
            return $this->resolvePricingContext($obPrincipal);
        });

        $obEvent->listen('agentcommerce.pricing.releaseContext', function (): void {
            ActivePriceHelper::forgetInstance();
        });

        $obEvent->listen('agentcommerce.order.enterB2bContext', function ($obPrincipal): void {
            $sClientSlug = is_object($obPrincipal) && isset($obPrincipal->sClientSlug) && is_string($obPrincipal->sClientSlug)
                ? $obPrincipal->sClientSlug
                : 'agent_b2b';
            App::make(OrderAttributionContext::class)->set(
                OrderAttributionContext::CHANNEL_AGENT_B2B,
                $sClientSlug,
                true,
                true
            );
        });

        $obEvent->listen('agentcommerce.order.leaveB2bContext', function (): void {
            App::make(OrderAttributionContext::class)->clear();
        });

        $obEvent->listen('agentcommerce.handoff.downgradeOrderToWeb', function ($obOrder): void {
            if ($obOrder instanceof Order) {
                OrderAttributionHandler::downgradeToWebInsideTransaction($obOrder);
            }
        });

        $obEvent->listen('agentcommerce.order.beforeCreate', function ($obPrincipal, $arNormalized): ?array {
            $iShippingTypeId = is_array($arNormalized) && is_numeric($arNormalized['shipping_type_id'] ?? null)
                ? (int) $arNormalized['shipping_type_id']
                : 0;
            if (OrderEligibilityValidator::isDynamicPostNordShipping($iShippingTypeId)) {
                return [
                    'reject' => true,
                    'code' => 'use_checkout_handoff',
                    'message' => 'Dynamic PostNord shipping requires interactive checkout handoff.',
                ];
            }

            return null;
        });

        $obEvent->listen('agentcommerce.order.validateEligibility', function ($obPrincipal, $arNormalized): ?array {
            $iShippingTypeId = is_array($arNormalized) && is_numeric($arNormalized['shipping_type_id'] ?? null)
                ? (int) $arNormalized['shipping_type_id']
                : 0;
            $iPaymentMethodId = is_array($arNormalized) && is_numeric($arNormalized['payment_method_id'] ?? null)
                ? (int) $arNormalized['payment_method_id']
                : 0;
            $sError = OrderEligibilityValidator::validateSelection($iShippingTypeId, $iPaymentMethodId, false, true);
            if ($sError !== null) {
                return [
                    'reject' => true,
                    'code' => 'checkout_method_ineligible',
                    'message' => $sError,
                ];
            }

            return null;
        });
    }

    /**
     * @return array{price_type_code: ?string, price_includes_vat: bool, currency_code: string}|null
     */
    private function resolvePricingContext(mixed $obPrincipal): ?array
    {
        ActivePriceHelper::forgetInstance();
        $sCurrencyCode = (string) (CurrencyHelper::instance()->getActiveCurrencyCode() ?: 'EUR');

        $bCanUseB2b = is_object($obPrincipal)
            && method_exists($obPrincipal, 'canUseB2bPricing')
            && $obPrincipal->canUseB2bPricing();

        if (!$bCanUseB2b || !isset($obPrincipal->iUserId) || !is_int($obPrincipal->iUserId)) {
            return [
                'price_type_code' => null,
                'price_includes_vat' => true,
                'currency_code' => $sCurrencyCode,
            ];
        }

        $sPriceTypeCode = $this->resolveUserPrimaryPriceTypeCode($obPrincipal->iUserId);
        $bIncludesVat = $sPriceTypeCode === null
            || in_array($sPriceTypeCode, ShopLayoutBinder::VAT_INCLUSIVE_PRICE_TYPE_LIST, true);

        return [
            'price_type_code' => $sPriceTypeCode,
            'price_includes_vat' => $bIncludesVat,
            'currency_code' => $sCurrencyCode,
        ];
    }

    private function resolveUserPrimaryPriceTypeCode(int $iUserId): ?string
    {
        $sUserModelClass = UserHelper::instance()->getUserModel();
        if (!is_string($sUserModelClass) || !class_exists($sUserModelClass)) {
            return null;
        }

        $obUser = $sUserModelClass::query()->find($iUserId);
        if ($obUser === null) {
            return null;
        }

        $obPrimaryGroup = $obUser->primary_group;
        if (empty($obPrimaryGroup) || empty($obPrimaryGroup->price_type_id)) {
            return null;
        }

        $obPriceType = $obPrimaryGroup->price_type;
        if (empty($obPriceType) || !is_string($obPriceType->code) || trim($obPriceType->code) === '') {
            return null;
        }

        return trim($obPriceType->code);
    }
}
