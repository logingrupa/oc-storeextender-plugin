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
