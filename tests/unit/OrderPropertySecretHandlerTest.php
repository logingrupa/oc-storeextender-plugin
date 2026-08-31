<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderPropertySecretHandler;

/**
 * Stand-in for the Order model: property is a jsonable attribute, so the handler
 * only ever sees and writes back a plain array.
 */
class FakeOrderProperty
{
    public $iWriteCount = 0;

    /** Raw attribute bag, mirroring the model: property is stored json encoded. */
    protected $arAttributes = ['property' => null];

    public function __get($sName)
    {
        if ($sName == 'property') {
            $sRaw = $this->arAttributes['property'];
            return $sRaw === null ? null : json_decode($sRaw, true);
        }

        return $this->arAttributes[$sName] ?? null;
    }

    /**
     * Reproduces Lovata\Toolbox\Traits\Models\SetPropertyAttributeTrait: assigning
     * to property MERGES into the stored array, so an assignment can never drop a key.
     * The guard has to bypass this, and this fake is what proves it does.
     */
    public function __set($sName, $value)
    {
        if ($sName != 'property') {
            $this->arAttributes[$sName] = $value;
            return;
        }

        $this->iWriteCount++;

        if (empty($value) || !is_array($value)) {
            return;
        }

        $arCurrent = $this->property;
        if (empty($arCurrent)) {
            $arCurrent = [];
        }

        foreach ($value as $sKey => $sValue) {
            $arCurrent[$sKey] = $sValue;
        }

        $this->arAttributes['property'] = json_encode($arCurrent, JSON_UNESCAPED_UNICODE);
    }

    public function getAttributes()
    {
        return $this->arAttributes;
    }

    public function setRawAttributes(array $arAttributes)
    {
        $this->arAttributes = $arAttributes;
    }
}

class OrderPropertySecretHandlerTest extends TestCase
{
    protected function strip($obOrder)
    {
        $obMethod = new ReflectionMethod(OrderPropertySecretHandler::class, 'stripSecretFields');
        $obMethod->setAccessible(true);
        $obMethod->invoke(new OrderPropertySecretHandler(), $obOrder);
    }

    public function testRemovesPassword()
    {
        $obOrder = new FakeOrderProperty();
        $obOrder->property = ['email' => 'a@b.lv', 'password' => 'hunter2'];

        $this->strip($obOrder);

        $this->assertArrayNotHasKey('password', $obOrder->property);
        $this->assertSame('a@b.lv', $obOrder->property['email']);
    }

    public function testRemovesPasswordConfirmation()
    {
        $obOrder = new FakeOrderProperty();
        $obOrder->property = ['password_confirmation' => 'hunter2', 'name' => 'Anna'];

        $this->strip($obOrder);

        $this->assertSame(['name' => 'Anna'], $obOrder->property);
    }

    public function testKeepsEveryOtherFieldIntact()
    {
        $arInput = [
            'name'              => 'Anna',
            'email'             => 'a@b.lv',
            'phone'             => '+371200',
            'password'          => 'hunter2',
            'last_name'         => 'Berzina',
            'account_type'      => 'private',
            'shipping_address2' => 'Riga',
        ];
        $obOrder = new FakeOrderProperty();
        $obOrder->property = $arInput;

        $this->strip($obOrder);

        unset($arInput['password']);
        $this->assertSame($arInput, $obOrder->property);
    }

    public function testDoesNotWriteWhenNoSecretPresent()
    {
        $obOrder = new FakeOrderProperty();
        $obOrder->property = ['email' => 'a@b.lv'];
        $iBefore = $obOrder->iWriteCount;

        $this->strip($obOrder);

        $this->assertSame($iBefore, $obOrder->iWriteCount);
        $this->assertSame(['email' => 'a@b.lv'], $obOrder->property);
    }

    public function testHandlesEmptyProperty()
    {
        $obOrder = new FakeOrderProperty();
        $obOrder->property = [];

        $this->strip($obOrder);

        // The merging mutator ignores an empty assignment, so the attribute is
        // still unset - the guard must simply leave it alone rather than throw.
        $this->assertNull($obOrder->property);
        $this->assertNull($obOrder->getAttributes()['property']);
    }

    public function testHandlesNullProperty()
    {
        $obOrder = new FakeOrderProperty();
        $obOrder->property = null;

        $this->strip($obOrder);

        $this->assertNull($obOrder->property);
    }

    public function testSecretFieldListCoversBothCredentialKeys()
    {
        $this->assertSame(
            ['password', 'password_confirmation'],
            OrderPropertySecretHandler::SECRET_FIELD_LIST
        );
    }
}
