<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use Lovata\Toolbox\Classes\Helper\UserHelper;
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

/** Answers both plugin shapes: RainLab's user() and Buddies' get(). */
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

    public function get()
    {
        return $this->obFakeUser;
    }
}

class ThemeUserBinderTest extends StoreExtenderUserPluginTestCase
{
    protected function isBuddies()
    {
        return UserHelper::instance()->getPluginName() == ThemeUserBinder::BUDDIES_PLUGIN_NAME;
    }

    public function testBindsTheActivePluginsComponentAndLogoutHandler()
    {
        $obLayout = new FakeBinderLayout();

        ThemeUserBinder::bind($obLayout);

        $sExpectedHandler = $this->isBuddies()
            ? ThemeUserBinder::BUDDIES_LOGOUT_HANDLER
            : ThemeUserBinder::RAINLAB_LOGOUT_HANDLER;
        $sExpectedAlias = $this->isBuddies()
            ? ThemeUserBinder::BUDDIES_COMPONENT_ALIAS
            : ThemeUserBinder::RAINLAB_COMPONENT_ALIAS;
        $sExpectedClass = $this->isBuddies()
            ? ThemeUserBinder::BUDDIES_COMPONENT_CLASS
            : ThemeUserBinder::RAINLAB_COMPONENT_CLASS;

        $this->assertSame($sExpectedHandler, $obLayout['sLogoutHandler']);
        $this->assertSame(1, $obLayout->iAddComponentCallCount);
        $this->assertSame($sExpectedClass, $obLayout->arComponentList[$sExpectedAlias]);

        // obUser comes from the component the binder registered
        $this->assertSame('binder-probe@nc.test', $obLayout['obUser']->email);
    }

    public function testReusesAnIniDeclaredComponentInstance()
    {
        $obLayout = new FakeBinderLayout();
        $sAlias = $this->isBuddies()
            ? ThemeUserBinder::BUDDIES_COMPONENT_ALIAS
            : ThemeUserBinder::RAINLAB_COMPONENT_ALIAS;

        $obDeclared = new FakeSessionComponent();
        $obDeclared->obFakeUser = (object) ['email' => 'declared-instance@nc.test'];
        $obLayout->arDeclaredList[$sAlias] = $obDeclared;

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
