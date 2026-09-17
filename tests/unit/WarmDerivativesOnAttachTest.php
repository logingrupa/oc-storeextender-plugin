<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\StoreExtender\Classes\Event\Image\WarmDerivativesOnAttach;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Which saved files earn a warm job and which are none of our business.
 *
 * The rule is a pure function of two attributes, so these cases drive it with
 * bare objects: no event, no model, no database, no queue. That is the whole
 * reason isWatched() is public and static.
 *
 * The negative cases carry the weight. `system_files` holds every attachment
 * in the application - backend avatars, order files, settings artwork, theme
 * uploads - and some of those are private files whose derivative URL October
 * routes through the backend Files controller. A rule that let one through
 * would publish it.
 */
class WarmDerivativesOnAttachTest extends TestCase
{
    /**
     * A saved file, reduced to the two attributes the rule reads.
     *
     * @param mixed $mAttachmentType
     * @param mixed $mField
     */
    protected function makeSavedFile($mAttachmentType, $mField): stdClass
    {
        $obFile = new stdClass();
        $obFile->id = 10505;
        $obFile->attachment_type = $mAttachmentType;
        $obFile->field = $mField;

        return $obFile;
    }

    public function testAnOfferPreviewPictureIsWatched()
    {
        $this->assertTrue(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(Offer::class, 'preview_image')
        ));
    }

    public function testAnOfferGalleryPictureIsWatched()
    {
        $this->assertTrue(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(Offer::class, 'images')
        ));
    }

    public function testAProductPreviewPictureIsWatched()
    {
        $this->assertTrue(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(Product::class, 'preview_image')
        ));
    }

    public function testAProductGalleryPictureIsWatched()
    {
        // watched by the listener, and warmed in no slot by the job: the type
        // filter is coarse on purpose and the job owns the matrix
        $this->assertTrue(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(Product::class, 'images')
        ));
    }

    public function testAnotherModelsAttachmentIsNotWatched()
    {
        $this->assertFalse(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile('Backend\Models\User', 'avatar')
        ));
    }

    public function testAnotherFieldOnAWatchedModelIsNotWatched()
    {
        $this->assertFalse(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(Offer::class, 'attachment')
        ));
    }

    public function testAFileWithNoAttachmentTypeIsNotWatched()
    {
        // an upload that has not been attached to anything yet
        $this->assertFalse(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(null, 'preview_image')
        ));
    }

    public function testAFileWithNoFieldIsNotWatched()
    {
        $this->assertFalse(WarmDerivativesOnAttach::isWatched(
            $this->makeSavedFile(Offer::class, null)
        ));
    }

    public function testSomethingThatIsNotAnObjectIsNotWatched()
    {
        $this->assertFalse(WarmDerivativesOnAttach::isWatched(null));
        $this->assertFalse(WarmDerivativesOnAttach::isWatched(Offer::class));
    }
}
