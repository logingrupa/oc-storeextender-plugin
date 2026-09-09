<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/ShopLayoutDoubles.php';

use Illuminate\Support\Facades\DB;
use RainLab\User\Models\User;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Logingrupa\StoreExtender\Classes\Helper\ShopLayoutBinder;

/**
 * On RainLab.User, Auth::getUser() answers only the user this request already
 * loaded; nothing reads the session until user() or check() runs. Every HTTP
 * request starts with an empty guard, so a binder that reads the cart before
 * anything primed the guard serves a signed-in shopper the guest cookie cart
 * (02-REVIEW WR-01). The layout double's Session component never touches the
 * guard, so the only thing that can prime it here is the binder itself.
 */
class ShopLayoutBinderGuardPrimingTest extends StoreExtenderUserPluginTestCase
{
    use ShopLayoutStubTables;

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        $this->createShopLayoutStubTables();

        PriceTypeHelper::forgetInstance();
        CurrencyHelper::forgetInstance();
        request()->setMethod('GET');
    }

    public function tearDown(): void
    {
        Auth::logout();
        Auth::forgetGuards();
        $this->restoreActivePriceHelper();

        PriceTypeHelper::forgetInstance();
        CurrencyHelper::forgetInstance();
        CartProcessor::$iTestCartID = null;

        parent::tearDown();
    }

    public function testServesTheSignedInShopperTheirOwnCartOnAFreshGuard()
    {
        $obUser = User::create([
            'email'                 => 'guard-priming@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        Auth::login($obUser);

        // The shape of every new HTTP request: the session carries the login,
        // the guard has loaded nobody yet
        Auth::forgetGuards();
        $this->assertNull(UserHelper::instance()->getUser(), 'precondition: a fresh guard holds no user until asked');

        $iUserCartID = DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => $obUser->id]);
        DB::table('lovata_orders_shopaholic_cart_positions')->insert([
            'cart_id'  => $iUserCartID,
            'item_id'  => 7,
            'quantity' => 1,
        ]);
        $iGuestCartID = DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => null]);
        CartProcessor::$iTestCartID = $iGuestCartID;

        $obLayout = new FakeShopLayout();

        ShopLayoutBinder::bind($obLayout);

        $this->assertSame(1, $obLayout['arCartState']['count'], 'the badge must come from the user cart, not the guest cookie cart');
        $this->assertSame([7 => 1], $obLayout['arCartState']['positions']);
        $this->assertSame($obUser->id, UserHelper::instance()->getUser()->id, 'the guard stays primed for every later read');
    }
}
