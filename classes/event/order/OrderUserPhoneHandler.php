<?php namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Illuminate\Support\Facades\Log;

use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;

/**
 * Class OrderUserPhoneHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Order
 *
 * Writes the phone number typed at checkout onto the customer's account, replacing whatever
 * was stored before.
 *
 * MakeOrder::findUserByEmail() gates its own phone handling on Lovata.Buddies, so under
 * RainLab.User an existing customer's phone is never updated at all. Its Buddies branch
 * APPENDS to a comma delimited list, which is how accounts ended up carrying four
 * variations of the same number. The owner wants one current number, so this handler
 * overwrites under both plugins.
 *
 * The address arrays merged into "property" are key prefixed (billing_phone,
 * shipping_phone), so property.phone is unambiguously the user's own field.
 */
class OrderUserPhoneHandler
{
    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        // Fired with halt = true, so this listener must never return a value: anything
        // non-null stops the remaining listeners and false cancels the order outright.
        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_BEFORE_CREATE, function ($arOrderData, $obUser) {
            $this->updateUserPhone($arOrderData, $obUser);
        });
    }

    /**
     * @param array $arOrderData
     * @param \Lovata\Buddies\Models\User|\RainLab\User\Models\User|null $obUser
     * @return void
     */
    protected function updateUserPhone($arOrderData, $obUser)
    {
        if (empty($obUser)) {
            return;
        }

        $sPhone = trim((string) array_get((array) $arOrderData, 'property.phone'));
        if ($sPhone === '') {
            return;
        }

        if ((string) $obUser->phone === $sPhone) {
            return;
        }

        $obUser->phone = $sPhone;

        // This runs inside the order transaction. A user row that fails validation for an
        // unrelated reason must not roll back a paid order, so the failure is logged and
        // the checkout continues.
        try {
            $obUser->save();
        } catch (\Exception $obException) {
            Log::error('Checkout phone update failed for user '.$obUser->id.': '.$obException->getMessage());
        }
    }
}
