<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Logingrupa\StoreExtender\Classes\Event\User\AccountCartIdentityHandler;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Components\Cart as CartComponent;
use RainLab\User\Models\User;

/**
 * Login, registration and logout leave the account checkout fields on the
 * account cart row, through the REAL plugin boot, so a signed-out visitor gets
 * the same prefill and Meta identity as a guest who typed them.
 */
class AccountCartIdentityHandlerTest extends StoreExtenderUserPluginTestCase
{
    const ACCOUNT_FIELDS = [
        'name'      => 'Anna',
        'last_name' => 'Bērziņa',
        'email'     => 'account@nc.test',
        'phone'     => '26 111-222',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
        $this->createCartTables();
    }

    public function tearDown(): void
    {
        $this->dropCartTables();

        parent::tearDown();
    }

    public function testLogoutWritesAccountFieldsOntoTheUserCart()
    {
        $obUser = $this->createUser(self::ACCOUNT_FIELDS);
        $this->insertCart(7, $obUser->id, null);

        Event::fire(AccountCartIdentityHandler::EVENT_LOGOUT, [null, $obUser]);

        $this->assertSame(self::ACCOUNT_FIELDS, $this->cartUserData(7));
        $this->assertSame('account@nc.test', DB::table('lovata_orders_shopaholic_carts')->where('id', 7)->value('email'));
        $this->assertSame(1, DB::table('lovata_orders_shopaholic_carts')->count(), 'writes the existing account cart');
    }

    public function testLoginMintsTheAccountCartWhenNoneExists()
    {
        $obUser = $this->createUser(self::ACCOUNT_FIELDS);

        Event::fire(AccountCartIdentityHandler::EVENT_LOGIN, [$obUser]);

        $iCartId = (int) DB::table('lovata_orders_shopaholic_carts')->where('user_id', $obUser->id)->value('id');
        $this->assertGreaterThan(0, $iCartId);
        $this->assertSame(self::ACCOUNT_FIELDS, $this->cartUserData($iCartId));
    }

    public function testRegistrationWritesLikeLogin()
    {
        $obUser = $this->createUser(self::ACCOUNT_FIELDS);
        $this->insertCart(7, $obUser->id, null);

        Event::fire(AccountCartIdentityHandler::EVENT_REGISTER, [null, $obUser]);

        $this->assertSame(self::ACCOUNT_FIELDS, $this->cartUserData(7));
    }

    public function testEmptyAccountFieldKeepsTheTypedValue()
    {
        $obUser = $this->createUser(['name' => 'Anna', 'last_name' => 'Liepa', 'email' => 'account@nc.test', 'phone' => '']);
        $this->insertCart(7, $obUser->id, ['name' => 'Guest', 'phone' => '29 000-111', 'comment' => 'kept']);

        Event::fire(AccountCartIdentityHandler::EVENT_LOGOUT, [null, $obUser]);

        $this->assertSame(
            ['name' => 'Anna', 'phone' => '29 000-111', 'comment' => 'kept', 'last_name' => 'Liepa', 'email' => 'account@nc.test'],
            $this->cartUserData(7)
        );
    }

    public function testCartComponentPrefillsTheSignedOutVisitorFromTheAccountCart()
    {
        $obUser = $this->createUser(self::ACCOUNT_FIELDS);
        $this->insertCart(7, $obUser->id, null);
        Event::fire(AccountCartIdentityHandler::EVENT_LOGOUT, [null, $obUser]);
        CartProcessor::$iTestCartID = 7;

        $this->assertSame(self::ACCOUNT_FIELDS, (new CartComponent())->getSavedUserData());
    }

    /**
     * @param array $arFields name, last_name, email, phone
     * @return User
     */
    protected function createUser(array $arFields): User
    {
        $obUser = User::create([
            'email'                 => $arFields['email'],
            'first_name'            => $arFields['name'],
            'last_name'             => $arFields['last_name'],
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        DB::table('users')->where('id', $obUser->id)->update(['phone' => $arFields['phone']]);

        return $obUser->fresh();
    }

    /**
     * @param int        $iCartId
     * @param int        $iUserId
     * @param array|null $arUserData
     * @return void
     */
    protected function insertCart(int $iCartId, int $iUserId, ?array $arUserData)
    {
        DB::table('lovata_orders_shopaholic_carts')->insert([
            'id'        => $iCartId,
            'user_id'   => $iUserId,
            'user_data' => $arUserData === null ? null : json_encode($arUserData),
        ]);
    }

    /**
     * @param int $iCartId
     * @return array
     */
    protected function cartUserData(int $iCartId): array
    {
        return (array) json_decode((string) DB::table('lovata_orders_shopaholic_carts')->where('id', $iCartId)->value('user_data'), true);
    }
}
