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
 * The rule is deliberately coarse: it names the two attachment types whose
 * pictures the storefront draws, and the two fields they hang on. Which slots a
 * picture is actually warmed in is the job's decision, read off the row it
 * loads, because the dispatch is a hint and the loaded row is the fact.
 */
class WarmDerivativesOnAttach
{
    /**
     * The attachment types whose pictures are public storefront images.
     *
     * Everything else - a settings logo, an order attachment, a theme upload,
     * a backend user avatar - is out. Some of those are private attachments,
     * and System\Models\File::getThumbUrl() routes a private file's derivative
     * through the backend Files controller, so warming one blindly is how a
     * private attachment gets published.
     */
    const WATCHED_TYPE_LIST = [
        Offer::class,
        Product::class,
    ];

    /** The attachOne and attachMany fields those pictures hang on */
    const WATCHED_FIELD_LIST = [
        'preview_image',
        'images',
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

        $sAttachmentType = $obFile->attachment_type ?? null;
        if (!in_array($sAttachmentType, self::WATCHED_TYPE_LIST, true)) {
            return false;
        }

        return in_array($obFile->field ?? null, self::WATCHED_FIELD_LIST, true);
    }
}
