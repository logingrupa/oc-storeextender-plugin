<?php namespace Logingrupa\StoreExtender\Classes\Event\Image;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * A picture attached to an offer or a product lands its own derivatives, because
 * the 1C import may not grow a fix-up step and a cold shade drops off the phone
 * hero onto the 600px preview until the next deploy warms it (D-12).
 *
 * This class is the match rule and nothing else. The registration is an
 * Event::listen on `eloquent.saved: System\Models\File` in Plugin::boot(), and
 * the warming is WarmImageDerivatives, so a test can ask "is this picture one of
 * ours" with a bare object, no event and no queue.
 *
 * SAVED, not CREATED. The import's update path deletes a picture's thumbs and
 * re-saves the EXISTING row with a new disk_name
 * (extendshopaholic/classes/helper/AbstractImportModelFromXML.php:925-939), so
 * the picture is cold while its id never changed. That is the case this exists
 * for, and it is an update.
 *
 * The rule names the attachment types whose pictures the storefront draws and,
 * per type, the fields the job has a slot list for. Which slots a picture is
 * actually warmed in is still the job's decision, read off the row it loads,
 * because the dispatch is a hint and the loaded row is the fact; this list only
 * keeps a job off the queue when that decision is already known to be "none".
 */
class WarmDerivativesOnAttach
{
    /**
     * The attachment types whose pictures are public storefront images, and
     * the attachOne and attachMany fields on each that a template ever names.
     *
     * The keys mirror WarmImageDerivatives::SLOT_LIST_MATRIX, and a Product
     * `images` entry is absent for the same reason it is absent there: /p
     * draws a product gallery entry at 700x570 and 100x100 and never in a
     * slot the job can warm, so its dispatch would be a File::find() and a
     * return, once per gallery picture per import.
     *
     * Every other type - a settings logo, an order attachment, a theme upload,
     * a backend user avatar - is out. Some of those are private attachments,
     * and System\Models\File::getThumbUrl() routes a private file's derivative
     * through the backend Files controller, so warming one blindly is how a
     * private attachment gets published.
     */
    const WATCHED_FIELD_LIST = [
        Offer::class   => ['preview_image', 'images'],
        Product::class => ['preview_image'],
    ];

    /**
     * Whether a saved file is a storefront picture worth a warm job.
     *
     * @param mixed $obFile a System\Models\File, or any object carrying the two
     *                      attributes; anything else answers false
     */
    public static function isWatched($obFile): bool
    {
        if (!is_object($obFile)) {
            return false;
        }

        $arWatchedFieldList = self::WATCHED_FIELD_LIST[$obFile->attachment_type ?? ''] ?? [];

        return in_array($obFile->field ?? null, $arWatchedFieldList, true);
    }
}
