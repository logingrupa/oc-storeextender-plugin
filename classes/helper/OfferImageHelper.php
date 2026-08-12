<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use October\Rain\Database\Attach\File;

/**
 * The one place offer image derivative sizes are decided.
 *
 * Every offer picture on the storefront lands in one of two CSS boxes, so
 * there are two sizes here and no more. Sharing one size across call sites is
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

        $arOptions = [
            'mode'      => 'auto',
            'quality'   => self::HERO_QUALITY,
            'extension' => self::THUMB_EXTENSION,
        ];
        if ((int) $obImage->height < self::HERO_MIN_SOURCE_HEIGHT) {
            return (string) $obImage->getThumb(self::HERO_SMALL_WIDTH, 'auto', $arOptions);
        }

        return (string) $obImage->getThumb(self::HERO_WIDTH, self::HERO_HEIGHT, $arOptions);
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
