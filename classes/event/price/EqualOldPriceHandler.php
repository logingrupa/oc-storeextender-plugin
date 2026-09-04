<?php namespace Logingrupa\StoreExtender\Classes\Event\Price;

use Lovata\Shopaholic\Models\Price;
use Lovata\Toolbox\Classes\Helper\PriceHelper;

/**
 * An old price equal to the price is no discount, so it is stored as 0 and never rendered struck.
 */
class EqualOldPriceHandler
{
    /**
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        Price::extend(function (Price $obPrice) {
            $obPrice->bindEvent('model.beforeSave', function () use ($obPrice) {
                self::dropEqualOldPrice($obPrice);
            });
        });
    }

    /**
     * @param Price $obPrice
     */
    public static function dropEqualOldPrice(Price $obPrice): void
    {
        $fOldPrice = PriceHelper::toFloat($obPrice->old_price_value);
        if ($fOldPrice <= 0) {
            return;
        }

        if (abs($fOldPrice - PriceHelper::toFloat($obPrice->price_value)) >= 0.005) {
            return;
        }

        $obPrice->old_price = 0;
    }
}
