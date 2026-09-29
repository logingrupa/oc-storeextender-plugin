<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/PriceTierGroupFixtures.php';

use Illuminate\Support\Facades\DB;
use RainLab\User\Models\User;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;

/**
 * Owner ruling 2026-09-29: the Primary Group alone decides the price tier.
 * The secondary groups pivot is never read for pricing.
 */
class ActivePriceHelperPriceTypeTest extends StoreExtenderUserPluginTestCase
{
    use PriceTierGroupFixtures;

    /** @var array<string, int> */
    protected $arGroupIDList;

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        $this->arGroupIDList = $this->createPriceTierGroups();
        ActivePriceHelper::forgetInstance();
    }

    public function tearDown(): void
    {
        Auth::logout();
        ActivePriceHelper::forgetInstance();

        parent::tearDown();
    }

    public function testPrimaryGroupPriceTypeIsTheActivePriceType()
    {
        $obUser = $this->createUserWithPrimaryGroup('primary-salon@nc.test', 'salona');

        Auth::login($obUser);

        $this->assertSame('salon', ActivePriceHelper::instance()->getActivePriceType()->code);
    }

    public function testTierInTheSecondaryGroupsAloneGivesRetail()
    {
        $obUser = User::create([
            'email'                 => 'pivot-only@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        DB::table('users_groups')->insert(['user_id' => $obUser->id, 'user_group_id' => $this->arGroupIDList['distributor']]);

        Auth::login($obUser);

        $this->assertNull(ActivePriceHelper::instance()->getActivePriceType());
    }

    public function testPrimaryGroupWithoutPriceTypeGivesRetail()
    {
        $obUser = $this->createUserWithPrimaryGroup('primary-authorized@nc.test', 'authorized');

        Auth::login($obUser);

        $this->assertNull(ActivePriceHelper::instance()->getActivePriceType());
    }

    public function testGuestGetsNoPriceType()
    {
        $this->assertNull(ActivePriceHelper::instance()->getActivePriceType());
    }

    /**
     * @param string $sEmail
     * @param string $sGroupCode
     * @return User
     */
    protected function createUserWithPrimaryGroup($sEmail, $sGroupCode)
    {
        $obUser = User::create([
            'email'                 => $sEmail,
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        $obUser->primary_group_id = $this->arGroupIDList[$sGroupCode];
        $obUser->save();

        // A request loads the user fresh; this instance still holds the relation beforeCreate set
        return $obUser->fresh();
    }
}
