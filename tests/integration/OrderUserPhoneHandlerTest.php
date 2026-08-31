<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Log;

use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderUserPhoneHandler;

/**
 * Stand-in for the user model: records saves and can be told to fail one, so the
 * handler's "a phone update must never roll back a paid order" contract is provable.
 */
class FakePhoneUser
{
    public $id = 42;
    public $phone;
    public $iSaveCount = 0;
    public $bFailSave = false;

    public function __construct($sPhone = null)
    {
        $this->phone = $sPhone;
    }

    public function save()
    {
        $this->iSaveCount++;

        if ($this->bFailSave) {
            throw new Exception('validation failed');
        }

        return true;
    }
}

/** Captures the closure the handler registers, so its return value can be asserted. */
class FakePhoneDispatcher
{
    public $arListenerList = [];

    public function listen($sEvent, $callback)
    {
        $this->arListenerList[$sEvent] = $callback;
    }
}

class OrderUserPhoneHandlerTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    protected function update($arOrderData, $obUser)
    {
        $obMethod = new ReflectionMethod(OrderUserPhoneHandler::class, 'updateUserPhone');
        $obMethod->setAccessible(true);
        $obMethod->invoke(new OrderUserPhoneHandler(), $arOrderData, $obUser);
    }

    public function testOverwritesTheStoredPhoneInsteadOfAppending()
    {
        $obUser = new FakePhoneUser('+37120000001,+37120000002');

        $this->update(['property' => ['phone' => '+37126111222']], $obUser);

        $this->assertSame('+37126111222', $obUser->phone, 'the comma list must be replaced, not extended');
        $this->assertSame(1, $obUser->iSaveCount);
    }

    public function testDoesNotWriteWhenThePhoneIsUnchanged()
    {
        $obUser = new FakePhoneUser('+37126111222');

        $this->update(['property' => ['phone' => '+37126111222']], $obUser);

        $this->assertSame(0, $obUser->iSaveCount);
    }

    public function testDoesNotWriteWhenTheOrderCarriesNoPhone()
    {
        $obUser = new FakePhoneUser('+37126111222');

        $this->update(['property' => ['phone' => '  ']], $obUser);
        $this->update(['property' => []], $obUser);
        $this->update([], $obUser);

        $this->assertSame(0, $obUser->iSaveCount);
        $this->assertSame('+37126111222', $obUser->phone);
    }

    public function testGuestOrderWithoutUserIsIgnored()
    {
        $this->update(['property' => ['phone' => '+37126111222']], null);

        // Reaching this line without a fatal is the assertion
        $this->assertTrue(true);
    }

    public function testFailingUserSaveIsLoggedAndSwallowed()
    {
        $obUser = new FakePhoneUser('+37120000001');
        $obUser->bFailSave = true;

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function ($sMessage) {
                return strpos($sMessage, 'Checkout phone update failed') !== false
                    && strpos($sMessage, '42') !== false;
            });

        $this->update(['property' => ['phone' => '+37126111222']], $obUser);

        $this->assertSame(1, $obUser->iSaveCount, 'the save must have been attempted');
    }

    public function testListenerNeverReturnsAValue()
    {
        // EVENT_UPDATE_ORDER_BEFORE_CREATE fires with halt = true: any non-null
        // return stops the remaining listeners, false cancels the order.
        $obDispatcher = new FakePhoneDispatcher();
        (new OrderUserPhoneHandler())->subscribe($obDispatcher);

        $callback = $obDispatcher->arListenerList[OrderProcessor::EVENT_UPDATE_ORDER_BEFORE_CREATE] ?? null;
        $this->assertNotNull($callback, 'handler is not listening on the before-create event');

        $obUser = new FakePhoneUser('+37120000001');
        $this->assertNull($callback(['property' => ['phone' => '+37126111222']], $obUser));
        $this->assertNull($callback([], null));
    }
}
