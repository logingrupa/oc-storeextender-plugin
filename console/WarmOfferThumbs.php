<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use Lovata\Shopaholic\Models\Offer;
use Throwable;

/**
 * Generate the offer image derivatives ahead of traffic.
 *
 * October writes a thumbnail the first time a page asks for it, inline, on
 * that visitor's request. A 230-shade product opens its sheet with 200 row
 * pictures at once, so the first visitor after a deploy - or after the first
 * import that adds a size - pays for every one of them. Run this once after
 * deploying and that visitor pays for none.
 *
 * Bounded and resumable by design:
 *   - offers are walked in id order in chunks, never loaded all at once;
 *   - --from-id resumes a run that was killed, and the last id is printed
 *     every chunk so there is always something to resume from;
 *   - --limit caps a single run, so this can be spread over several windows;
 *   - a gallery deeper than GALLERY_IMAGE_CEILING is truncated and reported,
 *     rather than letting one bad record run unbounded.
 *
 * Already-generated derivatives cost one file_exists each: File::getThumbUrl
 * returns early when the file is there, so re-running is cheap and safe.
 *
 *   php artisan storeextender:warm-offer-thumbs
 *   php artisan storeextender:warm-offer-thumbs --from-id=4200 --limit=1000
 */
class WarmOfferThumbs extends Command
{
    /** Offers loaded per database chunk */
    const CHUNK_SIZE = 200;

    /** Gallery pictures warmed per offer before the rest are reported and skipped */
    const GALLERY_IMAGE_CEILING = 20;

    /** @var string */
    protected $signature = 'storeextender:warm-offer-thumbs
        {--from-id=0 : Resume from this offer id (inclusive)}
        {--limit=0 : Stop after this many offers (0 = every offer)}
        {--dry-run : Report what would be generated without writing any file}';

    /** @var string */
    protected $description = 'Pre-generate the sized offer image derivatives the storefront asks for';

    /** @var int */
    protected $iOfferCount = 0;

    /** @var int */
    protected $iThumbCount = 0;

    /** @var int */
    protected $iFailureCount = 0;

    /** @var int */
    protected $iTruncatedGalleryCount = 0;

    /** @var int */
    protected $iLastOfferId = 0;

    public function handle(): int
    {
        $iFromId = (int) $this->option('from-id');
        $iLimit = (int) $this->option('limit');
        if ($iFromId < 0 || $iLimit < 0) {
            $this->error('--from-id and --limit must not be negative.');

            return self::FAILURE;
        }

        $bDryRun = (bool) $this->option('dry-run');
        $this->line(sprintf(
            'Warming offer thumbs: swatch %dpx, preview %dpx, %s q%d%s',
            OfferImageHelper::SIZE_SWATCH,
            OfferImageHelper::SIZE_PREVIEW,
            OfferImageHelper::THUMB_EXTENSION,
            OfferImageHelper::THUMB_QUALITY,
            $bDryRun ? ' (dry run)' : ''
        ));

        $this->walkOfferList($iFromId, $iLimit, $bDryRun);

        $this->line(sprintf(
            'Done: %d offers, %d derivatives%s, %d failures, last offer id %d.',
            $this->iOfferCount,
            $this->iThumbCount,
            $bDryRun ? ' would be generated' : ' present',
            $this->iFailureCount,
            $this->iLastOfferId
        ));
        if ($this->iTruncatedGalleryCount > 0) {
            $this->warn(sprintf(
                '%d offers have more than %d gallery pictures - the rest were skipped.',
                $this->iTruncatedGalleryCount,
                self::GALLERY_IMAGE_CEILING
            ));
        }

        return $this->iFailureCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Walk offers in id order, in chunks, stopping at the limit.
     */
    protected function walkOfferList(int $iFromId, int $iLimit, bool $bDryRun): void
    {
        Offer::query()
            ->where('id', '>=', $iFromId)
            ->with(['preview_image', 'images'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($obOfferChunk) use ($iLimit, $bDryRun) {
                foreach ($obOfferChunk as $obOffer) {
                    if ($iLimit > 0 && $this->iOfferCount >= $iLimit) {
                        return false; // limit reached: stop the walk
                    }
                    $this->warmOffer($obOffer, $bDryRun);
                    $this->iOfferCount++;
                    $this->iLastOfferId = (int) $obOffer->id;
                }

                $this->line(sprintf(
                    '  %d offers, %d derivatives, through offer id %d',
                    $this->iOfferCount,
                    $this->iThumbCount,
                    $this->iLastOfferId
                ));

                return $iLimit === 0 || $this->iOfferCount < $iLimit;
            });
    }

    /**
     * Both slots of the preview image, plus the preview slot of every gallery
     * picture - the batch that a sheet open and a shade pick between them ask
     * for.
     *
     * @param \Lovata\Shopaholic\Models\Offer $obOffer
     */
    protected function warmOffer($obOffer, bool $bDryRun): void
    {
        $obPreviewImage = $obOffer->preview_image;
        if ($obPreviewImage !== null) {
            $this->warmImage($obPreviewImage, OfferImageHelper::SLOT_SWATCH, $bDryRun, (int) $obOffer->id);
            $this->warmImage($obPreviewImage, OfferImageHelper::SLOT_PREVIEW, $bDryRun, (int) $obOffer->id);
        }

        $iGalleryIndex = 0;
        foreach ($obOffer->images as $obImage) {
            if ($iGalleryIndex >= self::GALLERY_IMAGE_CEILING) {
                $this->iTruncatedGalleryCount++;
                break;
            }
            $this->warmImage($obImage, OfferImageHelper::SLOT_PREVIEW, $bDryRun, (int) $obOffer->id);
            $iGalleryIndex++;
        }
    }

    /**
     * Generate one derivative. A single unreadable or unencodable source must
     * not abort a 6819-offer run, so this is the one place that swallows -
     * and it counts and reports every one it swallows.
     *
     * @param \October\Rain\Database\Attach\File $obImage
     */
    protected function warmImage($obImage, string $sSlot, bool $bDryRun, int $iOfferId): void
    {
        if ($bDryRun) {
            $this->iThumbCount++;

            return;
        }

        try {
            $sUrl = $sSlot === OfferImageHelper::SLOT_SWATCH
                ? OfferImageHelper::swatch($obImage)
                : OfferImageHelper::preview($obImage);
            if ($sUrl === '') {
                throw new \RuntimeException('resizer returned an empty URL');
            }
            $this->iThumbCount++;
        } catch (Throwable $obException) {
            $this->iFailureCount++;
            Log::warning(sprintf(
                'warm-offer-thumbs: offer %d, file %s, slot %s: %s',
                $iOfferId,
                (string) $obImage->id,
                $sSlot,
                $obException->getMessage()
            ));
        }
    }
}
