<?php

require_once __DIR__ . '/PricingCharacterizationTestCase.php';

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Gate 1 guards: the sqlite/:memory: fail-fast must actually fail fast, and the
 * teardown must leave no hermetic tables behind.
 */
class TestInfrastructureGuardTest extends PricingCharacterizationTestCase
{
    public function testGuardFailsFastWhenNotOnSqliteMemory(): void
    {
        $sOriginalDefault = $this->app['config']->get('database.default');

        // Simulate a mis-pinned connection; restore before asserting.
        $this->app['config']->set('database.default', 'mysql');

        $bFailed = false;
        try {
            $this->assertRunningOnSqliteMemory();
        } catch (AssertionFailedError $obException) {
            $bFailed = true;
        } finally {
            $this->app['config']->set('database.default', $sOriginalDefault);
        }

        self::assertTrue($bFailed, 'Guard must fail when the default connection is not sqlite/:memory:.');
    }

    public function testGuardPassesOnSqliteMemory(): void
    {
        $this->assertRunningOnSqliteMemory();
        self::assertTrue(true);
    }

    public function testDropHermeticSchemasLeavesNoHermeticTables(): void
    {
        foreach (self::getHermeticTableList() as $sTable) {
            self::assertTrue(Schema::hasTable($sTable), "setUp must have built {$sTable}");
        }

        $this->dropHermeticSchemas();

        foreach (self::getHermeticTableList() as $sTable) {
            self::assertFalse(Schema::hasTable($sTable), "tearDown drop must remove {$sTable}");
        }
        self::assertFalse(Schema::hasTable('system_settings'));
        self::assertFalse(Schema::hasTable('migrations'));

        // Rebuild so this test's own tearDown (and any later Schema call) stays consistent.
        $this->buildHermeticPricingSchema();
    }
}
