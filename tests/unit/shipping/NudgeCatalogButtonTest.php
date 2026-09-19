<?php namespace Logingrupa\StoreExtender\Tests\Unit\Shipping;

use Logingrupa\StoreExtender\Classes\Shipping\NudgeCatalogButton;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The window comes raw from a settings form: strings, empty values, a
 * minimum typed above the maximum. Anything that is not a usable window
 * switches the button off rather than showing it everywhere.
 */
class NudgeCatalogButtonTest extends TestCase
{
    public function testSettingsStringsBecomeTheWindow()
    {
        $this->assertSame(['min' => 1.0, 'max' => 13.0], NudgeCatalogButton::window('1', '13'));
        $this->assertSame(['min' => 10.0, 'max' => 150.5], NudgeCatalogButton::window(10, '150.50'));
    }

    public function testEmptyMinimumMeansFromZero()
    {
        $this->assertSame(['min' => 0.0, 'max' => 13.0], NudgeCatalogButton::window(null, 13));
        $this->assertSame(['min' => 0.0, 'max' => 13.0], NudgeCatalogButton::window('', '13'));
    }

    #[DataProvider('unusableWindowProvider')]
    public function testUnusableWindowSwitchesTheButtonOff($mMinMissing, $mMaxMissing)
    {
        $this->assertNull(NudgeCatalogButton::window($mMinMissing, $mMaxMissing));
    }

    public static function unusableWindowProvider(): array
    {
        return [
            'empty maximum'         => [1, null],
            'zero maximum'          => [1, '0'],
            'text maximum'          => [1, 'abc'],
            'negative minimum'      => [-1, 13],
            'minimum above maximum' => [14, 13],
        ];
    }

    public function testButtonShowsOnlyInsideTheWindowEdgesIncluded()
    {
        $arWindow = ['min' => 1.0, 'max' => 13.0];

        $this->assertTrue(NudgeCatalogButton::isShown(1.0, $arWindow));
        $this->assertTrue(NudgeCatalogButton::isShown(4.5, $arWindow));
        $this->assertTrue(NudgeCatalogButton::isShown(13.0, $arWindow));
        $this->assertFalse(NudgeCatalogButton::isShown(0.99, $arWindow));
        $this->assertFalse(NudgeCatalogButton::isShown(13.01, $arWindow));
        $this->assertFalse(NudgeCatalogButton::isShown(0.0, $arWindow));
    }
}
