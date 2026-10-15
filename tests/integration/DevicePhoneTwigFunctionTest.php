<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Classes\Helper\DeviceHint;
use Logingrupa\StoreExtender\Plugin;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The shop, main, catalog_default, content and light layouts pick the phone
 * header through is_phone(). Calling it sets DeviceHint's process-wide flag,
 * which has no reset, so the call runs in its own process.
 */
class DevicePhoneTwigFunctionTest extends StoreExtenderPluginTestCase
{
    /** Reads the markup registration only, no table */
    protected $autoMigrate = false;

    /** @var string iPhone Safari 17, measured against MobileDetect 4.11.0 */
    const UA_IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    public function testIsPhoneIsRegisteredAsTheDeviceHintPhoneAnswer()
    {
        $arFunctionList = $this->functionList();

        $this->assertSame([DeviceHint::class, 'isMobile'], $arFunctionList['is_phone']);
        $this->assertTrue(is_callable($arFunctionList['is_phone']));
    }

    #[RunInSeparateProcess]
    public function testIsPhoneAnswersTrueForAPhoneAndMarksTheHintConsulted()
    {
        request()->headers->set('User-Agent', self::UA_IPHONE_SAFARI);

        $this->assertTrue(call_user_func($this->functionList()['is_phone']));
        $this->assertTrue(DeviceHint::wasConsulted());
    }

    private function functionList(): array
    {
        return (new Plugin(App::make('app')))->registerMarkupTags()['functions'];
    }
}
