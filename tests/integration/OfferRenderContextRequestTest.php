<?php

use Logingrupa\StoreExtender\Classes\Helper\OfferRenderContext;

/**
 * What the offer render rule does when NO caller pinned a shade: the plain page
 * render, and the legacy per-shade AJAX render.
 *
 * The interesting half is bOfferSelected, which is what the h3 above the price
 * is decided by. A canonical /p/<slug> render shows the PRODUCT name; a shade
 * the visitor actually chose shows that shade's name. That used to be read
 * straight off the URL, so the title only ever renamed itself after something
 * had written a shade into the address bar - which the inline swatch strip never
 * does. The first click on a shade circle changed the price and the picture and
 * left the heading describing the product.
 *
 * Core modules only, no plugin schema: nothing below resolves a product, and
 * Lovata's update_table_offers_remove_price_field migration does not run on
 * SQLite anyway (see XmlImportPriceFlickerTest). The app is needed only because
 * the rule reads request input.
 */
class OfferRenderContextRequestTest extends PluginTestCase
{
    /** @var bool No plugin schema is required, and Shopaholic's will not build here. */
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrateModules();
    }

    /**
     * A canonical product URL: no shade was asked for, so the page keeps its own
     * offer and the heading stays the product name.
     */
    public function testAPlainPageRenderResolvesNothingAndChoosesNoShade()
    {
        $arContext = OfferRenderContext::resolve(null, null);

        $this->assertNull($arContext['obProduct']);
        $this->assertNull($arContext['obOffer']);
        $this->assertFalse($arContext['bOfferSelected']);
    }

    /**
     * A shared /p/<slug>/<offer> link. The offer itself is NOT resolved here -
     * the page did that once in onInit - but the render knows a shade was named,
     * which is what the heading and the video ordering turn on.
     */
    public function testAnOfferInTheUrlCountsAsChosenWithoutResolvingIt()
    {
        $arContext = OfferRenderContext::resolve(null, '4150');

        $this->assertTrue($arContext['bOfferSelected']);
        $this->assertNull($arContext['obOffer'], 'the page already resolved its own offer; resolving it twice is how the two disagreed');
    }

    /**
     * Route params arrive as strings and a slug-shaped one is not an offer id.
     */
    public function testANonNumericUrlSegmentIsNotAChosenShade()
    {
        $this->assertFalse(OfferRenderContext::resolve(null, 'gellaka-uvled')['bOfferSelected']);
        $this->assertFalse(OfferRenderContext::resolve(null, '0')['bOfferSelected']);
        $this->assertFalse(OfferRenderContext::resolve(null, '')['bOfferSelected']);
    }

    /**
     * The batch handler must never name its offer product_id/offer_id, because
     * one response renders twelve shades and those names would put one shade's id
     * in front of all twelve renders. The guard is here rather than in the
     * component because it is a property of THIS rule: request input is read at
     * all, so a batch that used those names would be obeyed.
     *
     * Reflection over the handler's source, not a rendered response: the
     * alternative needs a Shopaholic schema, which does not build on SQLite.
     */
    public function testTheBatchHandlerDoesNotSpeakInRequestOfferNames()
    {
        $sSource = file_get_contents(
            plugins_path('logingrupa/storeextender/components/OfferSheet.php')
        );

        $this->assertStringContainsString("input('batch_offer_ids')", $sSource);
        $this->assertStringNotContainsString("'offer_id' =>", $sSource);
        $this->assertStringContainsString("'obRenderOffer' => \$obOfferItem", $sSource);
    }
}
