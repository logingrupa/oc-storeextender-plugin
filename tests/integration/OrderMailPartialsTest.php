<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Plugin;

/**
 * The order mail templates call these partials by code, and an unregistered file partial
 * renders as a "Missing partial" comment where the customer's line items belong.
 */
class OrderMailPartialsTest extends StoreExtenderPluginTestCase
{
    /** Registrations and view files are read without touching a table */
    protected $autoMigrate = false;

    const ORDER_MAIL_PARTIAL_LIST = ['orderMailBody', 'orderMailItems', 'orderMailBank', 'orderMailButton'];

    public function testOrderMailPartialsAreRegisteredAndReadable()
    {
        $arPartialList = (new Plugin(App::make('app')))->registerMailPartials();

        foreach (self::ORDER_MAIL_PARTIAL_LIST as $sCode) {
            $this->assertArrayHasKey($sCode, $arPartialList);

            $sFileName = substr($arPartialList[$sCode], strrpos($arPartialList[$sCode], '.') + 1);

            $this->assertFileExists(__DIR__.'/../../views/mail/'.$sFileName.'.htm');
        }
    }
}
