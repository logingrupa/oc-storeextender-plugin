<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Database\Attach\File;

/**
 * The one place offer image derivative sizes are decided.
 *
 * Every offer picture on the storefront lands in a known CSS box, and each box
 * gets one derivative size and no more. Sharing one size across call sites is
 * not tidiness: the product page swatch strip and the sheet rows draw the SAME
 * pictures, so a single swatch size means the sheet's 200 row images come out
 * of the browser cache the strip already filled.
 *
 * Sizes are read off the boxes the pictures actually render into
 * (themes/logingrupa-naisstore/src/css/partials/_offer-sheet.scss and
 * _offer-swatches.scss):
 *
 *   SLOT_SWATCH   .hr-sheet-offers__item img   48x48, object-fit: cover
 *                 .offer-swatches__item img    45x45, object-fit: cover
 *                 -> 96 cropped, which is 2x the larger of the two
 *
 *   SLOT_PREVIEW  .hr-sheet__preview-image     100% wide, 240px tall desktop,
 *                                              33dvh on phones, object-fit: contain
 *                 the panel is min(420px, 92vw), so a square picture is bounded
 *                 by the height: 240 CSS px desktop, ~260-280 on a phone
 *                 -> 600 fitted, 2.5x desktop and ~2.2x on a phone
 *
 *   SLOT_HERO_PHONE  .pdp-hero__slide img      100vw wide and
 *                                              min(calc(100vw - 50px), 340px)
 *                                              tall, object-fit: cover
 *                    on a 390px phone that box resolves to 390x340 CSS px
 *                    -> 780x680 cropped, the box times DPR 2
 *
 * ARITY MATTERS HERE. File::getThumb($width, $height, $options) takes three
 * arguments. Passing a mode string AND an options array - getThumb(50, 50,
 * 'crop', {'quality': 80, 'extension': 'webp'}) - is not an error in PHP: the
 * fourth argument is discarded and File::getDefaultThumbOptions() rewrites the
 * third into ['mode' => 'crop'], so quality and extension are silently lost and
 * the derivative comes back in the source format. Every call goes through this
 * class so that shape cannot be written again.
 *
 * Twig, registered in Plugin::registerMarkupTags():
 *   {{ offer_swatch_src(obOffer.preview_image) }}
 *   {{ offer_preview_src(obOffer.preview_image) }}
 *   {{ offer_hero_src(obOffer.preview_image) }}
 *   {{ offer_hero_warm_src(obOffer.preview_image) }}   empty unless warmed
 *   {{ offer_hero_phone_src(obOffer.preview_image) }}
 *   {{ offer_hero_phone_warm_src(obOffer.preview_image) }}   empty unless warmed
 */
class OfferImageHelper
{
    /** 48px sheet row and 45px strip circle, both object-fit: cover, at 2x */
    const SLOT_SWATCH = 'swatch';
    const SIZE_SWATCH = 96;

    /** Sheet preview slot, object-fit: contain, at 2.2x-2.5x */
    const SLOT_PREVIEW = 'preview';
    const SIZE_PREVIEW = 600;

    /**
     * Product page main gallery image. Not a square slot like the other two:
     * the box is the gallery column, fitted. A source shorter than
     * HERO_MIN_SOURCE_HEIGHT is served at HERO_SMALL_WIDTH instead, because
     * upscaling a small source to 1000px buys bytes and no pixels.
     */
    const SLOT_HERO = 'hero';
    const HERO_WIDTH = 1000;
    const HERO_HEIGHT = 821;
    const HERO_QUALITY = 95;
    const HERO_MIN_SOURCE_HEIGHT = 300;
    const HERO_SMALL_WIDTH = 300;

    /**
     * Phone hero slide on /p2, cropped to the box a 390px phone measures.
     *
     * The box is `width: 100vw; height: min(calc(100vw - 50px), 340px);
     * object-fit: cover` in _pdp-hero.scss, which resolves to 390x340 CSS px on
     * a 390px viewport, so at DPR 2 the file is exactly 780x680. It is a CROP,
     * not a fit, because the box is object-fit: cover; a fitted derivative would
     * have needed 830px of width to cover the 680px height from a 1000x821
     * source, and that is 830px of pixels the box never shows.
     *
     * Quality 95 was picked on 2026-09-17 from nine pictures encoded at 80, 85,
     * 90 and 95 and read side by side at 390px DPR 2. The gate was colour
     * crispness on the bottle gradient, not bytes: medians ran 5.8, 7.0, 9.4 and
     * 14.6 KB against 66.1 KB for the 1000x821 slide file this replaces, so the
     * most expensive candidate is still a 78 percent cut.
     *
     * CHANGING HERO_PHONE_QUALITY AFTER THE FIRST WARM RUN REGENERATES NOTHING,
     * because quality is absent from File::getThumbFilename(): the name carries
     * the dimensions and the mode only, so the warm lookup keeps finding the file
     * the old quality wrote and hands it back. A revision needs every
     * thumb_*_780_680_crop.webp deleted on every shop first.
     */
    const SLOT_HERO_PHONE = 'hero_phone';
    const HERO_PHONE_WIDTH = 780;
    const HERO_PHONE_HEIGHT = 680;
    const HERO_PHONE_QUALITY = 95;

    const THUMB_QUALITY = 80;
    const THUMB_EXTENSION = 'webp';

    /**
     * Cropped circle for a swatch or a sheet row.
     *
     * Null is a real state - an offer without a picture - and the callers
     * render a lettered placeholder instead, so it returns an empty string
     * rather than throwing.
     */
    public static function swatch(?File $obImage): string
    {
        return self::renderThumb($obImage, self::SLOT_SWATCH);
    }

    /**
     * Fitted picture for the sheet's large preview slot.
     */
    public static function preview(?File $obImage): string
    {
        return self::renderThumb($obImage, self::SLOT_PREVIEW);
    }

    /**
     * Fitted picture for the product page's main gallery. The size rule lived
     * inline in the gallery partial, which meant the warm command could not
     * pre-generate the derivative and the first visitor per shade paid the
     * resize inside their request.
     */
    public static function hero(?File $obImage): string
    {
        if ($obImage === null) {
            return '';
        }

        $arOptions = self::getHeroThumbOptions();
        if ((int) $obImage->height < self::HERO_MIN_SOURCE_HEIGHT) {
            return (string) $obImage->getThumb(self::HERO_SMALL_WIDTH, 'auto', $arOptions);
        }

        return (string) $obImage->getThumb(self::HERO_WIDTH, self::HERO_HEIGHT, $arOptions);
    }

    /**
     * The resizer options of the hero slot. Public so a test can pin the shape,
     * and shared so hero() and heroIfWarm() can never name a different
     * derivative for the same picture.
     *
     * @return array{mode: string, quality: int, extension: string}
     */
    public static function getHeroThumbOptions(): array
    {
        return [
            'mode'      => 'auto',
            'quality'   => self::HERO_QUALITY,
            'extension' => self::THUMB_EXTENSION,
        ];
    }

    /**
     * The hero URL when the derivative is already on disk, and an empty string
     * when it is not.
     *
     * NEVER RESIZES. hero() above goes through File::getThumbUrl(), which
     * generates a missing derivative inside the request that asked for it -
     * measured at 167ms per picture, 36s for the 218 shades one rail render
     * carries. This one looks the file up and gives up instead, so a caller that
     * renders hundreds of labels costs one storage existence check each and the
     * cold shades fall back to whatever picture the caller already had.
     *
     * Warming is storeextender:warm-offer-thumbs' job (PERF-05).
     */
    public static function heroIfWarm(?File $obImage): string
    {
        if ($obImage === null || !$obImage->isImage()) {
            return '';
        }

        $arOptions = self::getHeroThumbOptions();
        $bSmallSource = (int) $obImage->height < self::HERO_MIN_SOURCE_HEIGHT;
        $iWidth = $bSmallSource ? self::HERO_SMALL_WIDTH : self::HERO_WIDTH;
        $iHeight = $bSmallSource ? 0 : self::HERO_HEIGHT;

        $sThumbFileName = $obImage->getThumbFilename($iWidth, $iHeight, $arOptions);
        if (!$obImage->getDisk()->exists($obImage->getDiskPath($sThumbFileName))) {
            return '';
        }

        return (string) $obImage->getPath($sThumbFileName);
    }

    /**
     * Cropped picture for the /p2 phone hero slide. May resize inside the
     * request, so only the eager slides call it: the opened slide and one
     * neighbour on each side, three pictures per page at most.
     *
     * The small-source branch mirrors hero(): a source shorter than
     * HERO_MIN_SOURCE_HEIGHT is served at HERO_SMALL_WIDTH rather than upscaled
     * 2.7x into the 780x680 box. That branch names a 300-wide file
     * heroPhoneIfWarm() never looks for, which is deliberate - the lookup twin
     * gives up on a short source so the rail falls through to the 600px preview
     * chain it already has.
     */
    public static function heroPhone(?File $obImage): string
    {
        if ($obImage === null) {
            return '';
        }

        $arOptions = self::getHeroPhoneThumbOptions();
        if ((int) $obImage->height < self::HERO_MIN_SOURCE_HEIGHT) {
            return (string) $obImage->getThumb(self::HERO_SMALL_WIDTH, 'auto', $arOptions);
        }

        return (string) $obImage->getThumb(self::HERO_PHONE_WIDTH, self::HERO_PHONE_HEIGHT, $arOptions);
    }

    /**
     * The resizer options of the phone hero slot. Public so a test can pin the
     * shape, and shared so heroPhone() and heroPhoneIfWarm() can never name a
     * different derivative for the same picture.
     *
     * @return array{mode: string, quality: int, extension: string}
     */
    public static function getHeroPhoneThumbOptions(): array
    {
        return [
            'mode'      => 'crop',
            'quality'   => self::HERO_PHONE_QUALITY,
            'extension' => self::THUMB_EXTENSION,
        ];
    }

    /**
     * The phone hero URL when the derivative is already on disk, and an empty
     * string when it is not.
     *
     * NEVER RESIZES, for the reason heroIfWarm() states above: the rail's rest
     * window carries up to 218 labels and a generated derivative is 91-133 ms
     * each, so one cold render through the generating twin would be a 20-29
     * second request.
     *
     * A source shorter than HERO_MIN_SOURCE_HEIGHT gets an empty string instead
     * of the 300px name heroPhone() writes, so a short picture falls through to
     * the caller's existing 600px preview or 96px circle with no new code.
     */
    public static function heroPhoneIfWarm(?File $obImage): string
    {
        if ($obImage === null || !$obImage->isImage()) {
            return '';
        }

        if ((int) $obImage->height < self::HERO_MIN_SOURCE_HEIGHT) {
            return '';
        }

        $arOptions = self::getHeroPhoneThumbOptions();
        $sThumbFileName = $obImage->getThumbFilename(self::HERO_PHONE_WIDTH, self::HERO_PHONE_HEIGHT, $arOptions);
        if (!$obImage->getDisk()->exists($obImage->getDiskPath($sThumbFileName))) {
            return '';
        }

        return (string) $obImage->getPath($sThumbFileName);
    }

    /**
     * Pixel size of one slot.
     */
    public static function getSlotSize(string $sSlot): int
    {
        self::assertSlot($sSlot);

        return $sSlot === self::SLOT_SWATCH ? self::SIZE_SWATCH : self::SIZE_PREVIEW;
    }

    /**
     * The options array October's resizer is handed for one slot. Public so a
     * test can pin the shape without a database or a stored file.
     *
     * A swatch is cropped because its box is object-fit: cover and a circle;
     * the preview is fitted because its box is object-fit: contain and cropping
     * would cut the bottle off.
     *
     * @return array{mode: string, quality: int, extension: string}
     */
    public static function getThumbOptions(string $sSlot): array
    {
        self::assertSlot($sSlot);

        return [
            'mode'      => $sSlot === self::SLOT_SWATCH ? 'crop' : 'auto',
            'quality'   => self::THUMB_QUALITY,
            'extension' => self::THUMB_EXTENSION,
        ];
    }

    /**
     * Derivative URL for one image in one slot, empty when there is no image.
     */
    protected static function renderThumb(?File $obImage, string $sSlot): string
    {
        if ($obImage === null) {
            return '';
        }

        $iSize = self::getSlotSize($sSlot);

        return (string) $obImage->getThumb($iSize, $iSize, self::getThumbOptions($sSlot));
    }

    /**
     * A slot name that is not one of the two is a typo in a template, and a
     * typo must not quietly fall through to the wrong size.
     */
    protected static function assertSlot(string $sSlot): void
    {
        if ($sSlot !== self::SLOT_SWATCH && $sSlot !== self::SLOT_PREVIEW) {
            throw new \InvalidArgumentException(
                sprintf('OfferImageHelper: unknown slot "%s" - expected "%s" or "%s"', $sSlot, self::SLOT_SWATCH, self::SLOT_PREVIEW)
            );
        }
    }
}
