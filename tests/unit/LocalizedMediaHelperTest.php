<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\StoreExtender\Classes\Helper\LocalizedMediaHelper;
use PHPUnit\Framework\TestCase;

/**
 * Which banner file a locale gets served.
 *
 * The two seams that need a booted app - the media library and the site
 * definition table - are protected static methods, so the stub below answers
 * them from arrays and the suffix rules can be stated on their own.
 */
class LocalizedMediaHelperStub extends LocalizedMediaHelper
{
    /** @var array<int, string> Paths the stub library holds */
    public static $arExistingPathList = [];

    /** @var int Times the library was asked */
    public static $iExistsCallCount = 0;

    protected static function mediaFileExists($sPath)
    {
        static::$iExistsCallCount++;

        return in_array($sPath, static::$arExistingPathList);
    }

    protected static function getSiteLocaleList()
    {
        return ['lv', 'en', 'ru'];
    }
}

class LocalizedMediaHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LocalizedMediaHelperStub::flushCache();
        LocalizedMediaHelperStub::$arExistingPathList = [
            '/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg',
            '/2026/Septembris/NAILS cosmetics_09_SEPT-ru.jpg',
        ];
        LocalizedMediaHelperStub::$iExistsCallCount = 0;
    }

    public function testLocaleTwinIsServedWhenTheFileExists()
    {
        $sPath = LocalizedMediaHelperStub::localize('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', 'ru');

        $this->assertSame('/2026/Septembris/NAILS cosmetics_09_SEPT-ru.jpg', $sPath);
    }

    public function testStoredPathIsKeptWhenTheTwinWasNeverUploaded()
    {
        $sPath = LocalizedMediaHelperStub::localize('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', 'en');

        $this->assertSame('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', $sPath);
    }

    public function testMatchingLocaleCostsNoLibraryLookup()
    {
        $sPath = LocalizedMediaHelperStub::localize('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', 'lv');

        $this->assertSame('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', $sPath);
        $this->assertSame(0, LocalizedMediaHelperStub::$iExistsCallCount);
    }

    /**
     * The banners for shop opening hours carry no locale suffix, and a name
     * ending in two letters that are not a locale is just a name.
     */
    public function testPathWithoutALocaleSuffixIsUntouched()
    {
        $arPathList = [
            '/2026/Augusts 2026/2026_08_26_veikalu darba laika izmainas_WEB-8 (1).jpg',
            '/2026/banner-12.jpg',
            '/2026/polygel-xl.jpg',
        ];

        foreach ($arPathList as $sPath) {
            $this->assertSame($sPath, LocalizedMediaHelperStub::localize($sPath, 'ru'));
        }

        $this->assertSame(0, LocalizedMediaHelperStub::$iExistsCallCount);
    }

    public function testSecondCallForTheSamePathReusesTheFirstAnswer()
    {
        LocalizedMediaHelperStub::localize('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', 'ru');
        LocalizedMediaHelperStub::localize('/2026/Septembris/NAILS cosmetics_09_SEPT-lv.jpg', 'ru');

        $this->assertSame(1, LocalizedMediaHelperStub::$iExistsCallCount);
    }

    public function testEmptyPathIsReturnedAsIs()
    {
        $this->assertSame('', LocalizedMediaHelperStub::localize('', 'ru'));
        $this->assertNull(LocalizedMediaHelperStub::localize(null, 'ru'));
    }
}
