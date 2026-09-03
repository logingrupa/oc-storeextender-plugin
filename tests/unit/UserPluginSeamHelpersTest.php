<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;

use Lovata\Toolbox\Classes\Helper\UserHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserGroupHelper;
use Logingrupa\StoreExtender\Classes\Helper\UserPropertyHelper;
use Logingrupa\StoreExtender\Models\UserProperty;

/**
 * Resolution of the group and property model seams. Deterministic here regardless
 * of the host installation's state.
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

    public function testGroupModelResolvesToRainLab()
    {
        $this->assertSame(\RainLab\User\Models\UserGroup::class, UserGroupHelper::instance()->getGroupModel());
    }

    public function testPropertyModelIsThisPluginsOwn()
    {
        // RainLab has no property feature; this plugin supplies the model
        $this->assertSame(UserProperty::class, UserPropertyHelper::instance()->getPropertyModel());
    }

    public function testFindByCodeGuardsEmptyCode()
    {
        $this->assertNull(UserGroupHelper::instance()->findByCode(''));
        $this->assertNull(UserGroupHelper::instance()->findByCode(null));
    }
}
