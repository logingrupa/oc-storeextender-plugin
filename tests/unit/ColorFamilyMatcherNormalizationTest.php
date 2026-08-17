<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyMatcher;

/**
 * Pure normalization + display resolution of ColorFamilyMatcher: no DB, no
 * cache. The index shape mirrors what buildIndex() produces - terms and
 * localized names per family slug - so what these tests prove is exactly
 * what families() resolves for the pill row.
 */
class ColorFamilyMatcherNormalizationTest extends TestCase
{
    /**
     * @param array $arRawTermList
     * @return array normalized, like buildIndex() stores them
     */
    protected function makeTermList(array $arRawTermList): array
    {
        return array_map(function ($sTerm) {
            return ColorFamilyMatcher::normalizeTerm($sTerm);
        }, $arRawTermList);
    }

    protected function makeIndex(): array
    {
        return [
            'red' => [
                // names in every locale + the lv synonyms families.json ships
                'terms'  => $this->makeTermList(['Sarkans', 'Red', 'красный', 'sarkana', 'sarkanā']),
                'names'  => ['lv' => 'Sarkans', 'en' => 'Red', 'ru' => 'Красный'],
                'family' => 'Red',
                'hex'    => '#d32f2f',
            ],
            'blue' => [
                'terms'  => $this->makeTermList(['Zils', 'Blue']),
                'names'  => ['lv' => 'Zils', 'en' => 'Blue', 'ru' => 'Синий'],
                'family' => 'Blue',
                'hex'    => '#1976d2',
            ],
        ];
    }

    public function testNormalizeTermTransliteratesAndLowercases()
    {
        $this->assertSame('sarkana', ColorFamilyMatcher::normalizeTerm('Sarkanā'));
        $this->assertSame('krasnyj', ColorFamilyMatcher::normalizeTerm('Красный'));
    }

    public function testResolveEntriesPicksRequestedLocaleName()
    {
        $arResolved = ColorFamilyMatcher::resolveEntries($this->makeIndex(), 'ru');

        $this->assertSame(['red', 'blue'], array_keys($arResolved));
        $this->assertSame('Красный', $arResolved['red']['name']);
        $this->assertSame('#d32f2f', $arResolved['red']['hex']);
    }

    public function testResolveEntriesCarriesTheTermListForTheClientFilter()
    {
        $arResolved = ColorFamilyMatcher::resolveEntries($this->makeIndex(), 'lv');

        $this->assertContains('sarkans', $arResolved['red']['terms'], 'the pill filter vocabulary must ride along');
        $this->assertSame([], ColorFamilyMatcher::resolveEntries([
            'red' => ['names' => ['lv' => 'Sarkans'], 'family' => 'Red', 'hex' => null],
        ], 'lv')['red']['terms'], 'entries without terms resolve to an empty list, never an error');
    }

    public function testResolveEntriesFallsBackToDefaultLocaleThenFamily()
    {
        $arIndex = [
            'red'  => ['names' => ['lv' => 'Sarkans'], 'family' => 'Red', 'hex' => null],
            'gold' => ['names' => [], 'family' => 'Gold', 'hex' => null],
        ];

        $arResolved = ColorFamilyMatcher::resolveEntries($arIndex, 'de');

        $this->assertSame('Sarkans', $arResolved['red']['name'], 'missing locale falls back to the default locale');
        $this->assertSame('Gold', $arResolved['gold']['name'], 'no names at all falls back to the raw family name');
    }

    public function testResolveEntriesPrefersEnglishOverDefaultForUnknownLocale()
    {
        $arIndex = [
            'red' => ['names' => ['en' => 'Red', 'lv' => 'Sarkans', 'ru' => 'Красный'], 'family' => 'red', 'hex' => null],
        ];

        $arResolved = ColorFamilyMatcher::resolveEntries($arIndex, 'nb-no');

        $this->assertSame('Red', $arResolved['red']['name'], 'a locale outside the export reads English before Latvian');
    }
}
