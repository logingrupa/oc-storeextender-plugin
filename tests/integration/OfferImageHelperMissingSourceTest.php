<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use System\Models\File;

/**
 * The hero helpers against a real System\Models\File whose source is not on
 * the uploads disk. The record is never saved, so no table is touched.
 */
class OfferImageHelperMissingSourceTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /**
     * @return File
     */
    protected function makeMissingSourceFile()
    {
        $obFile = new File();
        $obFile->id = 990001;
        $obFile->disk_name = 'zzzmissingsourcefile.jpg';
        $obFile->file_name = 'missing.jpg';
        $obFile->is_public = true;

        $this->assertFalse($obFile->getDisk()->exists($obFile->getDiskPath()), 'the fixture source must be absent');

        return $obFile;
    }

    public function testTheHeightReadOfAMissingSourceThrows()
    {
        $obFile = $this->makeMissingSourceFile();

        $this->expectException(ErrorException::class);

        $obFile->height;
    }

    public function testEveryHeroHelperAnswersAMissingSourceWithoutThrowing()
    {
        $obFile = $this->makeMissingSourceFile();

        $this->assertSame($obFile->getUrl(), OfferImageHelper::hero($obFile));
        $this->assertSame($obFile->getUrl(), OfferImageHelper::heroPhone($obFile));
        $this->assertSame('', OfferImageHelper::heroIfWarm($obFile));
        $this->assertSame('', OfferImageHelper::heroPhoneIfWarm($obFile));
    }
}
