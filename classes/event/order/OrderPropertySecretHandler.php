<?php namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Lovata\OrdersShopaholic\Models\Order;

/**
 * Strips credential fields from the order property snapshot.
 *
 * MakeOrder merges the whole checkout user_data array into Order::$property, so a
 * guest who registers during checkout has their raw password written to the orders
 * table. Bound on the model rather than the component so every write path is covered.
 */
class OrderPropertySecretHandler
{
    /** @var array keys that must never reach the property snapshot */
    const SECRET_FIELD_LIST = ['password', 'password_confirmation'];

    public function subscribe()
    {
        Order::extend(function ($obOrder) {
            $obOrder->bindEvent('model.beforeSave', function () use ($obOrder) {
                $this->stripSecretFields($obOrder);
            });
        });
    }

    protected function stripSecretFields($obOrder)
    {
        $arProperty = $obOrder->property;
        if (empty($arProperty) || !is_array($arProperty)) {
            return;
        }

        $bChanged = false;
        foreach (self::SECRET_FIELD_LIST as $sField) {
            if (array_key_exists($sField, $arProperty)) {
                unset($arProperty[$sField]);
                $bChanged = true;
            }
        }

        if (!$bChanged) {
            return;
        }

        // Toolbox SetPropertyAttributeTrait mutates `property` by MERGING the
        // assigned array into the stored one, so `$obOrder->property = $arProperty`
        // can never drop a key. The raw attribute is rewritten instead.
        $arAttributeList = $obOrder->getAttributes();
        $arAttributeList['property'] = json_encode($arProperty, JSON_UNESCAPED_UNICODE);
        $obOrder->setRawAttributes($arAttributeList);
    }
}
