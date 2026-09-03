<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;

use Logingrupa\StoreExtender\Classes\Helper\BuddiesUserPorter;
use Logingrupa\StoreExtender\Classes\Helper\BuddiesUserChecksum;
use Logingrupa\StoreExtender\Classes\Helper\UserPhoneLookup;

/**
 * The Buddies-to-RainLab data port and its checksum, against real MySQL.
 *
 * These two classes are MySQL-only by construction (INSERT ... ON DUPLICATE KEY
 * UPDATE, SHA2, BIT_XOR, CONV, FIND_IN_SET), so the in-memory SQLite harness cannot
 * carry them. The whole class SKIPS unless a scratch database is configured:
 *
 *   STOREEXTENDER_MYSQL_TEST_DB=storeextender_test \
 *   php ../../../vendor/bin/phpunit --filter BuddiesUserPortMysqlTest
 *
 * (host/port/user/password via STOREEXTENDER_MYSQL_TEST_HOST/_PORT/_USER/_PASS,
 * defaulting to 127.0.0.1:3306 root with no password - the Laragon dev box.)
 * Point it at a DEDICATED empty database: every table it uses is dropped and
 * recreated per test.
 */
class BuddiesUserPortMysqlTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const CONNECTION = 'storeextender_mysql_test';

    const ALL_TABLE_LIST = [
        'lovata_buddies_users', 'lovata_buddies_groups', 'lovata_buddies_users_groups',
        'lovata_buddies_addition_properties',
        'users', 'user_groups', 'users_groups', 'logingrupa_storeextender_user_properties',
    ];

    public function setUp(): void
    {
        parent::setUp();

        $sDatabase = (string) getenv('STOREEXTENDER_MYSQL_TEST_DB');
        if ($sDatabase === '') {
            $this->markTestSkipped(
                'MySQL-only coverage (porter, checksum, FIND_IN_SET): set STOREEXTENDER_MYSQL_TEST_DB to a scratch database to run it.'
            );
        }

        config([
            'database.connections.'.self::CONNECTION => [
                'driver'    => 'mysql',
                'host'      => getenv('STOREEXTENDER_MYSQL_TEST_HOST') ?: '127.0.0.1',
                'port'      => getenv('STOREEXTENDER_MYSQL_TEST_PORT') ?: '3306',
                'database'  => $sDatabase,
                'username'  => getenv('STOREEXTENDER_MYSQL_TEST_USER') ?: 'root',
                'password'  => getenv('STOREEXTENDER_MYSQL_TEST_PASS') ?: '',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ],
            'database.default' => self::CONNECTION,
        ]);
        DB::purge(self::CONNECTION);

        BuddiesUserPorter::forgetInstance();
        BuddiesUserChecksum::forgetInstance();
        UserPhoneLookup::forgetInstance();

        $this->createSchema();
        $this->seedFixture();
    }

    public function tearDown(): void
    {
        // The parent tearDown flushes model event listeners, and booting those models
        // reads system_settings - which the MySQL scratch schema does not have. Point
        // the default connection back at the hermetic SQLite before it runs.
        config(['database.default' => 'sqlite']);
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    protected function createSchema()
    {
        foreach (self::ALL_TABLE_LIST as $sTable) {
            Schema::dropIfExists($sTable);
        }

        Schema::create('lovata_buddies_users', function (Blueprint $obTable) {
            $obTable->integer('id')->primary();
            $obTable->string('name')->nullable();
            $obTable->string('last_name')->nullable();
            $obTable->string('email');
            $obTable->string('password')->nullable();
            $obTable->string('activation_code')->nullable();
            $obTable->boolean('is_activated')->default(false);
            $obTable->timestamp('activated_at')->nullable();
            $obTable->timestamp('last_login')->nullable();
            $obTable->string('phone')->nullable();
            $obTable->string('phone_short')->nullable();
            $obTable->text('property')->nullable();
            $obTable->text('viewed_products')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        Schema::create('lovata_buddies_groups', function (Blueprint $obTable) {
            $obTable->integer('id')->primary();
            $obTable->string('name')->nullable();
            $obTable->string('code');
            $obTable->string('description')->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->timestamps();
        });

        Schema::create('lovata_buddies_users_groups', function (Blueprint $obTable) {
            $obTable->integer('user_id');
            $obTable->integer('group_id');
            $obTable->primary(['user_id', 'group_id']);
        });

        Schema::create('lovata_buddies_addition_properties', function (Blueprint $obTable) {
            $obTable->integer('id')->primary();
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->string('code')->nullable();
            $obTable->text('description')->nullable();
            $obTable->string('type')->nullable();
            $obTable->text('settings')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamps();
        });

        Schema::create('users', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('is_guest')->default(false);
            $obTable->boolean('is_mail_blocked')->default(false);
            $obTable->string('first_name')->nullable();
            $obTable->string('last_name')->nullable();
            $obTable->string('username')->nullable();
            $obTable->string('email');
            $obTable->string('password')->nullable();
            $obTable->string('activation_code')->nullable();
            $obTable->string('persist_code')->nullable();
            $obTable->integer('primary_group_id')->nullable();
            $obTable->timestamp('activated_at')->nullable();
            $obTable->timestamp('last_seen')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
            $obTable->string('phone')->nullable();
            $obTable->string('phone_short')->nullable();
            $obTable->text('property')->nullable();
            $obTable->text('viewed_products')->nullable();
        });

        Schema::create('user_groups', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code');
            $obTable->string('description')->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->timestamps();
        });

        Schema::create('users_groups', function (Blueprint $obTable) {
            $obTable->integer('user_id');
            $obTable->integer('user_group_id');
            $obTable->primary(['user_id', 'user_group_id']);
        });

        Schema::create('logingrupa_storeextender_user_properties', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->string('code')->nullable();
            $obTable->text('description')->nullable();
            $obTable->string('type')->nullable();
            $obTable->text('settings')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamps();
        });
    }

    /**
     * Mirror of the production shape at C3 scale: the seeded RainLab groups occupy the
     * ids the Buddies groups claim, one user holds two groups, one carries no name,
     * one is soft deleted, one was activated by flag with no timestamp.
     */
    protected function seedFixture()
    {
        DB::table('user_groups')->insert([
            ['id' => 1, 'name' => 'Guest', 'code' => 'guest', 'description' => null, 'price_type_id' => null, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
            ['id' => 2, 'name' => 'Registered', 'code' => 'registered', 'description' => null, 'price_type_id' => null, 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00'],
        ]);

        DB::table('lovata_buddies_groups')->insert([
            ['id' => 1, 'name' => 'Authorized', 'code' => 'authorized', 'description' => null, 'price_type_id' => 1, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['id' => 2, 'name' => 'Salona', 'code' => 'salona', 'description' => null, 'price_type_id' => 2, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['id' => 5, 'name' => 'Kolonna', 'code' => 'kolonna', 'description' => null, 'price_type_id' => null, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
        ]);

        DB::table('lovata_buddies_users')->insert([
            [
                'id' => 10, 'name' => 'Anna', 'last_name' => 'Berzina', 'email' => 'anna@nc.test',
                'password' => '$2y$10$examplehashexamplehashexampleha', 'activation_code' => null,
                'is_activated' => 1, 'activated_at' => null, 'last_login' => '2026-08-30 10:00:00',
                'phone' => '+371 20000001,+371 26111222', 'phone_short' => '+37120000001,+37126111222',
                'property' => '{"security":"5"}', 'viewed_products' => null, 'deleted_at' => null,
                'created_at' => '2021-05-01 12:00:00', 'updated_at' => '2026-08-30 10:00:00',
            ],
            [
                'id' => 11, 'name' => null, 'last_name' => null, 'email' => 'nameless@nc.test',
                'password' => '$2y$10$anotherhashanotherhashanotherha', 'activation_code' => 'abc',
                'is_activated' => 0, 'activated_at' => null, 'last_login' => null,
                'phone' => null, 'phone_short' => null,
                'property' => null, 'viewed_products' => null, 'deleted_at' => null,
                'created_at' => '2022-01-01 00:00:00', 'updated_at' => '2022-01-01 00:00:00',
            ],
            [
                'id' => 12, 'name' => 'Gone', 'last_name' => null, 'email' => 'deleted@nc.test',
                'password' => '$2y$10$deletedhashdeletedhashdeletedha', 'activation_code' => null,
                'is_activated' => 1, 'activated_at' => '2023-01-01 00:00:00', 'last_login' => null,
                'phone' => '+371 29999999', 'phone_short' => '+37129999999',
                'property' => null, 'viewed_products' => null, 'deleted_at' => '2024-06-01 00:00:00',
                'created_at' => '2023-01-01 00:00:00', 'updated_at' => '2024-06-01 00:00:00',
            ],
            [
                'id' => 15, 'name' => 'Skola', 'last_name' => null, 'email' => 'student@nc.test',
                'password' => '$2y$10$studenthashstudenthashstudenth', 'activation_code' => null,
                'is_activated' => 1, 'activated_at' => '2024-01-01 00:00:00', 'last_login' => null,
                'phone' => null, 'phone_short' => null,
                'property' => '{"security":"5","school-name":"kolonna"}', 'viewed_products' => null, 'deleted_at' => null,
                'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 16, 'name' => 'Broken', 'last_name' => null, 'email' => 'broken-json@nc.test',
                'password' => '$2y$10$brokenhashbrokenhashbrokenhash', 'activation_code' => null,
                'is_activated' => 1, 'activated_at' => '2024-01-01 00:00:00', 'last_login' => null,
                'phone' => null, 'phone_short' => null,
                'property' => 'not-json', 'viewed_products' => null, 'deleted_at' => null,
                'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
            ],
        ]);

        DB::table('lovata_buddies_users_groups')->insert([
            ['user_id' => 10, 'group_id' => 1],
            ['user_id' => 10, 'group_id' => 2],
            ['user_id' => 11, 'group_id' => 5],
        ]);

        DB::table('lovata_buddies_addition_properties')->insert([
            ['id' => 1, 'active' => 1, 'name' => 'Company', 'slug' => 'company', 'code' => 'company', 'description' => null, 'type' => 'text', 'settings' => null, 'sort_order' => 1, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
            ['id' => 2, 'active' => 1, 'name' => 'Reg nr', 'slug' => 'reg-nr', 'code' => 'reg_nr', 'description' => null, 'type' => 'text', 'settings' => null, 'sort_order' => 2, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00'],
        ]);
    }

    protected function assertAllChecksumsMatch($sContext)
    {
        foreach (BuddiesUserChecksum::instance()->compare() as $arRow) {
            $this->assertTrue(
                $arRow['match'],
                $sContext.': checksum mismatch on '.$arRow['section'].'.'.$arRow['column']
            );
        }
    }

    public function testCleanFixtureHasNoBlockers()
    {
        $this->assertSame([], BuddiesUserPorter::instance()->getBlockerList());
    }

    public function testBlockersCatchTheThreeDataHazards()
    {
        DB::table('lovata_buddies_users')->insert([
            'id' => 13, 'email' => 'anna@nc.test', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
        ]);
        DB::table('users')->insert([
            'id' => 11, 'email' => 'someone-else@nc.test', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
        ]);
        DB::table('user_groups')->insert([
            'id' => 30, 'name' => 'Old Authorized', 'code' => 'authorized', 'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-01 00:00:00',
        ]);

        $sBlockerText = implode(' | ', BuddiesUserPorter::instance()->getBlockerList());

        $this->assertStringContainsString('duplicate email', $sBlockerText);
        $this->assertStringContainsString('belong to a different account', $sBlockerText);
        $this->assertStringContainsString('under a different id', $sBlockerText);
    }

    public function testPortPreservesIdsRelocatesSeededGroupsAndMapsEveryColumn()
    {
        BuddiesUserPorter::instance()->port();

        // Seeded RainLab groups moved above the combined max id (5), codes untouched
        $this->assertSame(6, (int) DB::table('user_groups')->where('code', 'guest')->value('id'));
        $iRegisteredID = (int) DB::table('user_groups')->where('code', 'registered')->value('id');
        $this->assertSame(7, $iRegisteredID);

        // Buddies groups landed on their original ids with their price types
        $this->assertSame('authorized', DB::table('user_groups')->where('id', 1)->value('code'));
        $this->assertSame(2, (int) DB::table('user_groups')->where('code', 'salona')->value('price_type_id'));

        $obAnna = DB::table('users')->where('id', 10)->first();
        $this->assertSame('Anna', $obAnna->first_name, 'Buddies "name" maps onto first_name');
        $this->assertSame('anna@nc.test', $obAnna->username, 'username is seeded from email');
        $this->assertNull($obAnna->persist_code, 'Buddies hashes persist_code, RainLab does not - it must be nulled');
        $this->assertSame($iRegisteredID, (int) $obAnna->primary_group_id);
        $this->assertSame('2021-05-01 12:00:00', $obAnna->activated_at, 'is_activated with no timestamp backfills from created_at');
        $this->assertSame('2026-08-30 10:00:00', $obAnna->last_seen);
        $this->assertSame('+37120000001,+37126111222', $obAnna->phone_short);

        $this->assertNull(DB::table('users')->where('id', 11)->value('activated_at'), 'a never-activated account must stay unactivated');
        $this->assertSame('2024-06-01 00:00:00', DB::table('users')->where('id', 12)->value('deleted_at'));

        // The school from property["school-name"] becomes the primary group; a malformed
        // property payload neither errors nor moves the primary off registered
        $this->assertSame(5, (int) DB::table('users')->where('id', 15)->value('primary_group_id'));
        $this->assertSame($iRegisteredID, (int) DB::table('users')->where('id', 16)->value('primary_group_id'));

        // Memberships copied as (user_id, group_id) pairs on original group ids
        $this->assertSame(
            [['user_id' => 10, 'user_group_id' => 1], ['user_id' => 10, 'user_group_id' => 2], ['user_id' => 11, 'user_group_id' => 5]],
            DB::table('users_groups')->orderBy('user_id')->orderBy('user_group_id')
                ->get()->map(function ($obRow) {
                    return ['user_id' => (int) $obRow->user_id, 'user_group_id' => (int) $obRow->user_group_id];
                })->all()
        );

        $this->assertAllChecksumsMatch('after first port');
    }

    public function testRelocationCarriesPreExistingMembershipsAndPrimaryGroup()
    {
        DB::table('users')->insert([
            'id' => 100, 'email' => 'native@nc.test', 'primary_group_id' => 2,
            'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
        ]);
        DB::table('users_groups')->insert(['user_id' => 100, 'user_group_id' => 2]);

        BuddiesUserPorter::instance()->port();

        $iRegisteredID = (int) DB::table('user_groups')->where('code', 'registered')->value('id');
        $this->assertSame($iRegisteredID, (int) DB::table('users')->where('id', 100)->value('primary_group_id'));
        $this->assertTrue(
            DB::table('users_groups')->where('user_id', 100)->where('user_group_id', $iRegisteredID)->exists(),
            'the native user must follow the relocated registered group'
        );

        // The checksum scopes the target to the ported id set, so the native row is invisible to it
        $this->assertAllChecksumsMatch('with a native RainLab user present');
    }

    public function testResyncPropagatesAMembershipRevocationButSparesNativeRows()
    {
        $obPorter = BuddiesUserPorter::instance();
        $obPorter->port();

        // A native account above the ported range with its own membership
        DB::table('users')->insert([
            'id' => 100, 'email' => 'native@nc.test',
            'created_at' => '2026-08-01 00:00:00', 'updated_at' => '2026-08-01 00:00:00',
        ]);
        DB::table('users_groups')->insert(['user_id' => 100, 'user_group_id' => 2]);

        // The source revokes a ported user's membership between syncs
        $arRevoked = (array) DB::table('lovata_buddies_users_groups')->orderBy('user_id')->first();
        DB::table('lovata_buddies_users_groups')
            ->where('user_id', $arRevoked['user_id'])->where('group_id', $arRevoked['group_id'])->delete();

        $obPorter->port();

        $this->assertFalse(
            DB::table('users_groups')
                ->where('user_id', $arRevoked['user_id'])->where('user_group_id', $arRevoked['group_id'])->exists(),
            'a membership revoked at the source must disappear from the target on a re-sync'
        );
        $this->assertTrue(
            DB::table('users_groups')->where('user_id', 100)->where('user_group_id', 2)->exists(),
            'a native account above the ported range keeps its membership'
        );
        $this->assertAllChecksumsMatch('after the revocation re-sync');
    }

    public function testSecondRunIsIdempotentAndRepairsADivergedRow()
    {
        $obPorter = BuddiesUserPorter::instance();
        $obPorter->port();

        $iUserCount = DB::table('users')->count();
        $iGroupCount = DB::table('user_groups')->count();

        // A target-side mutation, as if someone edited a ported account post-port
        DB::table('users')->where('id', 10)->update(['phone' => 'tampered', 'phone_short' => 'tampered']);
        $bAnyMismatch = collect(BuddiesUserChecksum::instance()->compare())->contains('match', false);
        $this->assertTrue($bAnyMismatch, 'the checksum must see the tampered row before the repair');

        $obPorter->port();

        $this->assertSame($iUserCount, DB::table('users')->count());
        $this->assertSame($iGroupCount, DB::table('user_groups')->count());
        $this->assertSame('+371 20000001,+371 26111222', DB::table('users')->where('id', 10)->value('phone'));
        $this->assertAllChecksumsMatch('after the repair run');
    }

    public function testChecksumDetectsAnEqualLengthValueSwapBetweenTwoIds()
    {
        BuddiesUserPorter::instance()->port();

        // "Anna" and "Gone": equal length, so a digest that collapses to a bag of
        // bytes per column (the CRC32/BIT_XOR hole) cannot tell the swap happened.
        DB::table('users')->where('id', 10)->update(['first_name' => 'Gone']);
        DB::table('users')->where('id', 12)->update(['first_name' => 'Anna']);

        $arMismatchList = [];
        foreach (BuddiesUserChecksum::instance()->compare() as $arRow) {
            if (!$arRow['match']) {
                $arMismatchList[] = $arRow['section'].'.'.$arRow['column'];
            }
        }

        $this->assertSame(['users.first_name'], $arMismatchList, 'exactly the swapped column must go red');
    }

    public function testAutoIncrementResumesAboveThePortedRange()
    {
        BuddiesUserPorter::instance()->port();

        $iNewID = DB::table('users')->insertGetId([
            'email' => 'fresh@nc.test', 'created_at' => '2026-09-01 00:00:00', 'updated_at' => '2026-09-01 00:00:00',
        ]);

        $this->assertSame(17, $iNewID, 'AUTO_INCREMENT must resume above the highest ported id');
    }

    public function testPhoneLookupMatchesAVariantInsideTheCommaList()
    {
        BuddiesUserPorter::instance()->port();

        $obLookup = UserPhoneLookup::instance();

        $this->assertTrue($obLookup->exists('+371 26 111 222'), 'the second variant in the comma list must match');
        $this->assertTrue($obLookup->exists('+371 20000001'));
        $this->assertFalse($obLookup->exists('+371 26111299'), 'an unknown number must not match');
        $this->assertFalse($obLookup->exists('261112'), 'a substring of a stored number must not match');
        $this->assertFalse($obLookup->exists('+371 29999999'), 'a soft deleted account must not answer');
    }
}
