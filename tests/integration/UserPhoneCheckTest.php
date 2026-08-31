<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use System\Classes\RateLimiter;

use Logingrupa\StoreExtender\Components\UserPhoneCheck;

/**
 * The public phone-check endpoint: the response must carry nothing but a boolean,
 * short input must not consume the rate limit, and the limiter must fire BEFORE the
 * lookup.
 *
 * The true/false matching itself needs FIND_IN_SET and is covered by the MySQL-gated
 * integration test; here the lookup query is unreachable by design - on this SQLite
 * schema reaching it would raise a QueryException, so a clean pass doubles as proof
 * of the short-circuit order.
 */
class UserPhoneCheckTest extends StoreExtenderUserPluginTestCase
{
    protected function callHandler($sPhone)
    {
        request()->server->set('REQUEST_METHOD', 'POST');
        request()->setMethod('POST');
        request()->request->replace(['phone' => $sPhone]);

        return (new UserPhoneCheck())->onCheckPhone();
    }

    protected function limiter()
    {
        return new RateLimiter('phone-check:'.request()->ip());
    }

    public function testShortInputAnswersABareFalseWithoutConsumingTheRateLimit()
    {
        $arResult = $this->callHandler('12345');

        // assertSame pins the whole shape: exactly one key, exactly a boolean
        $this->assertSame(['exists' => false], $arResult);
        $this->assertSame(0, $this->limiter()->attempts(), 'short input must not consume the rate limit');
    }

    public function testEmptyInputAnswersFalse()
    {
        $this->assertSame(['exists' => false], $this->callHandler(''));
    }

    public function testThrottleFiresBeforeTheLookup()
    {
        $obLimiter = $this->limiter();
        for ($iAttempt = 0; $iAttempt < UserPhoneCheck::RATE_LIMIT_ATTEMPTS; $iAttempt++) {
            $obLimiter->increment(UserPhoneCheck::RATE_LIMIT_DECAY_SECONDS);
        }

        $this->expectException(ApplicationException::class);

        // Long enough to pass the digit gate: only the throttle can stop it now,
        // and if the throttle ran after the lookup this would be a QueryException.
        $this->callHandler('+37126111222');
    }

    public function testUnderTheLimitEachLookupCountsOneAttempt()
    {
        $obLimiter = $this->limiter();
        $obLimiter->increment(UserPhoneCheck::RATE_LIMIT_DECAY_SECONDS);
        $iBefore = $obLimiter->attempts();

        // The lookup itself raises on SQLite (FIND_IN_SET does not exist), which is
        // fine: the attempt must be consumed before the query runs.
        try {
            $this->callHandler('+37126111222');
        } catch (Exception $obException) {
            // expected on this schema; see class comment
        }

        $this->assertSame($iBefore + 1, $this->limiter()->attempts());
    }
}
