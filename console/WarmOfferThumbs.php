<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Helper\ImageWarmer;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Generate the offer image derivatives ahead of traffic.
 *
 * October writes a thumbnail the first time a page asks for it, inline, on
 * that visitor's request. A 230-shade product opens its sheet with 200 row
 * pictures at once, so the first visitor after a deploy - or after the first
 * import that adds a size - pays for every one of them. Run this once after
 * deploying and that visitor pays for none.
 *
 * WHICH derivatives a record needs is not decided here: ImageWarmer owns the
 * matrix, because the attach job asks the same question about a picture the
 * import just landed. This command owns the walk, the chunking and the counting.
 *
 * Two passes: every product preview picture first, then every offer picture.
 *
 * Bounded and resumable by design:
 *   - offers are walked in id order in chunks, never loaded all at once;
 *   - --from-id resumes a run that was killed, and the last id is printed
 *     every chunk so there is always something to resume from;
 *   - --limit caps a single run, so this can be spread over several windows;
 *   - a gallery deeper than ImageWarmer::GALLERY_IMAGE_CEILING is truncated and
 *     reported, rather than letting one bad record run unbounded.
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

    /** @var string */
    protected $signature = 'storeextender:warm-offer-thumbs
        {--from-id=0 : Resume from this offer id (inclusive)}
        {--limit=0 : Stop after this many offers (0 = every offer)}
        {--dry-run : Report what would be generated without writing any file}';

    /** @var string */
    protected $description = 'Pre-generate the sized offer image derivatives the storefront asks for';

    /** @var int */
    protected $iProductCount = 0;

    /** @var int */
    protected $iOfferCount = 0;

    /** @var int */
    protected $iThumbCount = 0;

    /** @var int */
    protected $iFailureCount = 0;

    /** @var int */
    protected $iSkippedCount = 0;

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
            'Warming offer thumbs: swatch %dpx, preview %dpx, hero %dx%d q%d, phone hero %dx%d q%d, %s%s',
            OfferImageHelper::SIZE_SWATCH,
            OfferImageHelper::SIZE_PREVIEW,
            OfferImageHelper::HERO_WIDTH,
            OfferImageHelper::HERO_HEIGHT,
            OfferImageHelper::HERO_QUALITY,
            OfferImageHelper::HERO_PHONE_WIDTH,
            OfferImageHelper::HERO_PHONE_HEIGHT,
            OfferImageHelper::HERO_PHONE_QUALITY,
            OfferImageHelper::THUMB_EXTENSION,
            $bDryRun ? ' (dry run)' : ''
        ));

        $this->walkProductList($bDryRun);
        $this->walkOfferList($iFromId, $iLimit, $bDryRun);

        $this->line(sprintf(
            'Done: %d products, %d offers, %d derivatives%s, %d skipped, %d failures, last offer id %d.',
            $this->iProductCount,
            $this->iOfferCount,
            $this->iThumbCount,
            $bDryRun ? ' would be generated' : ' present',
            $this->iSkippedCount,
            $this->iFailureCount,
            $this->iLastOfferId
        ));
        if ($this->iTruncatedGalleryCount > 0) {
            $this->warn(sprintf(
                '%d offers have more than %d gallery pictures - the rest were skipped.',
                $this->iTruncatedGalleryCount,
                ImageWarmer::GALLERY_IMAGE_CEILING
            ));
        }

        return $this->iFailureCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Walk every product in id order, in chunks, warming both hero slots of its
     * preview picture.
     *
     * Runs FIRST and unconditionally, before the offer walk and outside
     * --from-id and --limit. Those two options mean "offer id" and "offers", and
     * a pass that honoured them would either make them ambiguous or need a
     * second pair of options. It costs nothing to leave them alone: 667 products
     * at two derivatives each is seconds, and the pass has no resume need
     * because a killed run repeats it for one file_exists per derivative.
     */
    protected function walkProductList(bool $bDryRun): void
    {
        Product::query()
            ->with(['preview_image'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($obProductChunk) use ($bDryRun) {
                foreach ($obProductChunk as $obProduct) {
                    $this->recordCounts(ImageWarmer::warmProductPictures($obProduct, $bDryRun));
                    $this->iProductCount++;
                }

                $this->line(sprintf(
                    '  %d products, %d derivatives',
                    $this->iProductCount,
                    $this->iThumbCount
                ));
            });
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
                    $this->recordCounts(ImageWarmer::warmOfferPictures($obOffer, $bDryRun));
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
     * Fold one record's outcome counts into the run totals.
     *
     * @param array{warmed: int, failed: int, skipped: int, truncated: int} $arCounts
     */
    protected function recordCounts(array $arCounts): void
    {
        $this->iThumbCount += $arCounts[ImageWarmer::OUTCOME_WARMED];
        $this->iFailureCount += $arCounts[ImageWarmer::OUTCOME_FAILED];
        $this->iSkippedCount += $arCounts[ImageWarmer::OUTCOME_SKIPPED];
        $this->iTruncatedGalleryCount += $arCounts[ImageWarmer::COUNT_TRUNCATED];
    }
}
