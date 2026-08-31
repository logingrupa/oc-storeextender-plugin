<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use RainLab\User\Models\User;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\Toolbox\Classes\Helper\Users\RainLabUserHelper;
use Logingrupa\StoreExtender\Classes\Helper\RainLabUserHelperFix;

/**
 * The checkout-saving override: Toolbox's RainLabUserHelper::findUserByEmail() calls
 * User::findByEmail(), which RainLab.User 3.5.3 does not define, so every checkout
 * died with BadMethodCallException. The fix is container-bound in Plugin::register(),
 * and these tests go through UserHelper - the seam MakeOrder uses - so they also pin
 * that the binding is actually wired.
 */
class RainLabUserHelperFixTest extends StoreExtenderUserPluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
    }

    public function testContainerServesTheFixForTheToolboxClass()
    {
        $this->assertInstanceOf(RainLabUserHelperFix::class, app(RainLabUserHelper::class));
    }

    public function testFindsARegisteredUserByEmail()
    {
        User::create([
            'email'                 => 'customer@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);

        $obFound = UserHelper::instance()->findUserByEmail('customer@nc.test');

        $this->assertNotNull($obFound, 'checkout lookup by email is broken');
        $this->assertSame('customer@nc.test', $obFound->email);
    }

    public function testExcludesGuestAccounts()
    {
        $obGuest = User::create([
            'email'                 => 'guest@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        $obGuest->is_guest = true;
        $obGuest->save();

        // Buddies had no guest concept, so an order must never attach to one
        $this->assertNull(UserHelper::instance()->findUserByEmail('guest@nc.test'));
    }

    public function testAnswersNullForEmptyAndUnknownEmail()
    {
        $this->assertNull(UserHelper::instance()->findUserByEmail(''));
        $this->assertNull(UserHelper::instance()->findUserByEmail(null));
        $this->assertNull(UserHelper::instance()->findUserByEmail('nobody@nc.test'));
    }
}
