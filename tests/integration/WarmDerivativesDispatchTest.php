<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Logingrupa\StoreExtender\Classes\Queue\WarmImageDerivatives;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use System\Models\File;

/**
 * The registration, end to end, with the queue faked.
 *
 * These cases save a real System\Models\File row so ELOQUENT fires the event,
 * rather than firing the event name by hand. The event name is half the
 * contract: `eloquent.saved: System\Models\File` written one character wrong in
 * Plugin::boot() registers a listener nothing ever calls, and a hand-fired
 * event would agree with the typo and pass.
 *
 * Nothing reaches a real queue. The local connection is `database` with tens of
 * thousands of stuck rows and no worker, so a dispatched job would sit in that
 * backlog forever; Queue::fake() is the only honest way to assert a push here.
 */
class WarmDerivativesDispatchTest extends StoreExtenderPluginTestCase
{
    /** Core module tables are migrated in createApplication(); no plugin schema is touched. */
    protected $autoMigrate = false;

    /**
     * Save a picture row the way an attachment lands, without a stored file.
     *
     * @return \System\Models\File
     */
    protected function saveFile(?string $sAttachmentType, ?string $sField)
    {
        $obFile = new File();
        $obFile->attachment_type = $sAttachmentType;
        $obFile->attachment_id = 4200;
        $obFile->field = $sField;
        $obFile->disk_name = 'picture.jpg';
        $obFile->file_name = 'picture.jpg';
        $obFile->file_size = 11505;
        $obFile->content_type = 'image/jpeg';
        $obFile->is_public = true;
        $obFile->save();

        return $obFile;
    }

    public function testASavedOfferPreviewPictureEnqueuesExactlyOneWarmJobCarryingItsId()
    {
        Queue::fake();
        $obFile = $this->saveFile(Offer::class, 'preview_image');

        Queue::assertPushed(WarmImageDerivatives::class, 1);
        Queue::assertPushed(WarmImageDerivatives::class, function ($obJob) use ($obFile) {
            return $obJob->iFileId === (int) $obFile->id;
        });
    }

    public function testASavedProductPreviewPictureEnqueuesOneWarmJob()
    {
        Queue::fake();
        $this->saveFile(Product::class, 'preview_image');

        Queue::assertPushed(WarmImageDerivatives::class, 1);
    }

    public function testASavedAttachmentOfAnotherModelEnqueuesNothing()
    {
        Queue::fake();
        $this->saveFile('Backend\Models\User', 'avatar');

        Queue::assertNothingPushed();
    }

    public function testASavedFileWithNoAttachmentEnqueuesNothing()
    {
        Queue::fake();
        $this->saveFile(null, null);

        Queue::assertNothingPushed();
    }

    public function testRetryExhaustionIsLoggedOnceAndSwallowed()
    {
        Log::spy();

        (new WarmImageDerivatives(10505))->failed(new RuntimeException('source is unreadable'));

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($sMessage) {
            return strpos($sMessage, '10505') !== false
                && strpos($sMessage, 'source is unreadable') !== false;
        });
    }
}
