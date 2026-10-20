<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use October\Rain\Support\Facades\Input;
use Lovata\OrdersShopaholic\Components\MakeOrder;
use Logingrupa\StoreExtender\Classes\Helper\CheckoutPassword;

/**
 * Class CheckoutPasswordHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * MakeOrder registers a checkout account with str_random(8) when no password was typed,
 * which misses RainLab's number rule about one time in four. This fills the same branch
 * with a policy-compliant password before onCreate reads the input. The generated value
 * reaches order.property, where OrderPropertySecretHandler strips it.
 */
class CheckoutPasswordHandler
{
    const HANDLER = 'onCreate';

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent untyped: October passes its own dispatcher
     * @return void
     */
    public function subscribe($obEvent): void
    {
        MakeOrder::extend(function (MakeOrder $obComponent): void {
            // Fired with halt = true: any non-null return replaces the handler's answer
            $obComponent->bindEvent('component.beforeRunAjaxHandler', function ($sHandler): void {
                $this->fillGeneratedPassword((string) $sHandler);
            });
        });
    }

    /**
     * Mirrors MakeOrder::createUser: a non-empty user array whose password is empty().
     * @param string $sHandler
     * @return void
     */
    protected function fillGeneratedPassword(string $sHandler): void
    {
        if ($sHandler !== self::HANDLER) {
            return;
        }

        $arUserData = Input::get('user');
        if (empty($arUserData) || !is_array($arUserData) || !empty($arUserData['password'])) {
            return;
        }

        $arUserData['password'] = CheckoutPassword::make();
        Input::merge(['user' => $arUserData]);
    }
}
