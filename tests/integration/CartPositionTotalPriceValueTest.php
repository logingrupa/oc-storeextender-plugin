<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';
require_once __DIR__.'/../doubles/ShippingLadderStubTables.php';
require_once __DIR__.'/../doubles/TaxHelperDouble.php';

use Illuminate\Support\Facades\DB;
use Lovata\CampaignsShopaholic\Classes\Helper\CampaignHelper;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\ItemPriceContainer;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\TotalPriceContainer;
use Lovata\OrdersShopaholic\Components\Cart as CartComponent;

/**
 * The shipping ladder partial reads the subtotal through the Twig-only
 * dynamic method Cart::getPositionTotalPriceValue(), registered by the REAL
 * plugin boot. An empty cart on stub tables answers 0.0 through the whole
 * CartProcessor build; the non-zero answer is proven against a processor
 * stand-in, because a priced position needs the full Shopaholic chain that
 * does not run on SQLite (EqualOldPriceHandlerTest).
 */
class CartPositionTotalPriceValueTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        ShippingLadderStubTables::create();
        TaxHelperDouble::install();
        CampaignHelper::forgetInstance();
    }

    public function tearDown(): void
    {
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        CampaignHelper::forgetInstance();
        TaxHelperDouble::reset();
        parent::tearDown();
    }

    public function testEmptyCartAnswersZeroAsFloat()
    {
        CartProcessor::$iTestCartID = DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => null]);

        $fSubtotal = (new CartComponent())->getPositionTotalPriceValue();

        $this->assertSame(0.0, $fSubtotal);
    }

    public function testSubtotalIsTheProcessorPositionTotalAsFloat()
    {
        $obTotal = TotalPriceContainer::makeEmpty();
        $obTotal->addPriceContainer(new ItemPriceContainer(12.5, 12.5, 0));
        $this->installProcessorStandIn($obTotal);

        $fSubtotal = (new CartComponent())->getPositionTotalPriceValue();

        $this->assertSame(12.5, $fSubtotal);
    }

    /**
     * @param TotalPriceContainer $obTotal
     * @return void
     */
    protected function installProcessorStandIn(TotalPriceContainer $obTotal)
    {
        $obStandIn = new class($obTotal) {
            private $obTotal;

            public function __construct($obTotal)
            {
                $this->obTotal = $obTotal;
            }

            public function getCartPositionTotalPriceData()
            {
                return $this->obTotal;
            }
        };

        $obProperty = new ReflectionProperty(CartProcessor::class, 'instance');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, $obStandIn);
    }
}
