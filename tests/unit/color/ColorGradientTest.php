<?php namespace Logingrupa\StoreExtender\Tests\Unit\Color;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Color\ColorGradient;

/**
 * The family gradient the color-lab families.json sends: a type (linear,
 * radial, conic), a whole angle 0-359 and 2 to 8 strict #rrggbb stops.
 * Anything else is no gradient, so the flat hex draws.
 */
class ColorGradientTest extends TestCase
{
    /** GRADIENT-1 */
    public function testEachTypeBecomesItsCssGradient()
    {
        $arStops = ['#ff00aa', '#00e5ff', '#ffd400'];

        $this->assertSame('linear-gradient(135deg, #ff00aa, #00e5ff, #ffd400)', ColorGradient::toCss(['type' => 'linear', 'angle' => 135, 'stops' => $arStops]));
        $this->assertSame('radial-gradient(circle, #ff00aa, #00e5ff, #ffd400)', ColorGradient::toCss(['type' => 'radial', 'angle' => 0, 'stops' => $arStops]));
        $this->assertSame('conic-gradient(from 20deg, #ff00aa, #00e5ff, #ffd400)', ColorGradient::toCss(['type' => 'conic', 'angle' => 20, 'stops' => $arStops]));
    }

    public function testNormalizeKeepsTheThreeFieldsAndDropsExtraKeys()
    {
        $arStops = ['#111111', '#222222', '#333333', '#444444', '#555555', '#666666', '#777777', '#888888'];

        $this->assertSame(
            ['type' => 'conic', 'angle' => 359, 'stops' => $arStops],
            ColorGradient::normalize(['stops' => $arStops, 'angle' => 359, 'type' => 'conic', 'shape' => 'circle'])
        );
    }

    /**
     * @return array
     */
    public static function invalidGradientProvider(): array
    {
        $arTwoStops = ['#ff00aa', '#00e5ff'];

        return [
            'null'               => [null],
            'old bare list'      => [$arTwoStops],
            'string'             => ['linear-gradient(90deg, red, blue)'],
            'no stops'           => [['type' => 'linear', 'angle' => 90]],
            'one stop'           => [['type' => 'linear', 'angle' => 90, 'stops' => ['#ff00aa']]],
            'nine stops'         => [['type' => 'linear', 'angle' => 90, 'stops' => array_fill(0, 9, '#111111')]],
            'short hex stop'     => [['type' => 'linear', 'angle' => 90, 'stops' => ['#fff', '#000000']]],
            'css in a stop'      => [['type' => 'linear', 'angle' => 90, 'stops' => ['#ff00aa', 'red); background: url(x']]],
            'keyed stops'        => [['type' => 'linear', 'angle' => 90, 'stops' => ['a' => '#ff00aa', 'b' => '#00e5ff']]],
            'unknown type'       => [['type' => 'spiral', 'angle' => 90, 'stops' => $arTwoStops]],
            'missing angle'      => [['type' => 'conic', 'stops' => $arTwoStops]],
            'angle 360'          => [['type' => 'linear', 'angle' => 360, 'stops' => $arTwoStops]],
            'negative angle'     => [['type' => 'linear', 'angle' => -5, 'stops' => $arTwoStops]],
            'string angle'       => [['type' => 'linear', 'angle' => '90', 'stops' => $arTwoStops]],
            'fraction angle'     => [['type' => 'linear', 'angle' => 22.5, 'stops' => $arTwoStops]],
        ];
    }

    /** GRADIENT-2 */
    #[DataProvider('invalidGradientProvider')]
    public function testAnythingElseIsNoGradient($mGradient)
    {
        $this->assertNull(ColorGradient::normalize($mGradient));
        $this->assertNull(ColorGradient::toCss($mGradient));
    }
}
