<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Plugin;

/**
 * The order mail templates call product, orderSummary and buttons, and no site carries
 * a DB row for them, so an unregistered file partial renders as a "Missing partial"
 * comment where the customer's line items belong.
 */
class OrderMailPartialsTest extends StoreExtenderPluginTestCase
{
    /** Registrations and view files are read without touching a table */
    protected $autoMigrate = false;

    const ORDER_MAIL_PARTIAL_LIST = ['product', 'orderSummary', 'buttons'];

    public function testOrderMailPartialsAreRegisteredAndReadable()
    {
        $arPartialList = (new Plugin(App::make('app')))->registerMailPartials();

        foreach (self::ORDER_MAIL_PARTIAL_LIST as $sCode) {
            $this->assertArrayHasKey($sCode, $arPartialList);

            $sFileName = substr($arPartialList[$sCode], strrpos($arPartialList[$sCode], '.') + 1);

            $this->assertFileExists(__DIR__.'/../../views/mail/'.$sFileName.'.htm');
        }
    }

    public function testProductPartialResolvesTheOfferOffThePositionItPassed()
    {
        $sMarkup = file_get_contents(__DIR__.'/../../views/mail/product.htm');

        $this->assertStringContainsString(
            '{% set obOffer = obOrderPosition.item %}',
            $sMarkup,
            'the order template passes only order and obOrderPosition'
        );
    }
}
