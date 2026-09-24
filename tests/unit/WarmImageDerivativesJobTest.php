<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use InvalidArgumentException;
use Logingrupa\StoreExtender\Classes\Queue\WarmImageDerivatives;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use PHPUnit\Framework\TestCase;
use System\Models\File;

/**
 * What the job does with the id it was handed.
 *
 * A slot is identified here by the geometry it asks for, the same way
 * ImageWarmerTest identifies one: the picture is a File subclass that records
 * every getThumbUrl() call instead of resizing, so "780x680 crop" is the phone
 * hero and nothing else. No storage disk, no source file, no queue.
 *
 * The job's own database read is the one thing stubbed out, because a resize
 * needs a stored source that a unit test does not have. Everything after the
 * read - the re-check, the matrix, the label - is the real code.
 */
class WarmImageDerivativesJobTest extends TestCase
{
    const SWATCH_ASK = '96x96 crop';
    const PREVIEW_ASK = '600x600 auto';
    const HERO_ASK = '1000x821 auto';
    const HERO_PHONE_ASK = '780x680 crop';

    /**
     * A picture that records what it was asked for.
     *
     * height is an accessor that reads the source file through the Storage
     * facade, so the fake source height overrides the accessor rather than
     * setting an attribute the accessor would ignore.
     *
     * @return \System\Models\File
     */
    protected function makeRecordingFile(string $sAttachmentType, string $sField, int $iAttachmentId = 4200)
    {
        $obFile = new class extends File {
            /** @var array */
            public $arAskList = [];

            public function getHeightAttribute()
            {
                return 821;
            }

            public function getThumbUrl($width, $height, $options = [])
            {
                $this->arAskList[] = sprintf('%dx%d %s', $width, $height, $options['mode'] ?? '');

                return '/thumb/'.$this->id;
            }
        };
        $obFile->id = 10505;
        $obFile->attachment_type = $sAttachmentType;
        $obFile->attachment_id = $iAttachmentId;
        $obFile->field = $sField;
        $obFile->file_name = 'picture-10505.jpg';
        $obFile->file_size = 11505;

        return $obFile;
    }

    /**
     * The job with its one database read replaced by a fixture.
     *
     * @param \System\Models\File|null $obFileFixture what findFile() answers
     * @return \Logingrupa\StoreExtender\Classes\Queue\WarmImageDerivatives
     */
    protected function makeJob($obFileFixture, int $iFileId = 10505)
    {
        $obJob = new class($iFileId) extends WarmImageDerivatives {
            /** @var \System\Models\File|null */
            public $obFileFixture = null;

            /** @var int */
            public $iFindFileCallCount = 0;

            protected function findFile(): ?File
            {
                $this->iFindFileCallCount++;

                return $this->obFileFixture;
            }
        };
        $obJob->obFileFixture = $obFileFixture;

        return $obJob;
    }

    public function testAWatchedOfferPreviewPictureIsAskedForAllFourSlots()
    {
        $obFile = $this->makeRecordingFile(Offer::class, 'preview_image');
        $this->makeJob($obFile)->handle();

        $this->assertSame(
            [self::SWATCH_ASK, self::PREVIEW_ASK, self::HERO_ASK, self::HERO_PHONE_ASK],
            $obFile->arAskList
        );
    }

    public function testAWatchedOfferGalleryPictureIsAskedForThePreviewSlotOnly()
    {
        $obFile = $this->makeRecordingFile(Offer::class, 'images');
        $this->makeJob($obFile)->handle();

        $this->assertSame([self::PREVIEW_ASK], $obFile->arAskList);
    }

    public function testAWatchedProductPreviewPictureIsAskedForTheTwoHeroSlotsOnly()
    {
        $obFile = $this->makeRecordingFile(Product::class, 'preview_image', 366);
        $this->makeJob($obFile)->handle();

        $this->assertSame([self::HERO_ASK, self::HERO_PHONE_ASK], $obFile->arAskList);
        $this->assertNotContains(self::SWATCH_ASK, $obFile->arAskList);
    }

    public function testAProductGalleryPictureIsWarmedInNoSlot()
    {
        // /p draws a product gallery entry at 700x570 and at 100x100 and never
        // in the 600px sheet preview slot, so the file would have no caller
        $obFile = $this->makeRecordingFile(Product::class, 'images', 366);
        $this->makeJob($obFile)->handle();

        $this->assertSame([], $obFile->arAskList);
    }

    public function testAnUnwatchedAttachmentIsNotWarmedEvenThoughTheJobCarriedItsId()
    {
        // the re-check: the dispatch is a hint, the loaded row is the fact
        $obFile = $this->makeRecordingFile('Backend\Models\User', 'avatar');
        $this->makeJob($obFile)->handle();

        $this->assertSame([], $obFile->arAskList);
    }

    public function testAMissingRowIsAQuietNoOp()
    {
        $obJob = $this->makeJob(null);
        $obJob->handle();

        $this->assertSame(1, $obJob->iFindFileCallCount);
    }

    public function testANonPositiveFileIdIsRejectedAtTheBoundary()
    {
        $this->expectException(InvalidArgumentException::class);
        new WarmImageDerivatives(0);
    }

    public function testTheUniquenessKeyIsTheFileId()
    {
        $this->assertSame('10505', (new WarmImageDerivatives(10505))->uniqueId());
    }

    /**
     * The push happens inside the model event, inside whatever transaction
     * attached the picture. A job run before that commit finds no row and
     * stops quietly, so the flag is set by the job itself and not left to a
     * dispatcher to remember.
     */
    public function testTheJobWaitsForTheTransactionThatAttachedThePicture()
    {
        $this->assertTrue((new WarmImageDerivatives(10505))->afterCommit);
    }
}
