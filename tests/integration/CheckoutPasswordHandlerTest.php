<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Validator;
use Cms\Classes\ComponentBase;
use RainLab\User\Models\Setting;
use Lovata\OrdersShopaholic\Components\MakeOrder;

/**
 * The MakeOrder onCreate hook fills a policy-compliant password exactly where
 * MakeOrder::createUser would fall back to str_random(8): a non-empty user array whose
 * password is empty(). Fired through the component's own before-handler event.
 */
class CheckoutPasswordHandlerTest extends StoreExtenderUserPluginTestCase
{
    /**
     * RainLab.User only: the policy is its Setting model.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
    }

    /**
     * @param array $arUserData
     * @return void
     */
    #[DataProvider('provideEmptyPassword')]
    public function testEmptyPasswordIsFilledAndOtherKeysKept($arUserData)
    {
        request()->replace(['user' => $arUserData]);

        $this->fireBeforeHandler(new MakeOrder(), 'onCreate');

        $arFilledUser = request()->input('user');
        $sPassword = (string) ($arFilledUser['password'] ?? '');
        $this->assertNotSame('', $sPassword);
        $this->assertFalse(Validator::make(['password' => $sPassword], ['password' => [Setting::makePasswordRule()]])->fails(), $sPassword);
        unset($arFilledUser['password'], $arUserData['password']);
        $this->assertSame($arUserData, $arFilledUser);
    }

    /**
     * @return array
     */
    public static function provideEmptyPassword()
    {
        return [
            'empty string' => [['email' => 'guest@example.com', 'name' => 'Anna', 'password' => '']],
            'missing key'  => [['email' => 'guest@example.com', 'name' => 'Anna']],
            'zero string'  => [['email' => 'guest@example.com', 'name' => 'Anna', 'password' => '0']],
            'null'         => [['email' => 'guest@example.com', 'name' => 'Anna', 'password' => null]],
        ];
    }

    /**
     * A typed password, even whitespace, is MakeOrder's to keep and RainLab's to judge.
     * @param string $sTypedPassword
     * @return void
     */
    #[DataProvider('provideTypedPassword')]
    public function testTypedPasswordIsUntouched($sTypedPassword)
    {
        request()->replace(['user' => ['email' => 'guest@example.com', 'password' => $sTypedPassword]]);

        $this->fireBeforeHandler(new MakeOrder(), 'onCreate');

        $this->assertSame($sTypedPassword, request()->input('user.password'));
    }

    /**
     * @return array
     */
    public static function provideTypedPassword()
    {
        return [
            'typed'      => ['Probe12345'],
            'whitespace' => [' '],
        ];
    }

    /**
     * No user payload means MakeOrder creates no account; a password alone must not start one.
     * @param array $arInput
     * @return void
     */
    #[DataProvider('provideNoUserPayload')]
    public function testMissingOrEmptyUserPayloadIsUntouched($arInput)
    {
        request()->replace($arInput);

        $this->fireBeforeHandler(new MakeOrder(), 'onCreate');

        $this->assertSame($arInput, request()->all());
    }

    /**
     * @return array
     */
    public static function provideNoUserPayload()
    {
        return [
            'no user key' => [['order' => ['payment_method_id' => 1]]],
            'empty array' => [['user' => []]],
            'scalar'      => [['user' => 'guest@example.com']],
        ];
    }

    /**
     * @return void
     */
    public function testOtherHandlerIsUntouched()
    {
        request()->replace(['user' => ['email' => 'guest@example.com', 'password' => '']]);

        $this->fireBeforeHandler(new MakeOrder(), 'onGetData');

        $this->assertSame('', request()->input('user.password'));
    }

    /**
     * @return void
     */
    public function testOtherComponentOnCreateIsUntouched()
    {
        request()->replace(['user' => ['email' => 'guest@example.com', 'password' => '']]);
        $obOtherComponent = new class extends ComponentBase {
            public function componentDetails()
            {
                return ['name' => 'Other', 'description' => ''];
            }

            public function onCreate()
            {
                return null;
            }
        };

        $this->fireBeforeHandler($obOtherComponent, 'onCreate');

        $this->assertSame('', request()->input('user.password'));
    }

    /**
     * A non-null answer would halt the event and replace the order response.
     * @return void
     */
    public function testListenerReturnsNullSoTheHandlerRuns()
    {
        request()->replace(['user' => ['email' => 'guest@example.com', 'password' => '']]);

        $this->assertNull((new MakeOrder())->fireSystemEvent('cms.component.beforeRunAjaxHandler', ['onCreate']));
        $this->assertNotSame('', request()->input('user.password'), 'the listener ran');
    }

    /**
     * @param ComponentBase $obComponent
     * @param string        $sHandler
     * @return void
     */
    protected function fireBeforeHandler($obComponent, $sHandler)
    {
        $obComponent->fireSystemEvent('cms.component.beforeRunAjaxHandler', [$sHandler]);
    }
}
