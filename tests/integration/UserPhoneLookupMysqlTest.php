<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;

use Logingrupa\StoreExtender\Classes\Helper\UserPhoneLookup;

/**
 * The FIND_IN_SET phone match, against real MySQL. The in-memory SQLite harness has
 * no FIND_IN_SET, so the class SKIPS unless a scratch database is configured:
 *
 *   STOREEXTENDER_MYSQL_TEST_DB=storeextender_test \
 *   php ../../../vendor/bin/phpunit --filter UserPhoneLookupMysqlTest
 *
 * (host/port/user/password via STOREEXTENDER_MYSQL_TEST_HOST/_PORT/_USER/_PASS,
 * defaulting to 127.0.0.1:3306 root with no password - the Laragon dev box.)
 * Point it at a DEDICATED empty database: the users table is dropped and recreated per test.
 */
class UserPhoneLookupMysqlTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    const CONNECTION = 'storeextender_mysql_test';

    public function setUp(): void
    {
        parent::setUp();

        $sDatabase = (string) getenv('STOREEXTENDER_MYSQL_TEST_DB');
        if ($sDatabase === '') {
            $this->markTestSkipped('MySQL-only FIND_IN_SET: set STOREEXTENDER_MYSQL_TEST_DB to a scratch database to run it.');
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
        UserPhoneLookup::forgetInstance();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('email');
            $obTable->string('phone_short')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        DB::table('users')->insert([
            ['email' => 'list@nc.test', 'phone_short' => '+37120000001,+37126111222', 'deleted_at' => null],
            ['email' => 'deleted@nc.test', 'phone_short' => '+37129999999', 'deleted_at' => '2026-01-01 00:00:00'],
        ]);
    }

    public function tearDown(): void
    {
        // The parent tearDown boots models that read system_settings, which the scratch
        // schema does not have, so the default connection goes back to SQLite first.
        config(['database.default' => 'sqlite']);
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    public function testMatchesAVariantInsideTheCommaList()
    {
        $obLookup = UserPhoneLookup::instance();

        $this->assertTrue($obLookup->exists('+371 26 111 222'), 'the second variant in the comma list must match');
        $this->assertTrue($obLookup->exists('+371 20000001'));
        $this->assertFalse($obLookup->exists('+371 26111299'), 'an unknown number must not match');
        $this->assertFalse($obLookup->exists('261112'), 'a substring of a stored number must not match');
        $this->assertFalse($obLookup->exists('+371 29999999'), 'a soft deleted account must not answer');
    }
}
