<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Illuminate\Support\Facades\Schema;
use Kharanenka\Helper\Result;
use Logingrupa\StoreExtender\Classes\Helper\CheckoutStockHelper;
use Logingrupa\StoreExtender\Classes\Helper\OrderEligibilityValidator;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Components\MakeOrder;
use Lovata\OrdersShopaholic\Models\CartPosition;
use October\Rain\Support\Facades\Input;

/**
 * Enforces payment method eligibility before MakeOrder::onCreate creates users
 * or opens a transaction, and stamps the authoritative server-computed
 * out_of_stock flag onto the final order properties right before persistence.
 */
class CheckoutPaymentEligibilityHandler
{
    public const HANDLER = 'onCreate';

    /**
     * @param  \Illuminate\Events\Dispatcher  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        MakeOrder::extend(function (MakeOrder $obComponent): void {
            $obComponent->bindEvent('component.beforeRunAjaxHandler', function ($sHandler) {
                return $this->validateBeforeCreate((string) $sHandler);
            });
        });

        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_DATA, function ($arOrderData) {
            return $this->stampAuthoritativeStockFlag(is_array($arOrderData) ? $arOrderData : []);
        });
    }

    /**
     * Reject ineligible payment choices before MakeOrder registers a user or
     * creates an order. Empty carts pass through so OrderSubmitReplayHandler
     * still handles lost-response replays.
     *
     * @return array{status: false, message: string, data: array<mixed>}|null
     */
    protected function validateBeforeCreate(string $sHandler): ?array
    {
        if ($sHandler !== self::HANDLER) {
            return null;
        }

        if (app()->runningUnitTests() && !Schema::hasTable('lovata_orders_shopaholic_carts')) {
            return null;
        }

        $obCartProcessor = CartProcessor::instance();
        $obCart = $obCartProcessor->getCartObject();
        if (empty($obCart) || !CartPosition::getByCart($obCart->id)->exists()) {
            return null;
        }

        $arOrderData = Input::get('order');
        if (!is_array($arOrderData)) {
            $arOrderData = [];
        }

        $iShippingTypeId = (int) array_get($arOrderData, 'shipping_type_id', 0);
        $iPaymentMethodId = (int) array_get($arOrderData, 'payment_method_id', 0);
        $sError = OrderEligibilityValidator::validateSelection(
            $iShippingTypeId,
            $iPaymentMethodId,
            CheckoutStockHelper::isCartOverstock(),
            true
        );
        if ($sError !== null) {
            return $this->reject($sError);
        }

        // Release the pre-login CartProcessor singleton so MakeOrder::onCreate()
        // initializes cart ownership and shipping state identically to stock Shopaholic.
        CartProcessor::forgetInstance();

        return null;
    }

    /**
     * Overwrite order.property.out_of_stock from the live cart after MakeOrder
     * has merged saved and submitted user data so client input cannot spoof it.
     *
     * @param  array<string, mixed>  $arOrderData
     * @return array{property: array<string, mixed>}
     */
    protected function stampAuthoritativeStockFlag(array $arOrderData): array
    {
        $arProperty = is_array($arOrderData['property'] ?? null) ? $arOrderData['property'] : [];
        $arProperty['out_of_stock'] = CheckoutStockHelper::isCartOverstock() ? '1' : '0';

        return ['property' => $arProperty];
    }

    /**
     * @return array{status: false, message: string, data: array<mixed>}
     */
    protected function reject(string $sMessage): array
    {
        Result::setFalse()->setMessage($sMessage);

        return Result::get();
    }
}
