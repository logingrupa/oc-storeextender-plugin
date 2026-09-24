<?php

use Lovata\Shopaholic\Classes\Helper\TaxHelper;

/**
 * TaxHelper::init() loads the tax collection from lovata_shopaholic_taxes,
 * a table the hermetic tests do not create. The price containers only ask
 * the helper whether prices include tax and to convert between the two
 * bases, so a helper built without its constructor and a fixed flag is the
 * whole contract. Same Singleton seam as UserHelper in CartStateReaderTest.
 */
final class TaxHelperDouble
{
    /**
     * @param bool $bPriceIncludeTax
     * @return void
     */
    public static function install(bool $bPriceIncludeTax = true)
    {
        $obHelper = (new ReflectionClass(TaxHelper::class))->newInstanceWithoutConstructor();

        $obFlag = new ReflectionProperty(TaxHelper::class, 'bPriceIncludeTax');
        $obFlag->setAccessible(true);
        $obFlag->setValue($obHelper, $bPriceIncludeTax);

        $obInstance = new ReflectionProperty(TaxHelper::class, 'instance');
        $obInstance->setAccessible(true);
        $obInstance->setValue(null, $obHelper);
    }

    /**
     * @return void
     */
    public static function reset()
    {
        TaxHelper::forgetInstance();
    }
}
