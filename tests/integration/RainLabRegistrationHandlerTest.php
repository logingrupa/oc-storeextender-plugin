<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

use Logingrupa\StoreExtender\Classes\Event\User\RainLabRegistrationHandler;

/**
 * Registration through the real rainlab.user.beforeRegister seam, fired the way
 * RainLab's Registration component fires it (halt = true, listener's user wins).
 * RainLab's own createNewUser() whitelists four fields and requires first_name;
 * the shop form posts neither name and does post phone and property[...].
 */
class RainLabRegistrationHandlerTest extends StoreExtenderUserPluginTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
    }

    protected function register(array $arInput)
    {
        return Event::fire(RainLabRegistrationHandler::EVENT_BEFORE_REGISTER, [null, $arInput], true);
    }

    protected function shopFormPost(array $arOverrideList = [])
    {
        return array_merge([
            'email'                 => 'reg-a@nc.test',
            'password'              => 'Probe12345',
            'password_confirmation' => 'Probe12345',
            'phone'                 => '+371 26 111 222',
            'property'              => ['security' => '5', 'school-name' => ''],
            'redirect'              => 'index',
        ], $arOverrideList);
    }

    public function testPhoneAndPropertySurviveRegistration()
    {
        $obUser = $this->register($this->shopFormPost());

        $this->assertNotNull($obUser, 'the beforeRegister listener is not wired');
        $this->assertTrue($obUser->exists);
        $this->assertSame('reg-a@nc.test', $obUser->email);
        $this->assertSame('+371 26 111 222', $obUser->getAttributes()['phone']);
        $this->assertSame('+37126111222', $obUser->getAttributes()['phone_short']);
        $this->assertSame('5', $obUser->property['security']);
        $this->assertTrue(Hash::check('Probe12345', $obUser->password));
    }

    public function testFormWithoutAnyNameRegisters()
    {
        $obUser = $this->register($this->shopFormPost());

        $this->assertNull($obUser->first_name, 'the shop form posts no name and that must stay legal');
    }

    public function testPostedNameLandsOnFirstName()
    {
        $obUser = $this->register($this->shopFormPost(['name' => 'Anna', 'last_name' => 'Berzina']));

        $this->assertSame('Anna', $obUser->first_name);
        $this->assertSame('Berzina', $obUser->last_name);
    }

    public function testFieldsOutsideTheWhitelistAreDropped()
    {
        // "redirect" rides every post; a mass-assigned unknown column would throw on
        // save, and nothing outside FIELD_LIST may reach the account
        $obUser = $this->register($this->shopFormPost(['is_mail_blocked' => 1]));

        $this->assertSame(0, (int) $obUser->is_mail_blocked);
    }

    public function testRegistrationStampsBothIpAddressColumns()
    {
        $obUser = $this->register($this->shopFormPost(['email' => 'reg-ip@nc.test']));

        $this->assertNotEmpty($obUser->created_ip_address);
        $this->assertSame($obUser->created_ip_address, $obUser->last_ip_address);
    }

    public function testMissingPasswordConfirmationIsDefaulted()
    {
        $arInput = $this->shopFormPost(['email' => 'reg-b@nc.test']);
        unset($arInput['password_confirmation']);

        $obUser = $this->register($arInput);

        $this->assertTrue($obUser->exists);
    }

    public function testWrongSecurityAnswerIsRejectedUnderTheFormsKey()
    {
        try {
            $this->register($this->shopFormPost(['property' => ['security' => '4']]));
            $this->fail('a wrong security answer must not create an account');
        } catch (ValidationException $obException) {
            // Keyed property.security so the form's data-validate-for element renders it
            $this->assertArrayHasKey('property.security', $obException->errors());
        }
    }

    public function testMissingSecurityAnswerIsRejected()
    {
        $this->expectException(ValidationException::class);

        $this->register($this->shopFormPost(['property' => []]));
    }
}
