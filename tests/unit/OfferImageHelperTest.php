<?php namespace Logingrupa\StoreExtender\Tests\Unit;

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
}
