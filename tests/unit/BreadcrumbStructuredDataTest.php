<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\BreadcrumbStructuredData;

/**
 * The schema.org BreadcrumbList block of the phone product page (/p2).
 *
 * The legacy microdata partial numbered Home and the first crumb both as
 * position 1. The JSON-LD builder numbers Home 1 and every crumb after it,
 * and it escapes the angle brackets of a crumb name so an editor-written
 * category name cannot close the script element it is printed in.
 *
 * Pure input to output, no app boot: the builder reads only its arguments.
 * The JSON escape needles are assembled from chr(92) so the source holds no
 * backslash sequence a tool or editor could unescape.
 */
class BreadcrumbStructuredDataTest extends TestCase
{
    const HOME_NAME = 'Sakums';
    const HOME_URL = 'https://nc.test/lv';

    /**
     * @param string $sHex four hex digits
     * @return string the JSON unicode escape of that code point
     */
    private function unicodeEscape(string $sHex): string
    {
        return chr(92) . 'u' . $sHex;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function twoCrumbs(): array
    {
        return [
            ['title' => 'Gel polish', 'url' => 'https://nc.test/lv/c/gel'],
            ['title' => 'Gellaka 12ml', 'url' => 'https://nc.test/lv/p2/x/'],
        ];
    }

    public function testBuildNumbersHomeFirstAndEveryCrumbAfterIt(): void
    {
        $arData = BreadcrumbStructuredData::build($this->twoCrumbs(), self::HOME_NAME, self::HOME_URL);

        $this->assertSame('https://schema.org', $arData['@context']);
        $this->assertSame('BreadcrumbList', $arData['@type']);
        $this->assertCount(3, $arData['itemListElement']);

        $this->assertSame([1, 2, 3], array_column($arData['itemListElement'], 'position'));
        $this->assertSame(['ListItem', 'ListItem', 'ListItem'], array_column($arData['itemListElement'], '@type'));

        $this->assertSame(self::HOME_NAME, $arData['itemListElement'][0]['name']);
        $this->assertSame(self::HOME_URL, $arData['itemListElement'][0]['item']);
        $this->assertSame('Gel polish', $arData['itemListElement'][1]['name']);
        $this->assertSame('https://nc.test/lv/c/gel', $arData['itemListElement'][1]['item']);
        $this->assertSame('Gellaka 12ml', $arData['itemListElement'][2]['name']);
        $this->assertSame('https://nc.test/lv/p2/x/', $arData['itemListElement'][2]['item']);
    }

    public function testRenderEscapesAScriptBreakOutInACrumbName(): void
    {
        $arCrumbList = [['title' => 'A</script><script>alert(1)</script>', 'url' => 'https://nc.test/lv/c/a']];
        // json_encode escapes the slash as well, so the closing tag is read back as u003C, slash, script, u003E
        $sEscapedClosingTag = $this->unicodeEscape('003C') . chr(92) . '/script' . $this->unicodeEscape('003E');

        $sJson = BreadcrumbStructuredData::render($arCrumbList, self::HOME_NAME, self::HOME_URL);

        $this->assertStringNotContainsString('</script>', $sJson);
        $this->assertStringNotContainsString('<script>', $sJson);
        $this->assertStringContainsString($sEscapedClosingTag, $sJson);
        $this->assertSame($arCrumbList[0]['title'], json_decode($sJson, true)['itemListElement'][1]['name'], 'the name survives the round trip');
    }

    public function testRenderKeepsUtf8NamesUnescaped(): void
    {
        $sJson = BreadcrumbStructuredData::render($this->twoCrumbs(), 'Sākums', self::HOME_URL);

        $this->assertStringContainsString('"Sākums"', $sJson);
        $this->assertStringNotContainsString($this->unicodeEscape('0101'), $sJson);
    }

    public function testAnEmptyCrumbListThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crumb list is empty');

        BreadcrumbStructuredData::build([], self::HOME_NAME, self::HOME_URL);
    }

    public function testAnEmptyTitleThrowsNamingTheIndex(): void
    {
        $arCrumbList = $this->twoCrumbs();
        $arCrumbList[1]['title'] = '  ';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crumb 1');

        BreadcrumbStructuredData::build($arCrumbList, self::HOME_NAME, self::HOME_URL);
    }

    public function testAMissingTitleThrowsNamingTheIndex(): void
    {
        $arCrumbList = [['url' => 'https://nc.test/lv/c/gel']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crumb 0');

        BreadcrumbStructuredData::build($arCrumbList, self::HOME_NAME, self::HOME_URL);
    }

    public function testANonStringUrlThrowsNamingTheIndex(): void
    {
        $arCrumbList = $this->twoCrumbs();
        $arCrumbList[0]['url'] = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crumb 0');

        BreadcrumbStructuredData::build($arCrumbList, self::HOME_NAME, self::HOME_URL);
    }

    public function testARelativeUrlThrowsNamingTheIndex(): void
    {
        $arCrumbList = $this->twoCrumbs();
        $arCrumbList[1]['url'] = '/lv/p2/x/';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('crumb 1');

        BreadcrumbStructuredData::build($arCrumbList, self::HOME_NAME, self::HOME_URL);
    }

    public function testAnEmptyHomeNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('home name is empty');

        BreadcrumbStructuredData::build($this->twoCrumbs(), '', self::HOME_URL);
    }

    public function testARelativeHomeUrlThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('home url');

        BreadcrumbStructuredData::build($this->twoCrumbs(), self::HOME_NAME, '/lv');
    }
}
