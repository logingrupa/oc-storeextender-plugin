<?php namespace Logingrupa\StoreExtender\Classes\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Logingrupa\StoreExtender\Classes\Helper\ImageWarmer;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use System\Models\File;
use Throwable;

/**
 * Warms one attached picture's derivatives off the request.
 *
 * The deploy warm step generates every derivative the shop needs, but the 1C
 * import runs between deploys, and a picture it attaches after a release stays
 * cold until the next one. Cold means the first visitor pays 91-133 ms of
 * resize inside their own request, and on the phone hero the rail falls back to
 * the 600px preview because the warm lookup answers '' (D-12).
 *
 * The payload is a file ID and nothing else. A SerializesModels File would be
 * re-resolved at run time, and the import deletes and re-attaches rows freely,
 * so a job holding the model would FAIL on a row that is simply gone where this
 * one returns quietly. It also means SerializesModels has nothing to rehydrate.
 *
 * Which slots a picture is asked for is read from the row this job loads, not
 * from what the dispatcher believed: a queue payload is an integer somebody
 * else chose, not a capability. `System\Models\File::getThumbUrl()` routes a
 * non-public attachment through the backend Files controller, so a job that
 * warmed any file id it was handed could publish a private attachment's
 * derivative. SLOT_LIST_MATRIX is the allow-list that prevents it.
 *
 * ShouldBeUnique, keyed on the file id, and NO delay. The two are one decision:
 * a full 1C import re-saves thousands of File rows onto the `default` queue
 * that also carries Metapixel's CAPI events, and October re-saves the same row
 * more than once per request whenever a gallery is re-ordered or a field is
 * touched after the morph write. Uniqueness collapses those repeats and keeps
 * two workers off the same source file at once. A delay spread was rejected:
 * it moves the same work later without making it smaller, and the case this
 * job exists for is one owner attaching one picture and then looking at the
 * page. The job is idempotent and cheap when warm (one file_exists per
 * derivative), so this is queue fairness, not correctness.
 *
 * AFTER COMMIT, always. The dispatch happens inside the model event, which is
 * inside whatever transaction attached the picture, and a worker that took
 * the job before that transaction committed would find no row and stop
 * quietly, indistinguishable from a picture that was deleted. The constructor
 * sets the flag rather than the dispatcher, so every dispatch path is covered.
 */
class WarmImageDerivatives implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int one initial attempt plus two retries */
    public int $tries = 3;

    /** @var list<int> backoff seconds, widening: a failed resize is usually a busy disk */
    public array $backoff = [5, 30, 120];

    /** @var int seconds the uniqueness lock outlives a worker that died mid-job */
    public int $uniqueFor = 300;

    /**
     * Which slot list each watched attachment lands in.
     *
     * The same three lists the deploy command walks, asked for through the same
     * class, so the command and this job cannot drift. A Product `images` entry
     * is deliberately absent: the product gallery renders at 700x570 and
     * 100x100 on /p and is never drawn in the 600px sheet preview slot, so
     * warming it would write a file no template can name (04-08, D-13).
     */
    const SLOT_LIST_MATRIX = [
        Offer::class => [
            'preview_image' => ImageWarmer::OFFER_PREVIEW_SLOT_LIST,
            'images'        => ImageWarmer::GALLERY_SLOT_LIST,
        ],
        Product::class => [
            'preview_image' => ImageWarmer::PRODUCT_PREVIEW_SLOT_LIST,
        ],
    ];

    /** How the warm log names the record a picture hangs on */
    const RECORD_LABEL_LIST = [
        Offer::class   => 'offer',
        Product::class => 'product',
    ];

    /**
     * @param int $iFileId the system_files row to warm
     */
    public function __construct(public readonly int $iFileId)
    {
        if ($iFileId <= 0) {
            throw new InvalidArgumentException(
                'WarmImageDerivatives: a file id must be positive, got '.$iFileId
            );
        }
        // assigned, not declared: the Queueable trait declares the property
        // and PHP rejects a redeclaration with a different default
        $this->afterCommit = true;
    }

    /**
     * Warm every derivative the loaded row's slot list names.
     */
    public function handle(): void
    {
        $obFile = $this->findFile();
        if ($obFile === null) {
            // the import deleted and re-attached the picture between dispatch
            // and run; the row that replaced it carries its own job
            return;
        }

        $arSlotList = self::readSlotList($obFile);
        if ($arSlotList === []) {
            return;
        }

        ImageWarmer::warmPicture($obFile, $arSlotList, false, self::makeRecordLabel($obFile));
    }

    /**
     * Report once when the retries are spent.
     *
     * No dead-letter row: a cold derivative is not lost data, it is one slow
     * first request that the next deploy's warm step repairs.
     *
     * @param Throwable $obException why the last attempt failed
     */
    public function failed(Throwable $obException): void
    {
        Log::warning(sprintf(
            'warm-image-derivatives: file %d gave up after %d attempts: %s',
            $this->iFileId,
            $this->tries,
            $obException->getMessage()
        ));
    }

    /**
     * The lock key: one queued warm per picture at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->iFileId;
    }

    /**
     * The slots this picture is drawn in, empty when nothing draws it.
     *
     * @return list<string>
     */
    protected static function readSlotList(File $obFile): array
    {
        $sAttachmentType = (string) $obFile->attachment_type;
        $sField = (string) $obFile->field;

        return self::SLOT_LIST_MATRIX[$sAttachmentType][$sField] ?? [];
    }

    /**
     * The record label ImageWarmer prints in a warm warning, e.g. "offer 4200".
     */
    protected static function makeRecordLabel(File $obFile): string
    {
        $sAttachmentType = (string) $obFile->attachment_type;
        $sRecordName = self::RECORD_LABEL_LIST[$sAttachmentType] ?? 'record';

        return $sRecordName.' '.(int) $obFile->attachment_id;
    }

    /**
     * The File row this job was dispatched for, or null when it is gone.
     *
     * Its own method because it is the job's only database touch: a test drives
     * handle() through a recording picture, having neither a storage disk nor a
     * stored source to resize.
     */
    protected function findFile(): ?File
    {
        return File::find($this->iFileId);
    }
}
