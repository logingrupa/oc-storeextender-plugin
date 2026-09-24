<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Attach\File;
use RuntimeException;
use Throwable;

/**
 * The one place that answers "which derivatives does this record's pictures need".
 *
 * One place, because two ask that question: the deploy command
 * (storeextender:warm-offer-thumbs) walks every record after a release, and the
 * attach job warms the single picture the 1C import just landed. Two copies of
 * the matrix would drift the first time a slot is added, which is exactly how
 * the phone hero slot arrived.
 *
 * The matrix:
 *
 *   Offer   preview_image   swatch, preview, hero, hero_phone
 *   Offer   images (each)   preview
 *   Product preview_image   hero, hero_phone
 *
 * A Product picture gets neither swatch nor sheet preview, because both boxes
 * are offer-facing: the shade strip circles and the sheet rows only ever draw
 * offer pictures. It gets both hero slots because slide 1 of a bare product URL
 * is the Product preview picture, so that slide is an LCP candidate and must not
 * be resized inside the visitor's request (D-13).
 *
 * Sizes, crop modes and quality are NOT decided here - OfferImageHelper owns all
 * three. This class owns only which of them a picture is asked for, and the
 * counting that lets a caller report without owning the loop.
 */
class ImageWarmer
{
    /** Gallery pictures warmed per record before the rest are reported and skipped */
    const GALLERY_IMAGE_CEILING = 20;

    /** The derivative was generated, or was already on disk */
    const OUTCOME_WARMED = 'warmed';

    /** The source could not be read or encoded; counted and logged, never fatal */
    const OUTCOME_FAILED = 'failed';

    /** The slot was not asked for, because no lookup would ever name the file */
    const OUTCOME_SKIPPED = 'skipped';

    /** 1 when a record's gallery was cut at GALLERY_IMAGE_CEILING, 0 otherwise */
    const COUNT_TRUNCATED = 'truncated';

    /** Every slot an offer's preview picture is drawn in */
    const OFFER_PREVIEW_SLOT_LIST = [
        OfferImageHelper::SLOT_SWATCH,
        OfferImageHelper::SLOT_PREVIEW,
        OfferImageHelper::SLOT_HERO,
        OfferImageHelper::SLOT_HERO_PHONE,
    ];

    /** A gallery picture is only ever drawn in the sheet's large preview slot */
    const GALLERY_SLOT_LIST = [
        OfferImageHelper::SLOT_PREVIEW,
    ];

    /** A product preview picture is only ever drawn as a hero slide */
    const PRODUCT_PREVIEW_SLOT_LIST = [
        OfferImageHelper::SLOT_HERO,
        OfferImageHelper::SLOT_HERO_PHONE,
    ];

    /**
     * Warm every derivative one offer's pictures need: all four slots of the
     * preview picture, and the sheet preview slot of each gallery picture.
     *
     * @return array{warmed: int, failed: int, skipped: int, truncated: int}
     */
    public static function warmOfferPictures(Offer $obOffer, bool $bDryRun): array
    {
        $iOfferId = (int) $obOffer->id;
        if ($iOfferId <= 0) {
            throw new InvalidArgumentException('ImageWarmer: an unsaved offer has no pictures to warm');
        }

        $sRecordLabel = 'offer '.$iOfferId;
        $arCounts = self::makeCounts();

        $obPreviewImage = $obOffer->preview_image;
        if ($obPreviewImage !== null) {
            $arCounts = self::mergeCounts($arCounts, self::warmPicture(
                $obPreviewImage,
                self::OFFER_PREVIEW_SLOT_LIST,
                $bDryRun,
                $sRecordLabel
            ));
        }

        return self::mergeCounts($arCounts, self::warmGalleryPictures(
            $obOffer->images,
            $obPreviewImage,
            $bDryRun,
            $sRecordLabel
        ));
    }

    /**
     * Warm the two hero derivatives one product's preview picture needs.
     *
     * The product gallery is not walked. Nothing on either product page draws a
     * product gallery entry: the hero track is built from the offer labels and
     * the sheet rows are offer pictures, so a 600px fit of a product gallery
     * entry would be a file with no caller (D-13).
     *
     * @return array{warmed: int, failed: int, skipped: int, truncated: int}
     */
    public static function warmProductPictures(Product $obProduct, bool $bDryRun): array
    {
        $iProductId = (int) $obProduct->id;
        if ($iProductId <= 0) {
            throw new InvalidArgumentException('ImageWarmer: an unsaved product has no pictures to warm');
        }

        $obPreviewImage = $obProduct->preview_image;
        if ($obPreviewImage === null) {
            return self::makeCounts();
        }

        return self::warmPicture(
            $obPreviewImage,
            self::PRODUCT_PREVIEW_SLOT_LIST,
            $bDryRun,
            'product '.$iProductId
        );
    }

    /**
     * Warm one picture in a named list of slots.
     *
     * Public because the attach job warms a single File row it loaded by id and
     * has to ask for the very same slot list the command hands this class.
     *
     * @param list<string> $arSlotList one of the three *_SLOT_LIST constants
     * @return array{warmed: int, failed: int, skipped: int, truncated: int}
     */
    public static function warmPicture(File $obImage, array $arSlotList, bool $bDryRun, string $sRecordLabel): array
    {
        if ($arSlotList === []) {
            throw new InvalidArgumentException('ImageWarmer: warmPicture needs at least one slot');
        }
        if ($sRecordLabel === '') {
            throw new InvalidArgumentException('ImageWarmer: warmPicture needs a record label for the warning log');
        }

        $arCounts = self::makeCounts();
        foreach ($arSlotList as $sSlot) {
            $arCounts[self::warmImage($obImage, $sSlot, $bDryRun, $sRecordLabel)]++;
        }

        return $arCounts;
    }

    /**
     * The sheet preview slot of every gallery picture, capped and deduped.
     *
     * @param iterable<File> $obImageList
     * @return array{warmed: int, failed: int, skipped: int, truncated: int}
     */
    protected static function warmGalleryPictures(
        $obImageList,
        ?File $obPreviewImage,
        bool $bDryRun,
        string $sRecordLabel
    ): array {
        $arCounts = self::makeCounts();
        $sPreviewFileKey = $obPreviewImage === null ? '' : self::makeFileKey($obPreviewImage);
        $iGalleryCount = 0;

        foreach ($obImageList as $obImage) {
            if ($iGalleryCount >= self::GALLERY_IMAGE_CEILING) {
                $arCounts[self::COUNT_TRUNCATED] = 1;
                break;
            }
            // the import routinely re-attaches the preview photograph as a
            // gallery entry; the sheet dedupes it away, so its derivative
            // would be generated for nothing
            if (self::makeFileKey($obImage) === $sPreviewFileKey) {
                continue;
            }
            $arCounts = self::mergeCounts($arCounts, self::warmPicture(
                $obImage,
                self::GALLERY_SLOT_LIST,
                $bDryRun,
                $sRecordLabel
            ));
            $iGalleryCount++;
        }

        return $arCounts;
    }

    /**
     * Generate one derivative and report the outcome.
     *
     * A single unreadable or unencodable source must not abort a 6819-offer run,
     * so this is the one place in this phase that swallows - and it counts and
     * logs every one it swallows, naming the record, the file id, the slot and
     * the message. Everywhere else, fail fast.
     *
     * A slot name outside the four is an UnhandledMatchError, counted and
     * logged like any other failure and in a dry run too: warmPicture() is
     * public and takes any list, and a mistyped slot that fell through to one
     * of the real derivatives would report the wrong file as warmed.
     *
     * @return string one of OUTCOME_WARMED, OUTCOME_FAILED, OUTCOME_SKIPPED
     */
    protected static function warmImage(File $obImage, string $sSlot, bool $bDryRun, string $sRecordLabel): string
    {
        try {
            if ($sSlot === OfferImageHelper::SLOT_HERO_PHONE && !self::isPhoneHeroSource($obImage)) {
                return self::OUTCOME_SKIPPED;
            }
            $fnRender = match ($sSlot) {
                OfferImageHelper::SLOT_SWATCH     => OfferImageHelper::swatch(...),
                OfferImageHelper::SLOT_PREVIEW    => OfferImageHelper::preview(...),
                OfferImageHelper::SLOT_HERO       => OfferImageHelper::hero(...),
                OfferImageHelper::SLOT_HERO_PHONE => OfferImageHelper::heroPhone(...),
            };
            if ($bDryRun) {
                return self::OUTCOME_WARMED;
            }

            $sUrl = $fnRender($obImage);
            if ($sUrl === '') {
                throw new RuntimeException('resizer returned an empty URL');
            }

            return self::OUTCOME_WARMED;
        } catch (Throwable $obException) {
            Log::warning(sprintf(
                'image-warmer: %s, file %s, slot %s: %s',
                $sRecordLabel,
                (string) $obImage->id,
                $sSlot,
                $obException->getMessage()
            ));

            return self::OUTCOME_FAILED;
        }
    }

    /**
     * Whether the phone hero derivative this source would produce is one a
     * template can ever name.
     *
     * OfferImageHelper::heroPhone() serves a source shorter than
     * HERO_MIN_SOURCE_HEIGHT at HERO_SMALL_WIDTH rather than upscaling it 2.7x
     * into the 780x680 box, while heroPhoneIfWarm() answers '' for that same
     * source before it looks at the disk at all (D-21, plan 04-05). Warming the
     * slot anyway would write a thumb_{id}_300_0_crop.webp that no lookup asks
     * for, on every shop, forever. So the slot is skipped for a short source and
     * those shades stay cold by design: the rail falls back to the 600px preview,
     * which is what D-21 intends.
     */
    protected static function isPhoneHeroSource(File $obImage): bool
    {
        return (int) $obImage->height >= OfferImageHelper::HERO_MIN_SOURCE_HEIGHT;
    }

    /**
     * The identity a gallery entry is deduped by: the file name and the byte
     * size, not the row id, because the import attaches a second row for the
     * same photograph rather than reusing the preview row.
     */
    protected static function makeFileKey(File $obImage): string
    {
        return $obImage->file_name.'|'.$obImage->file_size;
    }

    /**
     * @return array{warmed: int, failed: int, skipped: int, truncated: int}
     */
    protected static function makeCounts(): array
    {
        return [
            self::OUTCOME_WARMED  => 0,
            self::OUTCOME_FAILED  => 0,
            self::OUTCOME_SKIPPED => 0,
            self::COUNT_TRUNCATED => 0,
        ];
    }

    /**
     * Add one counts array into another, key by key.
     *
     * @param array{warmed: int, failed: int, skipped: int, truncated: int} $arCounts
     * @param array{warmed: int, failed: int, skipped: int, truncated: int} $arAddition
     * @return array{warmed: int, failed: int, skipped: int, truncated: int}
     */
    protected static function mergeCounts(array $arCounts, array $arAddition): array
    {
        foreach ($arCounts as $sKey => $iValue) {
            $arCounts[$sKey] = $iValue + (int) ($arAddition[$sKey] ?? 0);
        }

        return $arCounts;
    }
}
