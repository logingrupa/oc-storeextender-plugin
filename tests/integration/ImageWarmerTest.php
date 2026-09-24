<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\ImageWarmer;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Attach\File;

/**
 * Which derivatives a record's pictures are asked for.
 *
 * These cases pin the MATRIX, not the resize. A resize needs a storage disk and
 * a stored source file, so every picture here is a File subclass that records
 * the geometry each getThumbUrl() call asks it for and hands back a marker URL.
 * A slot is therefore identified by what it asks for - "780x680 crop" is the
 * phone hero and nothing else - so an accidental fifth slot, a missing one or a
 * swapped one all show up as a different recorded list.
 *
 * NO PLUGIN TABLES AND NO QUERIES: $autoMigrate is off and every record carries
 * its pictures through setRelation(). The app is booted all the same, because
 * constructing an Offer runs RainLab Translate's TranslatableModel behavior,
 * which reads the primary site through the Site manager; a plain PHPUnit
 * TestCase dies there with "A facade root has not been set."
 */
class ImageWarmerTest extends StoreExtenderPluginTestCase
{
    /** No plugin schema is touched: the pictures are set as loaded relations. */
    protected $autoMigrate = false;

    const SWATCH_ASK = '96x96 crop';
    const PREVIEW_ASK = '600x600 auto';
    const HERO_ASK = '1000x821 auto';
    const HERO_PHONE_ASK = '780x680 crop';

    /** hero()'s small-source branch: 300 wide, height auto, which is 0 in the name */
    const HERO_SMALL_ASK = '300x0 auto';

    /**
     * A File that records what it was asked for instead of resizing.
     *
     * height is an accessor on File (getHeightAttribute reads the source file
     * through the Storage facade), so the fake source height overrides the
     * accessor rather than setting an attribute the accessor would ignore.
     *
     * @return \October\Rain\Database\Attach\File
     */
    protected function makeRecordingFile(int $iId, int $iSourceHeight = 821)
    {
        $obFile = new class extends File {
            /** @var int */
            public $iFakeHeight = 0;

            /** @var bool */
            public $bThrowOnThumb = false;

            /** @var array */
            public $arAskList = [];

            public function getHeightAttribute()
            {
                return $this->iFakeHeight;
            }

            public function getThumbUrl($width, $height, $options = [])
            {
                if ($this->bThrowOnThumb) {
                    throw new RuntimeException('source is unreadable');
                }
                $this->arAskList[] = sprintf('%dx%d %s', $width, $height, $options['mode'] ?? '');

                return '/thumb/'.$this->id;
            }
        };
        $obFile->id = $iId;
        $obFile->iFakeHeight = $iSourceHeight;
        $obFile->file_name = 'picture-'.$iId.'.jpg';
        $obFile->file_size = 1000 + $iId;

        return $obFile;
    }

    /**
     * An offer carrying the given pictures and nothing else.
     *
     * @param \October\Rain\Database\Attach\File|null $obPreviewImage
     * @param array $arGalleryImageList
     * @return \Lovata\Shopaholic\Models\Offer
     */
    protected function makeOffer($obPreviewImage, array $arGalleryImageList = [])
    {
        $obOffer = new Offer();
        $obOffer->id = 4200;
        $obOffer->setRelation('preview_image', $obPreviewImage);
        $obOffer->setRelation('images', new Collection($arGalleryImageList));

        return $obOffer;
    }

    /**
     * A product carrying the given preview picture and nothing else.
     *
     * @param \October\Rain\Database\Attach\File|null $obPreviewImage
     * @return \Lovata\Shopaholic\Models\Product
     */
    protected function makeProduct($obPreviewImage)
    {
        $obProduct = new Product();
        $obProduct->id = 366;
        $obProduct->setRelation('preview_image', $obPreviewImage);

        return $obProduct;
    }

    public function testOfferPreviewPictureIsAskedForAllFourSlots()
    {
        $obPreviewImage = $this->makeRecordingFile(10505);
        $arCounts = ImageWarmer::warmOfferPictures($this->makeOffer($obPreviewImage), false);

        $this->assertSame(
            [self::SWATCH_ASK, self::PREVIEW_ASK, self::HERO_ASK, self::HERO_PHONE_ASK],
            $obPreviewImage->arAskList
        );
        $this->assertSame(4, $arCounts[ImageWarmer::OUTCOME_WARMED]);
        $this->assertSame(0, $arCounts[ImageWarmer::OUTCOME_FAILED]);
    }

    public function testGalleryPictureIsAskedForThePreviewSlotOnly()
    {
        $obGalleryImage = $this->makeRecordingFile(10601);
        $arCounts = ImageWarmer::warmOfferPictures(
            $this->makeOffer($this->makeRecordingFile(10505), [$obGalleryImage]),
            false
        );

        $this->assertSame([self::PREVIEW_ASK], $obGalleryImage->arAskList);
        $this->assertSame(5, $arCounts[ImageWarmer::OUTCOME_WARMED]);
    }

    public function testGalleryEntryRepeatingThePreviewPictureIsSkipped()
    {
        $obPreviewImage = $this->makeRecordingFile(10505);
        $obRepeatedImage = $this->makeRecordingFile(10777);
        // the import attaches a second row for the same photograph, so the
        // dedupe is by name and byte size, not by file id
        $obRepeatedImage->file_name = $obPreviewImage->file_name;
        $obRepeatedImage->file_size = $obPreviewImage->file_size;

        ImageWarmer::warmOfferPictures($this->makeOffer($obPreviewImage, [$obRepeatedImage]), false);

        $this->assertSame([], $obRepeatedImage->arAskList);
    }

    public function testGalleryCeilingCapsTheLoopAndIsReported()
    {
        $arGalleryImageList = [];
        for ($iIndex = 0; $iIndex < ImageWarmer::GALLERY_IMAGE_CEILING + 5; $iIndex++) {
            $arGalleryImageList[] = $this->makeRecordingFile(20000 + $iIndex);
        }

        $arCounts = ImageWarmer::warmOfferPictures(
            $this->makeOffer($this->makeRecordingFile(10505), $arGalleryImageList),
            false
        );

        $this->assertSame(1, $arCounts[ImageWarmer::COUNT_TRUNCATED]);
        $this->assertSame(4 + ImageWarmer::GALLERY_IMAGE_CEILING, $arCounts[ImageWarmer::OUTCOME_WARMED]);
        $this->assertSame([], $arGalleryImageList[ImageWarmer::GALLERY_IMAGE_CEILING]->arAskList);
    }

    public function testProductPreviewPictureIsAskedForTheTwoHeroSlotsOnly()
    {
        $obPreviewImage = $this->makeRecordingFile(30001);
        $arCounts = ImageWarmer::warmProductPictures($this->makeProduct($obPreviewImage), false);

        $this->assertSame([self::HERO_ASK, self::HERO_PHONE_ASK], $obPreviewImage->arAskList);
        $this->assertNotContains(self::SWATCH_ASK, $obPreviewImage->arAskList);
        $this->assertSame(2, $arCounts[ImageWarmer::OUTCOME_WARMED]);
    }

    public function testShortSourceSkipsThePhoneSlotAndKeepsTheRest()
    {
        // D-21: heroPhoneIfWarm() answers '' for a source under 300px tall, so
        // warming the slot would write a file no lookup can ever name. The /p
        // hero slot has no such asymmetry - heroIfWarm() looks up the same 300px
        // name hero() writes - so that slot is still warmed.
        $obPreviewImage = $this->makeRecordingFile(30002, 299);
        $arCounts = ImageWarmer::warmProductPictures($this->makeProduct($obPreviewImage), false);

        $this->assertSame([self::HERO_SMALL_ASK], $obPreviewImage->arAskList);
        $this->assertSame(1, $arCounts[ImageWarmer::OUTCOME_WARMED]);
        $this->assertSame(1, $arCounts[ImageWarmer::OUTCOME_SKIPPED]);
    }

    public function testAThrowingSlotIsCountedAsAFailureAndTheRecordContinues()
    {
        $obPreviewImage = $this->makeRecordingFile(10505);
        $obPreviewImage->bThrowOnThumb = true;
        $obGalleryImage = $this->makeRecordingFile(10601);

        $arCounts = ImageWarmer::warmOfferPictures(
            $this->makeOffer($obPreviewImage, [$obGalleryImage]),
            false
        );

        $this->assertSame(4, $arCounts[ImageWarmer::OUTCOME_FAILED]);
        $this->assertSame(1, $arCounts[ImageWarmer::OUTCOME_WARMED]);
        $this->assertSame([self::PREVIEW_ASK], $obGalleryImage->arAskList);
    }

    /**
     * warmPicture() is public and takes any list. A slot it does not know must
     * be a counted, logged failure - in a dry run as well - and never a fall
     * through to one of the real derivatives reported as warmed.
     */
    public function testAnUnknownSlotIsCountedAsAFailureAndAskedForNothing()
    {
        Log::spy();
        $obPreviewImage = $this->makeRecordingFile(10505);

        $arCounts = ImageWarmer::warmPicture($obPreviewImage, ['banner'], false, 'offer 4200');
        $arDryRunCounts = ImageWarmer::warmPicture($obPreviewImage, ['banner'], true, 'offer 4200');

        $this->assertSame([], $obPreviewImage->arAskList);
        $this->assertSame(1, $arCounts[ImageWarmer::OUTCOME_FAILED]);
        $this->assertSame(0, $arCounts[ImageWarmer::OUTCOME_WARMED]);
        $this->assertSame(1, $arDryRunCounts[ImageWarmer::OUTCOME_FAILED]);
        $this->assertSame(0, $arDryRunCounts[ImageWarmer::OUTCOME_WARMED]);
        Log::shouldHaveReceived('warning')->twice()->withArgs(function ($sMessage) {
            return strpos($sMessage, 'offer 4200') !== false
                && strpos($sMessage, 'slot banner') !== false;
        });
    }

    public function testDryRunCountsTheSameSlotsWithoutAskingTheResizer()
    {
        $obPreviewImage = $this->makeRecordingFile(10505);
        $arCounts = ImageWarmer::warmOfferPictures($this->makeOffer($obPreviewImage), true);

        $this->assertSame([], $obPreviewImage->arAskList);
        $this->assertSame(4, $arCounts[ImageWarmer::OUTCOME_WARMED]);
    }

    public function testRecordWithoutAPreviewPictureWarmsNothing()
    {
        $arCounts = ImageWarmer::warmProductPictures($this->makeProduct(null), false);

        $this->assertSame(0, $arCounts[ImageWarmer::OUTCOME_WARMED]);
        $this->assertSame(0, $arCounts[ImageWarmer::OUTCOME_FAILED]);
    }

    public function testUnsavedRecordIsRejectedAtTheBoundary()
    {
        $obOffer = new Offer();
        $obOffer->setRelation('preview_image', null);
        $obOffer->setRelation('images', new Collection());

        $this->expectException(InvalidArgumentException::class);
        ImageWarmer::warmOfferPictures($obOffer, false);
    }

    public function testEmptySlotListIsRejectedAtTheBoundary()
    {
        $this->expectException(InvalidArgumentException::class);
        ImageWarmer::warmPicture($this->makeRecordingFile(10505), [], false, 'offer 4200');
    }
}
