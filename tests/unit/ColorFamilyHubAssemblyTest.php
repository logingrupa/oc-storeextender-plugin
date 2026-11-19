<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyHubReader;

/**
 * The assembly rule behind the collections hub - no DB, no stores.
 *
 * Three promises are tested here because the hub depends on all three: a chip
 * never promises shades the grid cannot draw, collections come before colors
 * whatever order the taxonomy hands over, and the thin-page threshold decides
 * indexability without deciding existence.
 */
class ColorFamilyHubAssemblyTest extends TestCase
{
    const SHADE_THRESHOLD = 8;

    /**
     * @return array taxonomy entries shaped like ColorFamilyMatcher::families() returns them
     */
    protected function makeFamilyMap(): array
    {
        return [
            'red'   => ['name' => 'Sarkani', 'family' => 'Red', 'hex' => '#d32f2f', 'kind' => 'color', 'gradient' => null],
            'disco' => [
                'name'     => 'DISCO',
                'family'   => 'DISCO',
                'hex'      => '#9e9e9e',
                'kind'     => 'collection',
                'gradient' => ['type' => 'linear', 'angle' => 90, 'stops' => ['#ff2e88', '#ffe600']],
            ],
            'black' => ['name' => 'Melni', 'family' => 'Black', 'hex' => '#212121', 'kind' => 'color', 'gradient' => null],
            'gold'  => ['name' => 'Zelta', 'family' => 'Gold', 'hex' => '#d4af37', 'kind' => 'color', 'gradient' => null],
        ];
    }

    /**
     * @return array live shade ids per family slug
     */
    protected function makeOfferIdMap(): array
    {
        return [
            'red'   => range(1, 97),
            'disco' => range(200, 229),
            'black' => [300, 301, 302],
            'gold'  => [],
        ];
    }

    public function testCollectionsComeBeforeColors()
    {
        $arFamilyList = ColorFamilyHubReader::assembleFamilyList(
            $this->makeFamilyMap(),
            $this->makeOfferIdMap(),
            self::SHADE_THRESHOLD
        );

        $this->assertSame(
            ['disco', 'red', 'black'],
            array_column($arFamilyList, 'slug'),
            'collections lead the hub, colors keep taxonomy order behind them'
        );
    }

    public function testAFamilyWithNoLiveShadeGetsNoChip()
    {
        $arFamilyList = ColorFamilyHubReader::assembleFamilyList(
            $this->makeFamilyMap(),
            $this->makeOfferIdMap(),
            self::SHADE_THRESHOLD
        );

        $this->assertNotContains('gold', array_column($arFamilyList, 'slug'), 'Gold carries zero shades, so it has no page to link to');
    }

    public function testThresholdDecidesIndexabilityNotExistence()
    {
        $arFamilyList = ColorFamilyHubReader::assembleFamilyList(
            $this->makeFamilyMap(),
            $this->makeOfferIdMap(),
            self::SHADE_THRESHOLD
        );
        $arBySlug = array_column($arFamilyList, null, 'slug');

        $this->assertArrayHasKey('black', $arBySlug, 'a thin family is still listed');
        $this->assertSame(3, $arBySlug['black']['count']);
        $this->assertFalse($arBySlug['black']['bIsIndexable'], '3 shades is under the threshold');
        $this->assertTrue($arBySlug['red']['bIsIndexable']);
    }

    public function testThresholdIsInclusive()
    {
        $arFamilyList = ColorFamilyHubReader::assembleFamilyList(
            ['teal' => ['name' => 'Zilganzaļi', 'family' => 'Teal', 'hex' => '#00897b', 'kind' => 'color']],
            ['teal' => range(1, self::SHADE_THRESHOLD)],
            self::SHADE_THRESHOLD
        );

        $this->assertTrue($arFamilyList[0]['bIsIndexable'], 'exactly the threshold is enough');
    }

    public function testAGradientFamilyDrawsItsGradientAndACollectionKeepsItsKind()
    {
        $arFamilyList = ColorFamilyHubReader::assembleFamilyList(
            $this->makeFamilyMap(),
            $this->makeOfferIdMap(),
            self::SHADE_THRESHOLD
        );
        $arDisco = array_column($arFamilyList, null, 'slug')['disco'];

        $this->assertSame('collection', $arDisco['kind']);
        $this->assertSame('linear-gradient(90deg, #ff2e88, #ffe600)', $arDisco['background']);
        $this->assertSame('/collections/disco', $arDisco['path']);
    }

    public function testPathsCarryTheHubSegment()
    {
        $this->assertSame('/collections', ColorFamilyHubReader::hubPath());
        $this->assertSame('/collections/magnetic-disco', ColorFamilyHubReader::familyPath('magnetic-disco'));
    }
}
