<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;

/**
 * The nail slot of the collections hub.
 *
 * The shade shots are a 1321x1080 landscape frame with a portrait nail in the
 * middle and roughly a third of the width empty on each side. A square fit
 * drew a nail a quarter of its box, which is why the hub's first two drafts
 * read as "too small"; the fix is a centre crop at the nail's own ratio.
 *
 * Both halves of that are pinned here because both are load-bearing. Crop,
 * because a fit would bring the empty frame back. And a box TALLER than it is
 * wide, because the crop keeps whichever dimension the target needs - a
 * square or landscape target would cut the nail's tip and cuticle off, which
 * is the one thing the design may not do.
 */
class OfferNailThumbTest extends TestCase
{
    public function testTheNailIsCroppedNotFitted()
    {
        $arOptions = OfferImageHelper::getNailThumbOptions();

        $this->assertSame('crop', $arOptions['mode'], 'a fit would letterbox the empty frame back in');
        $this->assertSame('webp', $arOptions['extension'], 'webp keeps the alpha of a cut-out shot');
    }

    public function testTheNailBoxIsPortraitSoTheFormSurvivesTheCrop()
    {
        $this->assertGreaterThan(
            OfferImageHelper::NAIL_WIDTH,
            OfferImageHelper::NAIL_HEIGHT,
            'a square or landscape box would crop the tip and the cuticle away'
        );
    }

    public function testTheNailBoxKeepsTheMeasuredNailRatio()
    {
        // 385/820 is the median width/height of the nail inside its frame,
        // measured across the catalogue; the crop leaves 94% nail
        $fRatio = OfferImageHelper::NAIL_WIDTH / OfferImageHelper::NAIL_HEIGHT;

        $this->assertEqualsWithDelta(0.47, $fRatio, 0.02, 'the box has drifted off the nail ratio');
    }

    public function testAnOfferWithNoPictureAnswersAnEmptyString()
    {
        $this->assertSame('', OfferImageHelper::nail(null), 'no picture is a real state, not a throw');
    }
}
