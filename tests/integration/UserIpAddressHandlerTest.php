<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\Request;

use Logingrupa\StoreExtender\Classes\Event\User\UserIpAddressHandler;

/**
 * The rainlab.user.login seam is the only writer of last_ip_address after
 * registration; RainLab's own touchIpAddress() has no caller inside the plugin.
 * AccountCartIdentityHandler shares the seam, hence the cart tables.
 */
class UserIpAddressHandlerTest extends StoreExtenderUserPluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
        $this->createCartTables();
    }

    public function tearDown(): void
    {
        $this->dropCartTables();

        parent::tearDown();
    }

    public function testLoginTouchesLastIpAddress()
    {
        $obUser = \RainLab\User\Models\User::create([
            'email'                 => 'login-ip@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
        $this->assertNull($obUser->last_ip_address);

        Event::fire(UserIpAddressHandler::EVENT_LOGIN, [$obUser]);

        $this->assertSame(Request::ip(), $obUser->fresh()->last_ip_address);
    }
}
