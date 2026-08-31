<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

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

    public function testSchoolNamePropertyAttachesTheGroupOnSave()
    {
        $obGroup = UserGroup::create(['name' => 'Kolonna', 'code' => 'kolonna']);

        $obUser = User::create([
            'email'                 => 'school@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
            'property'              => ['school-name' => 'kolonna'],
        ]);

        $this->assertTrue(
            $obUser->groups()->where('code', 'kolonna')->exists(),
            'UserModelHandler must attach the school group after save'
        );

        // A second save must not detach it (syncWithoutDetaching contract)
        $obUser->save();
        $this->assertTrue($obUser->groups()->where('id', $obGroup->id)->exists());
    }
}
