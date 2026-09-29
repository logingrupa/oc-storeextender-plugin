<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/PriceTierGroupFixtures.php';
require_once __DIR__.'/../../updates/update_table_users_primary_group_from_price_group.php';

use Illuminate\Support\Facades\DB;
use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;
use Logingrupa\StoreExtender\Updates\UpdateTableUsersPrimaryGroupFromPriceGroup;

/**
 * The one-time move of the ported tiers from the secondary groups pivot into the
 * primary group, over every row shape the .lv census found on 2026-09-29.
 */
class UsersPrimaryGroupFromPriceGroupTest extends StoreExtenderUserPluginTestCase
{
    use PriceTierGroupFixtures;

    /** @var array<string, int> */
    protected $arGroupIDList;

    /** @var int */
    protected $iRegisteredGroupID;

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        $this->arGroupIDList = $this->createPriceTierGroups();
        $this->iRegisteredGroupID = UserGroup::getRegisteredGroup()->id;
    }

    public function testMovesEveryPortedTierIntoThePrimaryGroup()
    {
        $arUserIDList = [
            'authorized-salona' => $this->createPortedUser($this->iRegisteredGroupID, ['authorized', 'salona']),
            'school-conflict'   => $this->createPortedUser($this->arGroupIDList['kolonna'], ['salona', 'kolonna']),
            'null-primary'      => $this->createPortedUser(null, ['vairum']),
            'dangling-primary'  => $this->createPortedUser(999, ['distributor']),
            'authorized-only'   => $this->createPortedUser($this->iRegisteredGroupID, ['authorized']),
            'retail'            => $this->createPortedUser($this->iRegisteredGroupID, []),
            'override'          => $this->createPortedUser(
                $this->arGroupIDList['studija'],
                ['distributor'],
                'nailscosmetics.slovenija@gmail.com'
            ),
        ];

        (new UpdateTableUsersPrimaryGroupFromPriceGroup())->up();

        $arExpectedList = [
            'authorized-salona' => $this->arGroupIDList['salona'],
            'school-conflict'   => $this->arGroupIDList['kolonna'],
            'null-primary'      => $this->arGroupIDList['vairum'],
            'dangling-primary'  => $this->arGroupIDList['distributor'],
            'authorized-only'   => $this->iRegisteredGroupID,
            'retail'            => $this->iRegisteredGroupID,
            'override'          => $this->arGroupIDList['distributor'],
        ];
        foreach ($arExpectedList as $sCase => $iExpectedGroupID) {
            $iUserID = $arUserIDList[$sCase];
            $this->assertSame($iExpectedGroupID, $this->getPrimaryGroupID($iUserID), $sCase.': primary group');
            $this->assertSame([$iExpectedGroupID], $this->getPivotGroupIDList($iUserID), $sCase.': pivot mirrors primary');
        }
    }

    public function testOverrideNeedsTheMembershipThatGrantsTheTier()
    {
        // .lt and .no have an empty pivot: the same address there must stay retail
        $iUserID = $this->createPortedUser($this->iRegisteredGroupID, [], 'nailscosmetics.slovenija@gmail.com');

        (new UpdateTableUsersPrimaryGroupFromPriceGroup())->up();

        $this->assertSame($this->iRegisteredGroupID, $this->getPrimaryGroupID($iUserID));
    }

    public function testDeletesTheLegacyAuthorizedGroup()
    {
        (new UpdateTableUsersPrimaryGroupFromPriceGroup())->up();

        $this->assertFalse(DB::table('user_groups')->where('code', 'authorized')->exists());
        $this->assertTrue(DB::table('user_groups')->where('code', 'salona')->exists());
    }

    public function testSecondRunChangesNothing()
    {
        $this->createPortedUser($this->iRegisteredGroupID, ['authorized', 'salona']);
        $this->createPortedUser(999, ['distributor']);
        $this->createPortedUser($this->iRegisteredGroupID, []);

        (new UpdateTableUsersPrimaryGroupFromPriceGroup())->up();
        $arUserListAfterFirstRun = DB::table('users')->orderBy('id')->pluck('primary_group_id', 'id')->all();
        $arPivotAfterFirstRun = DB::table('users_groups')->orderBy('user_id')->get()->map(fn ($obRow) => (array) $obRow)->all();

        (new UpdateTableUsersPrimaryGroupFromPriceGroup())->up();

        $this->assertSame($arUserListAfterFirstRun, DB::table('users')->orderBy('id')->pluck('primary_group_id', 'id')->all());
        $this->assertSame($arPivotAfterFirstRun, DB::table('users_groups')->orderBy('user_id')->get()->map(fn ($obRow) => (array) $obRow)->all());
    }

    /**
     * Writes the ported state straight to the tables, past the model events
     * @param int|null      $iPrimaryGroupID
     * @param array<string> $arPivotGroupCodeList
     * @param string|null   $sEmail
     * @return int
     */
    protected function createPortedUser($iPrimaryGroupID, $arPivotGroupCodeList, $sEmail = null)
    {
        $obUser = User::create([
            'email'                 => $sEmail ?? uniqid('ported-').'@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);

        DB::table('users')->where('id', $obUser->id)->update(['primary_group_id' => $iPrimaryGroupID]);
        DB::table('users_groups')->where('user_id', $obUser->id)->delete();
        foreach ($arPivotGroupCodeList as $sGroupCode) {
            DB::table('users_groups')->insert(['user_id' => $obUser->id, 'user_group_id' => $this->arGroupIDList[$sGroupCode]]);
        }

        return $obUser->id;
    }

    /**
     * @param int $iUserID
     * @return int|null
     */
    protected function getPrimaryGroupID($iUserID)
    {
        $iPrimaryGroupID = DB::table('users')->where('id', $iUserID)->value('primary_group_id');

        return $iPrimaryGroupID === null ? null : (int) $iPrimaryGroupID;
    }

    /**
     * @param int $iUserID
     * @return array<int>
     */
    protected function getPivotGroupIDList($iUserID)
    {
        return DB::table('users_groups')->where('user_id', $iUserID)->orderBy('user_group_id')->pluck('user_group_id')->map('intval')->all();
    }
}
