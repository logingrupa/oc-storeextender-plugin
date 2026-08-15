<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Color\ColorFamilyMatcher;

/**
 * Pure normalization + matching rule of ColorFamilyMatcher: no DB, no cache.
 * The index shape mirrors what buildIndex() produces - normalized terms per
 * family slug - so what these tests prove is exactly the rule match() runs.
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
                // names in every locale + the lv synonyms families.json ships;
                // the synonym 'sarkanā' is what lets the declined form meet the
                // family after diacritic stripping ('sarkanā' -> 'sarkana')
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

    public function testCaseAndDiacriticVariantsAllMatchTheLatvianTerm()
    {
        $arIndex = $this->makeIndex();

        foreach (['Sarkans', 'sarkans', 'SARKANS', 'sarkanā'] as $sQuery) {
            $arMatched = ColorFamilyMatcher::matchAgainstIndex($sQuery, $arIndex);
            $this->assertArrayHasKey('red', $arMatched, sprintf("query '%s' must match the red family", $sQuery));
            $this->assertArrayNotHasKey('blue', $arMatched, sprintf("query '%s' must not match blue", $sQuery));
        }
    }

    public function testTransliteratedQueryMatchesCyrillicTerm()
    {
        $arIndex = ['red' => ['terms' => $this->makeTermList(['красный'])]];

        $this->assertArrayHasKey('red', ColorFamilyMatcher::matchAgainstIndex('krasnyj', $arIndex));
    }

    public function testCyrillicQueryMatchesCyrillicTerm()
    {
        $arMatched = ColorFamilyMatcher::matchAgainstIndex('красный', $this->makeIndex());

        $this->assertArrayHasKey('red', $arMatched);
    }

    public function testTermMatchesAsWholeWordInsideLongerQuery()
    {
        $arMatched = ColorFamilyMatcher::matchAgainstIndex('gel lak sarkans', $this->makeIndex());

        $this->assertArrayHasKey('red', $arMatched);
    }

    public function testPartialWordDoesNotMatch()
    {
        // a prefix of a term is not the term
        $this->assertSame([], ColorFamilyMatcher::matchAgainstIndex('sar', $this->makeIndex()));
        // a term glued into a longer word is not a whole-word occurrence
        $this->assertSame([], ColorFamilyMatcher::matchAgainstIndex('sarkansgel', $this->makeIndex()));
    }

    public function testQueryBelowMinimumLengthMatchesNothing()
    {
        $this->assertSame([], ColorFamilyMatcher::matchAgainstIndex('sa', $this->makeIndex()));
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
