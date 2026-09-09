<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Logingrupa\StoreExtender\Classes\Event\User\PhoneLoginHandler;
use RainLab\User\Models\User;

/**
 * Login by phone through the REAL rainlab.user.beforeAuthenticate seam: a phone
 * with the right password resolves the account, a wrong password or unknown
 * number fails the attempt, and an email login is left to RainLab.
 *
 * FIND_IN_SET is MySQL-only; SQLite gets a PHP twin registered on the PDO so the
 * lookup runs against the hermetic schema.
 */
class PhoneLoginHandlerTest extends StoreExtenderUserPluginTestCase
{
    const PASSWORD = 'Probe12345';

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        DB::connection()->getPdo()->sqliteCreateFunction('FIND_IN_SET', function ($sNeedle, $sList): int {
            $iPosition = array_search((string) $sNeedle, explode(',', (string) $sList), true);

            return $iPosition === false ? 0 : $iPosition + 1;
        });
    }

    public function testPhoneWithTheRightPasswordResolvesTheAccount()
    {
        $obUser = $this->createUser('phone-login@nc.test', '26111222');

        $mResult = $this->fire(['email' => '26 111 222', 'password' => self::PASSWORD]);

        $this->assertInstanceOf(User::class, $mResult);
        $this->assertSame($obUser->id, $mResult->id);
    }

    public function testNationalNumberMatchesTheStoredVariantList()
    {
        $obUser = $this->createUser('phone-list@nc.test', '+37126111222,26111222');

        $this->assertSame($obUser->id, $this->fire(['email' => '26 111-222', 'password' => self::PASSWORD])->id);
    }

    public function testWrongPasswordFailsTheAttempt()
    {
        $this->createUser('phone-wrong@nc.test', '26111222');

        $this->assertFalse($this->fire(['email' => '26111222', 'password' => 'nope']));
    }

    public function testUnknownPhoneFailsTheAttempt()
    {
        $this->assertFalse($this->fire(['email' => '26999999', 'password' => self::PASSWORD]));
    }

    public function testSharedNumberSignsInTheAccountWhosePasswordMatches()
    {
        $this->createUser('shared-a@nc.test', '26111222', 'OtherPass123');
        $obSecond = $this->createUser('shared-b@nc.test', '26111222');

        $this->assertSame($obSecond->id, $this->fire(['email' => '26111222', 'password' => self::PASSWORD])->id);
    }

    public function testEmailAndShortOrEmptyInputAreLeftToRainLab()
    {
        $this->createUser('email-login@nc.test', '26111222');

        $this->assertNull($this->fire(['email' => 'email-login@nc.test', 'password' => self::PASSWORD]));
        $this->assertNull($this->fire(['email' => '26111222', 'password' => '']));
        $this->assertFalse($this->fire(['email' => '12345', 'password' => self::PASSWORD]), 'too short to look up is still not a login');
    }

    public function testLooksLikePhone()
    {
        $obHandler = new PhoneLoginHandler();

        $this->assertTrue($obHandler->looksLikePhone('+371 (26) 111-222'));
        $this->assertFalse($obHandler->looksLikePhone('anna@nc.test'));
        $this->assertFalse($obHandler->looksLikePhone('26111222x'));
        $this->assertFalse($obHandler->looksLikePhone(''));
    }

    /**
     * @param array $arInput
     * @return mixed first non-null listener result, as RainLab reads it
     */
    protected function fire(array $arInput)
    {
        return Event::fire(PhoneLoginHandler::EVENT_BEFORE_AUTHENTICATE, [null, $arInput], true);
    }

    /**
     * @param string $sEmail
     * @param string $sPhoneShort comma list as the model derives it
     * @param string $sPassword
     * @return User
     */
    protected function createUser(string $sEmail, string $sPhoneShort, string $sPassword = self::PASSWORD): User
    {
        $obUser = User::create([
            'email'                 => $sEmail,
            'first_name'            => 'Anna',
            'password'              => $sPassword,
            'password_confirmation' => $sPassword,
        ]);
        DB::table('users')->where('id', $obUser->id)->update(['phone' => $sPhoneShort, 'phone_short' => $sPhoneShort]);

        return $obUser;
    }
}
