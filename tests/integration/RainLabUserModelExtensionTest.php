<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/PriceTierGroupFixtures.php';

use Illuminate\Support\Facades\Log;

use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;

/**
 * The Buddies-shaped surface ExtendRainLabUserModel puts on RainLab's User, exercised
 * through the REAL plugin boot: no manual extend() here, so a UserModelHandler that
 * stops wiring the extension (the PluginManager::hasPlugin() regression that shipped a
 * guest menu to logged in users) fails every test in this file at once.
 */
class RainLabUserModelExtensionTest extends StoreExtenderUserPluginTestCase
{
    use PriceTierGroupFixtures;

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
    }

    public function testNameAliasesFirstNameBothWays()
    {
        $obUser = new User();

        $obUser->name = 'Anna';
        $this->assertSame('Anna', $obUser->first_name);

        $obUser->first_name = 'Berta';
        $this->assertSame('Berta', $obUser->name);
    }

    public function testPhoneDerivesPhoneShort()
    {
        $obUser = new User();

        $obUser->phone = '+371 26 111-222';

        $arAttributeList = $obUser->getAttributes();
        $this->assertSame('+371 26 111-222', $arAttributeList['phone'], 'the typed form is stored verbatim');
        $this->assertSame('+37126111222', $arAttributeList['phone_short']);
    }

    public function testPhoneListJoinsAndSplitsTheDelimitedColumn()
    {
        $obUser = new User();

        $obUser->phone_list = ['+371 20000001', '  ', '+371 20000002'];

        $this->assertSame('+371 20000001,+371 20000002', $obUser->getAttributes()['phone']);
        $this->assertSame(['+371 20000001', '+371 20000002'], $obUser->phone_list);
        $this->assertSame('+37120000001,+37120000002', $obUser->getAttributes()['phone_short']);
    }

    public function testPropertyMergesInsteadOfReplacing()
    {
        $obUser = new User();

        $obUser->property = ['security' => '5', 'account_type' => 'Private person'];
        $obUser->property = ['account_type' => 'Legal entity'];

        // The partial assignment overwrote its own key and kept the untouched one
        $this->assertSame(
            ['security' => '5', 'account_type' => 'Legal entity'],
            $obUser->property
        );
    }

    public function testEmptyPropertyAssignmentRemovesNothing()
    {
        $obUser = new User();
        $obUser->property = ['security' => '5'];

        $obUser->property = [];

        // Same contract as Lovata's SetPropertyAttributeTrait: an assignment can add
        // or overwrite a key, never remove one.
        $this->assertSame(['security' => '5'], $obUser->property);
    }

    public function testJsonStringPropertyAssignmentMerges()
    {
        $obUser = new User();
        $obUser->property = ['security' => '5'];

        // The ported Buddies rows carry property as raw JSON text
        $obUser->property = '{"school-name":"kolonna"}';

        $this->assertSame(
            ['security' => '5', 'school-name' => 'kolonna'],
            $obUser->property
        );
    }

    public function testUserWithoutFirstNameSaves()
    {
        // 3147 of the 13821 ported accounts carry no name; RainLab's stock
        // "required" rule would make every one of them unsaveable.
        $obUser = User::create([
            'email'                 => 'no-name@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);

        $this->assertNull($obUser->first_name);
        $this->assertTrue($obUser->exists);

        $obUser->phone = '+371 26111222';
        $obUser->save();

        $this->assertSame('+37126111222', $obUser->fresh()->phone_short);
    }

    public function testValidationMessagesNameTheFieldInTheShoppersLanguage()
    {
        // Both validators the auth forms hit - the model rules on register, a bare
        // Validator on password reset - read validation.attributes for the locale.
        // Without the plugin's lang path they shipped "password apstiprinājums nesakrīt".
        $sPreviousLocale = \App::getLocale();
        \App::setLocale('lv');

        try {
            $obUser = new User([
                'email'                 => 'named@nc.test',
                'password'              => 'Probe12345',
                'password_confirmation' => 'Other12345',
            ]);

            $sModelMessage = '';
            try {
                $obUser->validate();
                $this->fail('A mismatched confirmation must fail validation');
            } catch (\October\Rain\Database\ModelException $obException) {
                $sModelMessage = $obException->getErrors()->first('password');
            }

            $obValidator = \Validator::make(
                ['password' => 'Probe12345', 'password_confirmation' => 'Other12345'],
                ['password' => 'required|confirmed']
            );
            $sPlainMessage = $obValidator->errors()->first('password');
        } finally {
            \App::setLocale($sPreviousLocale);
        }

        $this->assertSame('Paroles apstiprinājums nesakrīt.', $sModelMessage);
        $this->assertSame('Paroles apstiprinājums nesakrīt.', $sPlainMessage);
    }

    public function testSchoolWithPriceTypeBecomesThePrimaryGroup()
    {
        $arGroupIDList = $this->createPriceTierGroups();

        $obUser = $this->createUser('school@nc.test', ['school-name' => 'kolonna']);

        $this->assertSame($arGroupIDList['kolonna'], (int) $obUser->fresh()->primary_group_id);
        $this->assertSame([$arGroupIDList['kolonna']], $this->getGroupIDList($obUser));
    }

    public function testChangingSchoolMovesThePrimaryGroup()
    {
        $arGroupIDList = $this->createPriceTierGroups();
        $obUser = $this->createUser('school-change@nc.test', ['school-name' => 'kolonna']);

        $obUser->property = ['school-name' => 'studija'];
        $obUser->save();

        $this->assertSame($arGroupIDList['studija'], (int) $obUser->fresh()->primary_group_id);
        $this->assertSame([$arGroupIDList['studija']], $this->getGroupIDList($obUser));
    }

    public function testSchoolNeverReplacesAManagerSetTier()
    {
        // Owner ruling 2026-09-29
        $arGroupIDList = $this->createPriceTierGroups();
        $obUser = $this->createUser('distributor-school@nc.test');
        $obUser->primary_group_id = $arGroupIDList['distributor'];
        $obUser->save();

        $obUser->property = ['school-name' => 'studija'];
        $obUser->save();

        $this->assertSame($arGroupIDList['distributor'], (int) $obUser->fresh()->primary_group_id);
    }

    public function testManagerSetTierWinsOverASchoolChosenInTheSameSave()
    {
        $arGroupIDList = $this->createPriceTierGroups();
        $obUser = $this->createUser('same-save@nc.test');

        $obUser->primary_group_id = $arGroupIDList['vairum'];
        $obUser->property = ['school-name' => 'studija'];
        $obUser->save();

        $this->assertSame($arGroupIDList['vairum'], (int) $obUser->fresh()->primary_group_id);
    }

    public function testSchoolWithoutPriceTypeLeavesThePrimaryGroupAlone()
    {
        $arGroupIDList = $this->createPriceTierGroups();
        UserGroup::create(['name' => 'Akademija', 'code' => 'akademija']);
        $obUser = $this->createUser('school-no-price@nc.test');
        $obUser->primary_group_id = $arGroupIDList['salona'];
        $obUser->save();

        $obUser->property = ['school-name' => 'akademija'];
        $obUser->save();

        $this->assertSame($arGroupIDList['salona'], (int) $obUser->fresh()->primary_group_id);
    }

    public function testUnrelatedSaveKeepsTheManagerSetPrimaryGroup()
    {
        $this->createPriceTierGroups();
        $obUser = $this->createUser('school-unrelated@nc.test', ['school-name' => 'kolonna']);

        // A manager moved the user to retail; a checkout phone update must not restore the school tier
        $iRegisteredGroupID = UserGroup::getRegisteredGroup()->id;
        $obUser->primary_group_id = $iRegisteredGroupID;
        $obUser->save();

        $obUser = $obUser->fresh();
        $obUser->phone = '+371 26111222';
        $obUser->save();

        $this->assertSame($iRegisteredGroupID, (int) $obUser->fresh()->primary_group_id);
        $this->assertSame([$iRegisteredGroupID], $this->getGroupIDList($obUser));
    }

    public function testChangingThePrimaryGroupLeavesItAsTheOnlySecondaryGroup()
    {
        $arGroupIDList = $this->createPriceTierGroups();
        $obUser = $this->createUser('pivot-mirror@nc.test');
        $obUser->groups()->attach([$arGroupIDList['authorized'], $arGroupIDList['salona']]);

        $obUser->primary_group_id = $arGroupIDList['distributor'];
        $obUser->save();

        $this->assertSame([$arGroupIDList['distributor']], $this->getGroupIDList($obUser));
    }

    public function testUnknownSchoolCodeWarnsOnlyWhenItChanges()
    {
        // 16 ported accounts carry a free-text school-name such as "1" that matches no
        // group; every login or checkout save of those users used to log this warning.
        Log::shouldReceive('warning')
            ->once()
            ->with("Group with code '1' not found.");

        $obUser = User::create([
            'email'                 => 'school-junk@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
            'property'              => ['school-name' => '1'],
        ]);

        $obUser = $obUser->fresh();
        $obUser->phone = '+371 26111222';
        $obUser->save();

        $this->assertSame('1', $obUser->fresh()->property['school-name']);
    }

    public function testRegistrationWithoutSchoolKeepsRegisteredPrimary()
    {
        $obUser = User::create([
            'email'                 => 'no-school@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);

        $iRegisteredGroupID = UserGroup::getRegisteredGroup()->id;
        $this->assertSame($iRegisteredGroupID, (int) $obUser->fresh()->primary_group_id);
        $this->assertSame([$iRegisteredGroupID], $this->getGroupIDList($obUser));
    }

    /**
     * @param string $sEmail
     * @param array  $arProperty
     * @return User
     */
    protected function createUser($sEmail, $arProperty = [])
    {
        return User::create([
            'email'                 => $sEmail,
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
            'property'              => $arProperty,
        ]);
    }

    /**
     * @param User $obUser
     * @return array<int>
     */
    protected function getGroupIDList($obUser)
    {
        return $obUser->groups()->pluck('id')->map('intval')->all();
    }
}
