<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/ShopLayoutDoubles.php';
require_once __DIR__.'/../doubles/ShopItemDoubles.php';

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RainLab\User\Models\User;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;
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
    use ShopItemDoubles;

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

    /**
     * End-to-end validation that a signed-in user in a primary group gets their
     * group's Shopaholic price type code, numeric iPriceTypeID, active helper ID,
     * and tiered OfferItem price on a fresh guard through ShopLayoutBinder::bind().
     *
     * @param string      $sGroupCode
     * @param string|null $sExpectedPriceTypeCode
     * @param float       $fExpectedOfferPrice
     */
    #[DataProvider('priceTypeByPrimaryGroupProvider')]
    public function testBindsPrimaryGroupPriceTypeAndResolvesTierOfferPriceOnFreshGuard(
        $sGroupCode,
        $sExpectedPriceTypeCode,
        $fExpectedOfferPrice
    ) {
        $arGroupIDList = $this->createPriceTierGroups();
        $this->insertDefaultEurCurrency();

        $obUser = User::create([
            'email'                 => $sGroupCode.'-binder@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        $obUser->primary_group_id = $arGroupIDList[$sGroupCode];
        $obUser->save();

        Auth::login($obUser->fresh());
        Auth::forgetGuards();
        ActivePriceHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();
        CurrencyHelper::forgetInstance();

        $obLayout = new FakeShopLayout();
        ShopLayoutBinder::bind($obLayout);

        $iExpectedID = $sExpectedPriceTypeCode === null
            ? null
            : (int) DB::table('lovata_shopaholic_price_types')->where('code', $sExpectedPriceTypeCode)->value('id');

        $this->assertSame($sExpectedPriceTypeCode, $obLayout['sPriceType']);
        $this->assertSame($iExpectedID, $obLayout['iPriceTypeID']);
        $this->assertSame($iExpectedID, PriceTypeHelper::instance()->getActivePriceTypeID());
        $this->assertSame($fExpectedOfferPrice, $this->makeTierProbeOffer()->price_value);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: float}>
     */
    public static function priceTypeByPrimaryGroupProvider()
    {
        return [
            'salona group resolves salon tier price'          => ['salona', 'salon', 7.5],
            'studija school group resolves salon tier price'  => ['studija', 'salon', 7.5],
            'vairum group resolves wholesale tier price'      => ['vairum', 'wholesale', 6.0],
            'kolonna school group resolves wholesale price'   => ['kolonna', 'wholesale', 6.0],
            'distributor group resolves distributor price'    => ['distributor', 'distributor', 4.5],
            'authorized group without price type gets retail' => ['authorized', null, 10.0],
        ];
    }

    public function testGuestAndSecondaryGroupOnlyBindNullPriceTypeIdAndKeepRetailPrice()
    {
        $arGroupIDList = $this->createPriceTierGroups();
        $this->insertDefaultEurCurrency();

        // Guest user
        Auth::logout();
        Auth::forgetGuards();
        ActivePriceHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();
        CurrencyHelper::forgetInstance();

        $obGuestLayout = new FakeShopLayout();
        ShopLayoutBinder::bind($obGuestLayout);

        $this->assertNull($obGuestLayout['sPriceType']);
        $this->assertNull($obGuestLayout['iPriceTypeID']);
        $this->assertTrue($obGuestLayout['bPriceIncludesVAT']);
        $this->assertSame(10.0, $this->makeTierProbeOffer()->price_value);

        // Signed-in user holding distributor only in secondary pivot, retail primary
        $obUser = User::create([
            'email'                 => 'secondary-only-binder@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        DB::table('users_groups')->insert([
            'user_id'       => $obUser->id,
            'user_group_id' => $arGroupIDList['distributor'],
        ]);

        Auth::login($obUser->fresh());
        Auth::forgetGuards();
        ActivePriceHelper::forgetInstance();
        PriceTypeHelper::forgetInstance();

        $obPivotLayout = new FakeShopLayout();
        ShopLayoutBinder::bind($obPivotLayout);

        $this->assertNull($obPivotLayout['sPriceType']);
        $this->assertNull($obPivotLayout['iPriceTypeID']);
        $this->assertTrue($obPivotLayout['bPriceIncludesVAT']);
        $this->assertSame(10.0, $this->makeTierProbeOffer()->price_value);
    }

    /**
     * @return void
     */
    protected function insertDefaultEurCurrency()
    {
        DB::table('lovata_shopaholic_currency')->insert([
            'active'     => 1,
            'is_default' => 1,
            'name'       => 'EUR',
            'code'       => 'EUR',
            'symbol'     => '€',
            'rate'       => 1,
        ]);
    }

    /**
     * Real OfferItem hydrated without an offers table so OfferItem::getPriceValueAttribute()
     * reads PriceTypeHelper::instance()->getActivePriceTypeID() directly.
     *
     * @return OfferItem
     */
    protected function makeTierProbeOffer()
    {
        $arPriceIDByCode = DB::table('lovata_shopaholic_price_types')->pluck('id', 'code')->all();

        /** @var OfferItem $obOffer */
        $obOffer = $this->makeItem(OfferItem::class, [
            'id'          => 101,
            'price_value' => 10.0,
            'price_list'  => [
                (int) $arPriceIDByCode['salon']       => ['price' => 7.5, 'old_price' => 10.0],
                (int) $arPriceIDByCode['wholesale']   => ['price' => 6.0, 'old_price' => 10.0],
                (int) $arPriceIDByCode['distributor'] => ['price' => 4.5, 'old_price' => 10.0],
            ],
        ]);

        return $obOffer;
    }
}
