<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use InvalidArgumentException;
use LogicException;
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

    /**
     * A File that records the geometry every getThumbUrl() call asks it for.
     *
     * October generates the derivative inside getThumbUrl(), which needs a
     * storage disk and a stored source file, and a plain unit test has neither.
     * The property these cases are about is the geometry the helper ASKS for, so
     * the override records it and hands back a marker instead of a URL.
     *
     * height is an accessor on File (getHeightAttribute reads the source file
     * through the Storage facade), so the fake source height overrides the
     * accessor rather than setting an attribute the accessor would ignore.
     */
    protected function makeSizeRecordingFile(int $iId, int $iSourceHeight): File
    {
        $obFile = new class extends File {
            /** @var int */
            public $iFakeHeight = 0;

            /** @var list<array{width: mixed, height: mixed}> */
            public $arThumbRequestList = [];

            public function getHeightAttribute()
            {
                return $this->iFakeHeight;
            }

            public function getThumbUrl($width, $height, $options = [])
            {
                $this->arThumbRequestList[] = ['width' => $width, 'height' => $height];

                return 'recorded';
            }
        };
        $obFile->id = $iId;
        $obFile->iFakeHeight = $iSourceHeight;

        return $obFile;
    }

    /**
     * A File whose storage disk throws the moment it is touched.
     *
     * heroPhoneIfWarm() has to answer before it reaches storage twice: for a
     * file that is not an image, and for a source shorter than the hero floor.
     * A getDisk() that throws is the assertion itself, because those two cases
     * only pass when the disk is never asked.
     */
    protected function makeDiskForbiddenFile(int $iId, bool $bIsImage, int $iSourceHeight): File
    {
        $obFile = new class extends File {
            /** @var bool */
            public $bFakeIsImage = true;

            /** @var int */
            public $iFakeHeight = 0;

            public function isImage()
            {
                return $this->bFakeIsImage;
            }

            public function getHeightAttribute()
            {
                return $this->iFakeHeight;
            }

            public function getDisk()
            {
                throw new LogicException('heroPhoneIfWarm reached the storage disk');
            }
        };
        $obFile->id = $iId;
        $obFile->bFakeIsImage = $bIsImage;
        $obFile->iFakeHeight = $iSourceHeight;

        return $obFile;
    }

    /**
     * A File whose storage disk answers "that derivative exists" to whatever
     * it is asked, and records what it was asked. Both path helpers stay
     * October's own, so a case built on this pins the exact disk path the
     * lookup checks and the exact URL it hands back, partition directory and
     * derivative name included - the two things that drift silently, because
     * a drifted lookup answers '' and the caller falls back without a log.
     */
    protected function makeWarmFile(int $iId, int $iSourceHeight): File
    {
        $obFile = new class extends File {
            /** @var int */
            public $iFakeHeight = 0;

            /** @var list<string> every disk path the lookup asked about */
            public $arExistsAskList = [];

            public function isImage()
            {
                return true;
            }

            public function getHeightAttribute()
            {
                return $this->iFakeHeight;
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

                        return true;
                    }
                };
            }
        };
        $obFile->id = $iId;
        $obFile->disk_name = 'abcdefghij.jpg';
        $obFile->is_public = true;
        $obFile->iFakeHeight = $iSourceHeight;

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
     * up before storage. The fixture's getDisk() throws, so this passes only if
     * the disk was never asked.
     */
    public function testPhoneHeroWarmLookupSkipsANonImageWithoutTouchingTheDisk()
    {
        $obFile = $this->makeDiskForbiddenFile(10505, false, 821);

        $this->assertSame('', OfferImageHelper::heroPhoneIfWarm($obFile));
    }

    /**
     * A source shorter than the hero floor answers an empty string, so the
     * caller falls through to the 600px preview or the 96px circle it already
     * renders instead of pointing at a 780x680 file nothing writes for it. Same
     * throwing getDisk(): the branch answers before the lookup.
     */
    public function testPhoneHeroWarmLookupGivesUpOnASourceShorterThanTheHeroFloor()
    {
        $obFile = $this->makeDiskForbiddenFile(10505, true, OfferImageHelper::HERO_MIN_SOURCE_HEIGHT - 1);

        $this->assertSame('', OfferImageHelper::heroPhoneIfWarm($obFile));
    }

    /**
     * The generating twin on that same short source asks for HERO_SMALL_WIDTH
     * and a proportional height, so an eager slide never upscales a 299px source
     * 2.7x into the 780x680 box.
     */
    public function testPhoneHeroDoesNotUpscaleASourceShorterThanTheHeroFloor()
    {
        $obFile = $this->makeSizeRecordingFile(10505, OfferImageHelper::HERO_MIN_SOURCE_HEIGHT - 1);

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
        $obFile = $this->makeSizeRecordingFile(10505, 821);

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
        $obFile = $this->makeWarmFile(10505, 821);

        $sUrl = OfferImageHelper::heroPhoneIfWarm($obFile);

        $this->assertSame(
            'http://localhost/storage/uploads/public/abc/def/ghi/thumb_10505_780_680_crop.webp',
            $sUrl
        );
        $this->assertSame(['public/abc/def/ghi/thumb_10505_780_680_crop.webp'], $obFile->arExistsAskList);
    }

    public function testHeroWarmLookupReturnsThePathOnceTheDerivativeExists()
    {
        $obFile = $this->makeWarmFile(10505, 821);

        $sUrl = OfferImageHelper::heroIfWarm($obFile);

        $this->assertSame(
            'http://localhost/storage/uploads/public/abc/def/ghi/thumb_10505_1000_821_auto.webp',
            $sUrl
        );
        $this->assertSame(['public/abc/def/ghi/thumb_10505_1000_821_auto.webp'], $obFile->arExistsAskList);
    }

    /**
     * Unlike the phone twin, hero() serves a short source at HERO_SMALL_WIDTH
     * and heroIfWarm() looks for that file: 300 wide, height auto, which
     * October writes as 0 in the name.
     */
    public function testHeroWarmLookupNamesTheSmallSourceDerivativeOnAShortSource()
    {
        $obFile = $this->makeWarmFile(10505, OfferImageHelper::HERO_MIN_SOURCE_HEIGHT - 1);

        $sUrl = OfferImageHelper::heroIfWarm($obFile);

        $this->assertSame(
            'http://localhost/storage/uploads/public/abc/def/ghi/thumb_10505_300_0_auto.webp',
            $sUrl
        );
        $this->assertSame(['public/abc/def/ghi/thumb_10505_300_0_auto.webp'], $obFile->arExistsAskList);
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
