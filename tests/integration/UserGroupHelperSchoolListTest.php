<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\DB;

use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;

/**
 * The school list the register and account forms offer. Mirrors the migrated
 * production layout: price groups below id 5, schools from id 5 up, and RainLab's
 * seeded guest/registered groups relocated above them by the Buddies port.
 */
class UserGroupHelperSchoolListTest extends StoreExtenderUserPluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
        UserGroupHelper::forgetInstance();
    }

    public function tearDown(): void
    {
        UserGroupHelper::forgetInstance();

        parent::tearDown();
    }

    public function testSchoolListSkipsPriceGroupsAndSeededGroups()
    {
        DB::table('user_groups')->delete();
        DB::table('user_groups')->insert([
            ['id' => 4, 'name' => 'Vairums', 'code' => 'vairum'],
            ['id' => 5, 'name' => 'Studija', 'code' => 'studija'],
            ['id' => 6, 'name' => 'Kolonna', 'code' => 'kolonna'],
            ['id' => 13, 'name' => 'Guest', 'code' => 'guest'],
            ['id' => 14, 'name' => 'Registered', 'code' => 'registered'],
        ]);

        $arCodeList = UserGroupHelper::instance()->getSchoolGroupList()->pluck('code')->all();

        $this->assertSame(['studija', 'kolonna'], $arCodeList);
    }
}
