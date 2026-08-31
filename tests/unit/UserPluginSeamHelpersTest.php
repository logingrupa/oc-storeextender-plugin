<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserPropertyHelper;
use Logingrupa\StoreExtender\Models\UserProperty;

/**
 * Stubs that pin the plugin-name answer without touching PluginManager. The Singleton
 * trait stores its instance in an inherited static, so instantiating the subclass
 * makes UserHelper::instance() return it until forgetInstance().
 */
class FakeBuddiesUserHelper extends UserHelper
{
    protected function init()
    {
        $this->sPluginName = 'Lovata.Buddies';
    }
}

class FakeRainLabUserHelper extends UserHelper
{
    protected function init()
    {
        $this->sPluginName = 'RainLab.User';
    }
}

/**
 * Resolution of the group and property model seams under BOTH user plugins - the
 * two classes the plugins name differently, deterministic here regardless of which
 * plugin the host installation runs.
 */
class UserPluginSeamHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        UserHelper::forgetInstance();
        UserGroupHelper::forgetInstance();
        UserPropertyHelper::forgetInstance();

        parent::tearDown();
    }

    protected function activatePlugin($sStubClass)
    {
        UserHelper::forgetInstance();
        $sStubClass::instance();
        UserGroupHelper::forgetInstance();
        UserPropertyHelper::forgetInstance();
    }

    public function testGroupModelResolvesPerPlugin()
    {
        $this->activatePlugin(FakeBuddiesUserHelper::class);
        $this->assertSame(\Lovata\Buddies\Models\Group::class, UserGroupHelper::instance()->getGroupModel());

        $this->activatePlugin(FakeRainLabUserHelper::class);
        $this->assertSame(\RainLab\User\Models\UserGroup::class, UserGroupHelper::instance()->getGroupModel());
    }

    public function testPropertyModelResolvesPerPlugin()
    {
        $this->activatePlugin(FakeBuddiesUserHelper::class);
        $this->assertSame(\Lovata\Buddies\Models\Property::class, UserPropertyHelper::instance()->getPropertyModel());

        // RainLab has no property feature; this plugin supplies the model
        $this->activatePlugin(FakeRainLabUserHelper::class);
        $this->assertSame(UserProperty::class, UserPropertyHelper::instance()->getPropertyModel());
    }

    public function testFindByCodeGuardsEmptyCode()
    {
        $this->activatePlugin(FakeRainLabUserHelper::class);

        $this->assertNull(UserGroupHelper::instance()->findByCode(''));
        $this->assertNull(UserGroupHelper::instance()->findByCode(null));
    }
}
