<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use ErrorException;
use InvalidArgumentException;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use October\Rain\Database\Attach\File;
use PHPUnit\Framework\TestCase;

/**
 * The size and format decision behind every offer picture on the storefront.
 *
 * October names a derivative thumb_{id}_{width}_{height}_{mode}.{extension},
 * so the filename October itself would produce is a complete statement of what
 * was asked for. These tests run that filename through October's own
 * File::getThumbFilename() rather than a copy of the rule, which is what makes
 * them catch the ARITY DEFECT this helper exists to prevent: getThumb() takes
 * three arguments, the templates were passing four, and the discarded fourth
 * held the whole point of the call.
 *
 * No database and no stored file: getThumbFilename() reads the record id and,
 * only when the extension is 'auto', the source file name.
 */
class OfferImageHelperTest extends TestCase
{
    /**
     * A File that knows its own extension without a storage disk. October's
     * File::getExtension() goes through the Storage facade, which is not booted
     * in a plain unit test; the source extension only matters here for the one
     * case that proves what the discarded options used to fall back to.
     */
    protected function makeFile(int $iId, string $sExtension): File
    {
        $obFile = new class extends File {
            /** @var string */
            public $sFakeExtension = '';

            public function getExtension()
            {
                return $this->sFakeExtension;
            }
        };
        $obFile->id = $iId;
        $obFile->sFakeExtension = $sExtension;

        return $obFile;
    }

    /** disk_name abcdefghij.jpg sits in partition abc/def/ghi */
    const SOURCE_DISK_PATH = 'public/abc/def/ghi/abcdefghij.jpg';
    const SOURCE_URL = 'http://localhost/storage/uploads/public/abc/def/ghi/abcdefghij.jpg';

    /**
     * A stored File on a fake storage disk. The disk answers $bSourceOnDisk for
     * the source path and "exists" for any derivative, and records every path it
     * is asked about. Both path helpers stay October's own, so a case pins the
     * exact disk path a lookup checks and the exact URL it hands back.
     *
     * getThumbUrl() records the geometry it is asked for instead of resizing,
     * because a plain unit test has no Storage facade and no stored source.
     *
     * height is an accessor on File that runs getimagesize() on the source, so
     * the fake accessor throws on a missing source, as the real one does.
     */
    protected function makeStoredFile(int $iId, int $iSourceHeight, bool $bSourceOnDisk = true, bool $bIsImage = true): File
    {
        $obFile = new class extends File {
            /** @var int */
            public $iFakeHeight = 0;

            /** @var bool */
            public $bFakeIsImage = true;

            /** @var bool */
            public $bFakeSourceOnDisk = true;

            /** @var list<string> every disk path a lookup asked about */
            public $arExistsAskList = [];

            /** @var list<array{width: mixed, height: mixed}> */
            public $arThumbRequestList = [];

            public function isImage()
            {
                return $this->bFakeIsImage;
            }

            public function getHeightAttribute()
            {
                if (!$this->bFakeSourceOnDisk) {
                    throw new ErrorException('getimagesize(): Failed to open stream: No such file or directory');
                }

                return $this->iFakeHeight;
            }

            public function getThumbUrl($width, $height, $options = [])
            {
                $this->arThumbRequestList[] = ['width' => $width, 'height' => $height];

                return 'recorded';
            }

            public function getDisk()
            {
                return new class($this) {
                    public function __construct(private File $obFile)
                    {
                    }

                    public function exists(string $sPath): bool
                    {
                        $this->obFile->arExistsAskList[] = $sPath;

                        return $sPath !== $this->obFile->getDiskPath() || $this->obFile->bFakeSourceOnDisk;
                    }
                };
            }
        };
        $obFile->id = $iId;
        $obFile->disk_name = 'abcdefghij.jpg';
        $obFile->is_public = true;
        $obFile->iFakeHeight = $iSourceHeight;
        $obFile->bFakeSourceOnDisk = $bSourceOnDisk;
        $obFile->bFakeIsImage = $bIsImage;

        return $obFile;
    }

    public function testSwatchOptionsProduceACroppedWebpDerivative()
    {
        $obFile = $this->makeFile(10505, 'png');

        $sFileName = $obFile->getThumbFilename(
            OfferImageHelper::getSlotSize(OfferImageHelper::SLOT_SWATCH),
            OfferImageHelper::getSlotSize(OfferImageHelper::SLOT_SWATCH),
            OfferImageHelper::getThumbOptions(OfferImageHelper::SLOT_SWATCH)
        );

        $this->assertSame('thumb_10505_96_96_crop.webp', $sFileName);
    }

    public function testPreviewOptionsProduceAFittedWebpDerivative()
    {
        $obFile = $this->makeFile(10505, 'png');

        $sFileName = $obFile->getThumbFilename(
            OfferImageHelper::getSlotSize(OfferImageHelper::SLOT_PREVIEW),
            OfferImageHelper::getSlotSize(OfferImageHelper::SLOT_PREVIEW),
            OfferImageHelper::getThumbOptions(OfferImageHelper::SLOT_PREVIEW)
        );

        // 'auto' is October's fit mode: it scales inside the box and never
        // crops, which is what object-fit: contain in the preview slot needs
        $this->assertSame('thumb_10505_600_600_auto.webp', $sFileName);
    }

    /**
     * The defect, pinned so nobody reintroduces it believing it works. This
     * is the shape three templates carried: a mode string in the options
     * position and the real options as a fourth argument PHP throws away.
     */
    public function testAModeStringInTheOptionsPositionLosesFormatAndQuality()
    {
        $obFile = $this->makeFile(10505, 'png');

        $sFileName = $obFile->getThumbFilename(96, 96, 'crop');

        // the extension fell back to 'auto', which resolves to the SOURCE
        // format - no webp, and the quality option never arrived either
        $this->assertSame('thumb_10505_96_96_crop.png', $sFileName);
        $this->assertStringEndsNotWith('.webp', $sFileName);
    }

    public function testSwatchIsCroppedAndPreviewIsNot()
    {
        $this->assertSame('crop', OfferImageHelper::getThumbOptions(OfferImageHelper::SLOT_SWATCH)['mode']);
        $this->assertSame('auto', OfferImageHelper::getThumbOptions(OfferImageHelper::SLOT_PREVIEW)['mode']);
    }

    public function testBothSlotsAskForWebpAtTheSameQuality()
    {
        foreach ([OfferImageHelper::SLOT_SWATCH, OfferImageHelper::SLOT_PREVIEW] as $sSlot) {
            $arOptions = OfferImageHelper::getThumbOptions($sSlot);
            $this->assertSame('webp', $arOptions['extension']);
            $this->assertSame(80, $arOptions['quality']);
        }
    }

    /**
     * A swatch has to be big enough for the largest box it lands in: the 48px
     * sheet row, at 2x. Shrinking this constant to save bytes would make the
     * sheet look soft on every phone sold in the last decade.
     */
    public function testSwatchSizeCoversTheLargestSwatchBoxAtRetinaDensity()
    {
        $this->assertGreaterThanOrEqual(48 * 2, OfferImageHelper::getSlotSize(OfferImageHelper::SLOT_SWATCH));
    }

    /**
     * The preview slot is 240 CSS px tall on desktop and around 33dvh on a
     * phone; 2x the desktop box is the floor.
     */
    public function testPreviewSizeCoversTheDesktopPreviewBoxAtRetinaDensity()
    {
        $this->assertGreaterThanOrEqual(240 * 2, OfferImageHelper::getSlotSize(OfferImageHelper::SLOT_PREVIEW));
    }

    public function testMissingImageRendersNoSource()
    {
        $this->assertSame('', OfferImageHelper::swatch(null));
        $this->assertSame('', OfferImageHelper::preview(null));
    }

    public function testUnknownSlotThrows()
    {
        $this->expectException(InvalidArgumentException::class);

        OfferImageHelper::getThumbOptions('hero');
    }

    /**
     * The measured phone box, pinned through October's own naming rule rather
     * than a copy of it: 390x340 CSS px at DPR 2 is 780x680, and the mode is
     * crop because the box is object-fit: cover.
     */
    public function testPhoneHeroOptionsProduceACroppedWebpDerivativeAtTheMeasuredBox()
    {
        $obFile = $this->makeFile(10505, 'png');

        $sFileName = $obFile->getThumbFilename(
            OfferImageHelper::HERO_PHONE_WIDTH,
            OfferImageHelper::HERO_PHONE_HEIGHT,
            OfferImageHelper::getHeroPhoneThumbOptions()
        );

        $this->assertSame('thumb_10505_780_680_crop.webp', $sFileName);
    }

    /**
     * All three keys, because the arity defect the mode-string case above pins
     * is exactly an options array arriving without its extension and quality.
     */
    public function testPhoneHeroOptionsCarryModeQualityAndExtension()
    {
        $arOptions = OfferImageHelper::getHeroPhoneThumbOptions();

        $this->assertSame('crop', $arOptions['mode']);
        $this->assertSame(OfferImageHelper::HERO_PHONE_QUALITY, $arOptions['quality']);
        $this->assertSame('webp', $arOptions['extension']);
    }

    public function testMissingImageRendersNoPhoneHeroSource()
    {
        $this->assertSame('', OfferImageHelper::heroPhone(null));
        $this->assertSame('', OfferImageHelper::heroPhoneIfWarm(null));
    }

    /**
     * A file that is not an image has no derivative to find, so the lookup gives
     * up before storage.
     */
    public function testPhoneHeroWarmLookupSkipsANonImageWithoutTouchingTheDisk()
    {
        $obFile = $this->makeStoredFile(10505, 821, true, false);

        $this->assertSame('', OfferImageHelper::heroPhoneIfWarm($obFile));
        $this->assertSame([], $obFile->arExistsAskList);
    }

    /**
     * A source shorter than the hero floor answers an empty string, so the
     * caller falls through to the 600px preview or the 96px circle it already
     * renders instead of pointing at a 780x680 file nothing writes for it. The
     * disk is asked about the source only, never about a derivative.
     */
    public function testPhoneHeroWarmLookupGivesUpOnASourceShorterThanTheHeroFloor()
    {
        $obFile = $this->makeStoredFile(10505, OfferImageHelper::HERO_MIN_SOURCE_HEIGHT - 1);

        $this->assertSame('', OfferImageHelper::heroPhoneIfWarm($obFile));
        $this->assertSame([self::SOURCE_DISK_PATH], $obFile->arExistsAskList);
    }

    /**
     * The generating twin on that same short source asks for HERO_SMALL_WIDTH
     * and a proportional height, so an eager slide never upscales a 299px source
     * 2.7x into the 780x680 box.
     */
    public function testPhoneHeroDoesNotUpscaleASourceShorterThanTheHeroFloor()
    {
        $obFile = $this->makeStoredFile(10505, OfferImageHelper::HERO_MIN_SOURCE_HEIGHT - 1);

        OfferImageHelper::heroPhone($obFile);

        $this->assertSame(
            [['width' => OfferImageHelper::HERO_SMALL_WIDTH, 'height' => 'auto']],
            $obFile->arThumbRequestList
        );
    }

    /**
     * A full-size source goes to the measured box, and to it exactly once.
     */
    public function testPhoneHeroAsksForTheMeasuredBoxOnAFullSizeSource()
    {
        $obFile = $this->makeStoredFile(10505, 821);

        OfferImageHelper::heroPhone($obFile);

        $this->assertSame(
            [['width' => OfferImageHelper::HERO_PHONE_WIDTH, 'height' => OfferImageHelper::HERO_PHONE_HEIGHT]],
            $obFile->arThumbRequestList
        );
    }

    /**
     * The branch the phase exists for: the derivative is on disk, so the
     * lookup hands back its URL. The disk path it checked and the URL it
     * returned both name the file the generating twin writes, down to the
     * partition directory, so a drift in either would fail here rather than
     * silently fall every phone slide back to the 600px preview.
     */
    public function testPhoneHeroWarmLookupReturnsThePathOnceTheDerivativeExists()
    {
        $obFile = $this->makeStoredFile(10505, 821);

        $sUrl = OfferImageHelper::heroPhoneIfWarm($obFile);

        $this->assertSame(
            'http://localhost/storage/uploads/public/abc/def/ghi/thumb_10505_780_680_crop.webp',
            $sUrl
        );
        $this->assertSame(
            [self::SOURCE_DISK_PATH, 'public/abc/def/ghi/thumb_10505_780_680_crop.webp'],
            $obFile->arExistsAskList
        );
    }

    public function testHeroWarmLookupReturnsThePathOnceTheDerivativeExists()
    {
        $obFile = $this->makeStoredFile(10505, 821);

        $sUrl = OfferImageHelper::heroIfWarm($obFile);

        $this->assertSame(
            'http://localhost/storage/uploads/public/abc/def/ghi/thumb_10505_1000_821_auto.webp',
            $sUrl
        );
        $this->assertSame(
            [self::SOURCE_DISK_PATH, 'public/abc/def/ghi/thumb_10505_1000_821_auto.webp'],
            $obFile->arExistsAskList
        );
    }

    /**
     * Unlike the phone twin, hero() serves a short source at HERO_SMALL_WIDTH
     * and heroIfWarm() looks for that file: 300 wide, height auto, which
     * October writes as 0 in the name.
     */
    public function testHeroWarmLookupNamesTheSmallSourceDerivativeOnAShortSource()
    {
        $obFile = $this->makeStoredFile(10505, OfferImageHelper::HERO_MIN_SOURCE_HEIGHT - 1);

        $sUrl = OfferImageHelper::heroIfWarm($obFile);

        $this->assertSame(
            'http://localhost/storage/uploads/public/abc/def/ghi/thumb_10505_300_0_auto.webp',
            $sUrl
        );
        $this->assertSame(
            [self::SOURCE_DISK_PATH, 'public/abc/def/ghi/thumb_10505_300_0_auto.webp'],
            $obFile->arExistsAskList
        );
    }

    /**
     * A source file missing from the disk must not reach the height accessor,
     * whose getimagesize() call throws. The generating pair answers the source
     * URL, as File::getThumb() does for a missing file, and the lookup pair gives
     * up before it looks for a derivative.
     */
    public function testAMissingSourceFileIsServedWithoutReadingItsHeight()
    {
        foreach (['hero', 'heroPhone'] as $sMethod) {
            $obFile = $this->makeStoredFile(10505, 821, false);

            $this->assertSame(self::SOURCE_URL, OfferImageHelper::$sMethod($obFile), $sMethod);
            $this->assertSame([], $obFile->arThumbRequestList, $sMethod.' must not resize a missing source');
        }

        foreach (['heroIfWarm', 'heroPhoneIfWarm'] as $sMethod) {
            $obFile = $this->makeStoredFile(10505, 821, false);

            $this->assertSame('', OfferImageHelper::$sMethod($obFile), $sMethod);
            $this->assertSame([self::SOURCE_DISK_PATH], $obFile->arExistsAskList, $sMethod);
        }
    }

    /**
     * The phone slot never joined assertSlot(). That guard is what stops
     * getSlotSize() from answering 96 or 600 for a hero picture, and the case
     * above it uses 'hero' as its unknown-slot fixture for the same reason.
     */
    public function testPhoneHeroSlotIsNotAGenericSlotName()
    {
        $this->expectException(InvalidArgumentException::class);

        OfferImageHelper::getThumbOptions(OfferImageHelper::SLOT_HERO_PHONE);
    }
}
