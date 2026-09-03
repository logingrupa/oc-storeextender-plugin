<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Logingrupa\StoreExtender\Classes\Helper\ThemeUserBinder;

/**
 * Minimal layout double: the binder only touches the array bag, one dynamic
 * property read and addComponent().
 */
class FakeBinderLayout implements ArrayAccess
{
    public $arBag = [];
    public $arComponentList = [];
    public $iAddComponentCallCount = 0;

    /** @var object|null pre-declared component instance, keyed by alias */
    public $arDeclaredList = [];

    public function __get($sName)
    {
        return $this->arDeclaredList[$sName] ?? null;
    }

    public function __isset($sName)
    {
        return isset($this->arDeclaredList[$sName]);
    }

    public function addComponent($sClass, $sAlias, $arProperties)
    {
        $this->iAddComponentCallCount++;
        $this->arComponentList[$sAlias] = $sClass;

        return new FakeSessionComponent();
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($sKey, $value)
    {
        $this->arBag[$sKey] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($sKey)
    {
        return $this->arBag[$sKey] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($sKey)
    {
        return isset($this->arBag[$sKey]);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($sKey)
    {
        unset($this->arBag[$sKey]);
    }
}

/** Answers the RainLab Session component's user() shape. */
class FakeSessionComponent
{
    public $obFakeUser;

    public function __construct()
    {
        $this->obFakeUser = (object) ['email' => 'binder-probe@nc.test'];
    }

    public function user()
    {
        return $this->obFakeUser;
    }
}

class ThemeUserBinderTest extends StoreExtenderUserPluginTestCase
{
    public function testBindsTheSessionComponentAndLogoutHandler()
    {
        $obLayout = new FakeBinderLayout();

        ThemeUserBinder::bind($obLayout);

        $this->assertSame(ThemeUserBinder::RAINLAB_LOGOUT_HANDLER, $obLayout['sLogoutHandler']);
        $this->assertSame(1, $obLayout->iAddComponentCallCount);
        $this->assertSame(
            ThemeUserBinder::RAINLAB_COMPONENT_CLASS,
            $obLayout->arComponentList[ThemeUserBinder::RAINLAB_COMPONENT_ALIAS]
        );

        // obUser comes from the component the binder registered
        $this->assertSame('binder-probe@nc.test', $obLayout['obUser']->email);
    }

    public function testReusesAnIniDeclaredComponentInstance()
    {
        $obLayout = new FakeBinderLayout();

        $obDeclared = new FakeSessionComponent();
        $obDeclared->obFakeUser = (object) ['email' => 'declared-instance@nc.test'];
        $obLayout->arDeclaredList[ThemeUserBinder::RAINLAB_COMPONENT_ALIAS] = $obDeclared;

        ThemeUserBinder::bind($obLayout);

        $this->assertSame(0, $obLayout->iAddComponentCallCount, 'a configured instance must not be shadowed by a bare one');
        $this->assertSame('declared-instance@nc.test', $obLayout['obUser']->email);
    }

    public function testRejectsAnEmptyLayout()
    {
        $this->expectException(InvalidArgumentException::class);

        ThemeUserBinder::bind(null);
    }
}
