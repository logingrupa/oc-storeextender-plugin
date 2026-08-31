<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\UserPhoneLookup;

/**
 * The pure half of the phone lookup: normalization and the minimum digit gate.
 *
 * The FIND_IN_SET matching itself is MySQL-only SQL and is covered by
 * tests/integration/BuddiesUserPortMysqlTest, which runs against a real MySQL
 * scratch schema when one is configured.
 */
class UserPhoneLookupTest extends TestCase
{
    protected function lookup()
    {
        UserPhoneLookup::forgetInstance();

        return UserPhoneLookup::instance();
    }

    public function testNormalizeStripsEverythingButDigitsAndPlus()
    {
        $this->assertSame('+37126111222', $this->lookup()->normalize('+371 26 111-222'));
        $this->assertSame('26111222', $this->lookup()->normalize('(26) 111.222 abc'));
        $this->assertSame('', $this->lookup()->normalize('no digits here'));
    }

    public function testNormalizeMatchesTheUserModelDerivation()
    {
        // ExtendRainLabUserModel derives phone_short with %[^\d,+]% over the whole
        // comma list; on a single number the two must agree or lookups miss.
        $sPhone = '+371 26 111 222';
        $sModelDerived = preg_replace('%[^\d,+]%', '', $sPhone);

        $this->assertSame($sModelDerived, $this->lookup()->normalize($sPhone));
    }

    public function testMinimumDigitGate()
    {
        $obLookup = $this->lookup();

        $this->assertFalse($obLookup->isLongEnough('12345'));
        $this->assertTrue($obLookup->isLongEnough('123456'));

        // The plus is not a digit and must not count toward the minimum
        $this->assertFalse($obLookup->isLongEnough('+12345'));
    }

    public function testShortInputAnswersFalseWithoutTouchingTheDatabase()
    {
        // No app, no DB connection - reaching the query would fatal, so a clean
        // false IS the proof the gate short-circuits.
        $this->assertFalse($this->lookup()->exists('12 34 5'));
    }
}
