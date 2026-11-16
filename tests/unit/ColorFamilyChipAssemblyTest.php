<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\ColorFamilyHelper;

/**
 * Pure chip assembly rule of ColorFamilyHelper::assembleChipList - no DB,
 * no stores. The theme grid intersects a family's offers with the active
 * offer list before drawing, so the chip count must be that intersect, and
 * the zero-count drop must run AFTER it: a chip is a promise of results.
 */
class ColorFamilyChipAssemblyTest extends TestCase
{
    const CATALOG_URL = 'https://shop.example/catalog';

    /**
     * @return array family display map shaped like resolveEntries() returns it
     */
    protected function makeFamilyMap(): array
    {
        return [
            'red'  => ['name' => 'Sarkans', 'hex' => '#d32f2f', 'terms' => ['sarkans', 'red', 'красный']],
            'blue' => ['name' => 'Zils', 'hex' => '#1976d2', 'terms' => ['zils', 'blue']],
        ];
    }

    public function testInactiveOfferDoesNotCount()
    {
        $arChipList = ColorFamilyHelper::assembleChipList(
            $this->makeFamilyMap(),
            ['red' => [1, 2, 3], 'blue' => [4]],
            [1, 3, 4, 99],
            self::CATALOG_URL
        );

        $this->assertCount(2, $arChipList);
        $this->assertSame('red', $arChipList[0]['slug']);
        $this->assertSame(2, $arChipList[0]['count'], 'the inactive offer 2 must not count');
        $this->assertSame(self::CATALOG_URL.'?color=red', $arChipList[0]['url']);
        $this->assertSame('sarkans red красный', $arChipList[0]['terms'], 'the vocabulary must ship space-joined');
    }

    public function testAllInactiveFamilyDropsAfterTheIntersect()
    {
        $arChipList = ColorFamilyHelper::assembleChipList(
            $this->makeFamilyMap(),
            ['red' => [1], 'blue' => [4, 5]],
            [1],
            self::CATALOG_URL
        );

        $this->assertCount(1, $arChipList, 'a family whose offers are all inactive must drop');
        $this->assertSame('red', $arChipList[0]['slug']);
    }

    /** CHIP-1 */
    public function testAChipCarriesTheGradientOrTheHexAsItsBackground()
    {
        $arFamilyMap = $this->makeFamilyMap();
        $arFamilyMap['disco'] = [
            'name'     => 'DISCO',
            'hex'      => '#ff00aa',
            'terms'    => ['disco'],
            'gradient' => ['#ff00aa', '#00e5ff', '#ffd400'],
        ];

        $arChipList = ColorFamilyHelper::assembleChipList(
            $arFamilyMap,
            ['red' => [1], 'blue' => [2], 'disco' => [1, 2]],
            [1, 2],
            self::CATALOG_URL
        );

        $this->assertSame('#d32f2f', $arChipList[0]['background']);
        $this->assertSame('#1976d2', $arChipList[1]['background']);
        $this->assertSame('linear-gradient(90deg, #ff00aa, #00e5ff, #ffd400)', $arChipList[2]['background']);
        $this->assertSame('#ff00aa', $arChipList[2]['hex'], 'hex stays beside the gradient');
        $this->assertSame([1, 1, 2], array_column($arChipList, 'count'), 'counts are per pill');
    }

    public function testTheBackgroundBuilderOnlyDrawsStrictHexStops()
    {
        $this->assertSame('#abc123', ColorFamilyHelper::buildSwatchBackground('#abc123', null));
        $this->assertSame('#abc123', ColorFamilyHelper::buildSwatchBackground('#abc123', ['#fff', 'zz']));
        $this->assertSame('#abc123', ColorFamilyHelper::buildSwatchBackground('#abc123', ['#ff00aa']));
        $this->assertNull(ColorFamilyHelper::buildSwatchBackground('url(x)', null));
        $this->assertSame(
            'linear-gradient(90deg, #ff00aa, #00e5ff)',
            ColorFamilyHelper::buildSwatchBackground(null, ['#ff00aa', '#00e5ff'])
        );
    }

    /** ORDER-1 */
    public function testACollectionPageOrdersShadesByNaturalShadeCodeNullsLast()
    {
        $arShadeCodeByOfferId = [1 => 'DISCO 13H', 3 => 'DISCO 02', 4 => 'DISCO 13F', 5 => 'DISCO 10'];

        $arOrdered = ColorFamilyHelper::sortOfferIdsByShadeCode([1, 2, 3, 4, 5], $arShadeCodeByOfferId, []);

        $this->assertSame([3, 5, 4, 1, 2], $arOrdered, 'DISCO 02, 10, 13F, 13H, then the shade with no code');
    }

    public function testEqualShadeCodesFallBackToConfidenceThenIncomingOrder()
    {
        $arShadeCodeByOfferId = [1 => 'DISCO 05', 2 => 'DISCO 05', 3 => 'DISCO 05', 4 => 'DISCO 05'];
        $arConfidenceByOfferId = [2 => 0.4, 3 => 0.9];

        $arOrdered = ColorFamilyHelper::sortOfferIdsByShadeCode([1, 2, 3, 4], $arShadeCodeByOfferId, $arConfidenceByOfferId);

        $this->assertSame([3, 2, 1, 4], $arOrdered, 'confidence desc, unscored keep the incoming order');
    }

    public function testFamilyWithoutAnyOfferListDrops()
    {
        $arChipList = ColorFamilyHelper::assembleChipList(
            $this->makeFamilyMap(),
            ['red' => [1]],
            [1],
            self::CATALOG_URL
        );

        $this->assertCount(1, $arChipList, 'a family the filter store knows nothing about must drop');
        $this->assertSame('red', $arChipList[0]['slug']);
    }
}
