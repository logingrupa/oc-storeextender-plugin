<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/ShopItemDoubles.php';

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\StoreExtender\Classes\Event\Cache\SatelliteCacheInvalidationHandler;
use Logingrupa\StoreExtender\Classes\Event\ExtendPaymentGateway;
use Logingrupa\StoreExtender\Classes\Helper\ProductCardLabelPrimer;
use Logingrupa\StoreExtender\Classes\Registrar\PaymentRedirectRegistrar;
use Logingrupa\StoreExtender\Classes\Registrar\ShopaholicExtensionRegistrar;
use Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway as OmnipayPaymentGateway;
use Lovata\PayPalShopaholic\Classes\Helper\PayPalExpressPaymentGateway;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use System\Classes\PluginManager;

class OptionalPluginGuardsTest extends StoreExtenderPluginTestCase
{
    use ShopItemDoubles;

    protected $autoMigrate = false;

    public function testShopaholicExtensionRegistrarGuardsOptionalRelations(): void
    {
        $arOrigProduct = ProductItem::$arQueryWith;
        $arOrigOffer = OfferItem::$arQueryWith;
        $arOrigCategory = CategoryItem::$arQueryWith;

        try {
            $this->withDisabledPlugins(['Lovata.MightySeo', 'Lovata.PropertiesShopaholic'], function (): void {
                ProductItem::$arQueryWith = [];
                OfferItem::$arQueryWith = [];
                CategoryItem::$arQueryWith = [];

                ShopaholicExtensionRegistrar::extendItemEagerLoading();

                $this->assertNotContains('seo_container', ProductItem::$arQueryWith);
                $this->assertNotContains('seo_container', CategoryItem::$arQueryWith);
                $this->assertNotContains('property_set', CategoryItem::$arQueryWith);
                $this->assertContains('translations', ProductItem::$arQueryWith);
                $this->assertContains('translations', CategoryItem::$arQueryWith);
            });

            ProductItem::$arQueryWith = [];
            OfferItem::$arQueryWith = [];
            CategoryItem::$arQueryWith = [];

            ShopaholicExtensionRegistrar::extendItemEagerLoading();

            $this->assertContains('seo_container', ProductItem::$arQueryWith);
            $this->assertContains('seo_container', CategoryItem::$arQueryWith);
            $this->assertContains('property_set', CategoryItem::$arQueryWith);
        } finally {
            ProductItem::$arQueryWith = $arOrigProduct;
            OfferItem::$arQueryWith = $arOrigOffer;
            CategoryItem::$arQueryWith = $arOrigCategory;
        }
    }

    public function testProductCardLabelPrimerSkipsQueriesWhenLabelsPluginDisabled(): void
    {
        $arQueries = [];
        DB::listen(function ($obQuery) use (&$arQueries): void {
            if (str_contains($obQuery->sql, ProductCardLabelPrimer::PIVOT_TABLE)) {
                $arQueries[] = $obQuery->sql;
            }
        });

        $arCards = [$this->makeProduct(['id' => 101]), $this->makeProduct(['id' => 102])];
        $this->withDisabledPlugins(['Lovata.LabelsShopaholic'], function () use ($arCards, &$arQueries): void {
            $mReturned = ProductCardLabelPrimer::prime($arCards);
            $this->assertSame($arCards, $mReturned);
            $this->assertSame([], $arQueries);
        });
    }

    public function testPaymentGatewayListenersSkipWhenPluginsDisabled(): void
    {
        $this->withDisabledPlugins(['Lovata.PayPalShopaholic'], function (): void {
            $obDispatcher = new Dispatcher;
            (new ExtendPaymentGateway)->subscribe($obDispatcher);
            $this->assertFalse($obDispatcher->hasListeners(PayPalExpressPaymentGateway::EVENT_GET_PAYMENT_GATEWAY_RETURN_URL));
        });

        $obOriginalDispatcher = Event::getFacadeRoot();
        try {
            $obFreshDispatcher = new Dispatcher;
            Event::swap($obFreshDispatcher);

            $this->withDisabledPlugins(['Lovata.OmnipayShopaholic'], function (): void {
                PaymentRedirectRegistrar::addPaymentGatewayRedirectListeners();
            });

            $this->assertFalse($obFreshDispatcher->hasListeners(OmnipayPaymentGateway::EVENT_GET_PAYMENT_GATEWAY_CANCEL_URL));
            $this->assertFalse($obFreshDispatcher->hasListeners(OmnipayPaymentGateway::EVENT_GET_PAYMENT_GATEWAY_RETURN_URL));
        } finally {
            Event::swap($obOriginalDispatcher);
        }
    }

    public function testSatelliteCacheInvalidationSkipsDisabledOptionalPlugins(): void
    {
        $this->withDisabledPlugins(['Lovata.PropertiesShopaholic', 'Lovata.MightySeo'], function (): void {
            $obHandler = new SatelliteCacheInvalidationHandler;
            $obHandler->subscribe(new Dispatcher);
            $this->assertTrue(true);
        });
    }

    /**
     * Temporarily marks plugins disabled in-memory on PluginManager without touching storage/cms/disabled.php.
     *
     * @param  list<string>  $arCodes
     */
    private function withDisabledPlugins(array $arCodes, callable $fnCallback): void
    {
        $obManager = PluginManager::instance();
        $obProp = new ReflectionProperty(PluginManager::class, 'disabledPlugins');
        $obProp->setAccessible(true);
        $arOriginal = (array) $obProp->getValue($obManager);

        try {
            $arDisabled = $arOriginal;
            foreach ($arCodes as $sCode) {
                $arDisabled[$obManager->normalizeIdentifier($sCode)] = true;
            }
            $obProp->setValue($obManager, $arDisabled);
            $fnCallback();
        } finally {
            $obProp->setValue($obManager, $arOriginal);
        }
    }
}
