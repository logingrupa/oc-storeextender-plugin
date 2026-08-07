<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * The one place it is decided WHICH offer a product-card fragment renders.
 *
 * Six fragment partials used to answer that question for themselves, each from
 * request state, and they did not agree. That disagreement was a bug the
 * storefront actually shipped:
 *
 *   The product page URL is /p/:slug/:offer?. Picking a shade inside the offer
 *   sheet rewrites the address bar to that shade (sheet-selection.js), which is
 *   what makes the state shareable. Larajax then posts to the CURRENT URL, so
 *   from the first sheet pick onward every AJAX render carries an :offer route
 *   param. The gallery partial resolved its own offer from that param and
 *   OVERWROTE the offer it had just been handed - so the picture stopped
 *   following the shade, froze on whatever was last in the URL, and the title
 *   started working at the same moment because it read the same param only to
 *   decide whether a shade was chosen at all. Price and SKU never looked at the
 *   URL and never broke, which is exactly why the symptom looked incoherent.
 *
 * The rule that removes the whole class of bug: ONE batched response renders
 * MANY offers, so request state can never decide which offer this particular
 * render is for. Only the caller knows, so the caller says, and what the caller
 * says wins.
 *
 * Precedence, highest first:
 *
 *   1. obRenderOffer   the caller pinned this render to one shade
 *                      (OfferSheet::onGetOfferBatch). Request state ignored.
 *   2. product_id / offer_id request input - the legacy per-shade AJAX path
 *      (partials/shared/controls/product-detail-control.js) and the quick-view
 *      modal, where the offer really does arrive in the request.
 *   3. neither: a plain page render. The page already resolved its own offer
 *      once in onInit (CustomProductPage::getSelectedOfferItem), so nothing is
 *      resolved again here - the :offer route param only answers whether a
 *      shade was explicitly asked for.
 *
 * A null obProduct or obOffer means "keep what you already have", so a page
 * render keeps its page variables and only an AJAX render replaces them.
 *
 * Twig, registered in Plugin::registerMarkupTags():
 *
 *   {% set arOfferRender = offer_render_context(obRenderOffer|default(null), this.param.offer) %}
 *   {% set obProduct = arOfferRender.obProduct ?: obProduct|default(null) %}
 *   {% set obOffer = arOfferRender.obOffer ?: obOffer|default(null) %}
 *   {% set bOfferSelected = arOfferRender.bOfferSelected %}
 *
 * obRenderOffer is deliberately a DIFFERENT name from obOffer. The page passes
 * obOffer to these partials on a normal render as well, and that is not a shade
 * the visitor chose - the h3 has to stay the product name on a canonical URL.
 * Only a name nothing else in scope uses can tell "pinned" from "inherited".
 */
class OfferRenderContext
{
    /**
     * @param OfferItem|null $obRenderOffer offer the caller pinned this render to
     * @param mixed          $sUrlOfferId   :offer route param, passed in rather
     *                                      than read out of the router so the
     *                                      rule has no hidden inputs
     *
     * @return array{obProduct: ProductItem|null, obOffer: OfferItem|null, bOfferSelected: bool}
     */
    public static function resolve($obRenderOffer = null, $sUrlOfferId = null): array
    {
        if ($obRenderOffer !== null) {
            return self::resolvePinned($obRenderOffer);
        }

        $iRequestProductId = (int) input('product_id');
        if ($iRequestProductId > 0) {
            return self::resolveFromRequest($iRequestProductId);
        }

        return [
            'obProduct'      => null,
            'obOffer'        => null,
            'bOfferSelected' => (int) $sUrlOfferId > 0,
        ];
    }

    /**
     * Case 1: the caller pinned the shade.
     *
     * Both halves of the contract are asserted. Anything other than an OfferItem
     * is a caller passing the wrong variable, and an EMPTY OfferItem is a caller
     * pinning a shade that does not exist - both would otherwise degrade into
     * silently rendering the page's own offer, which is the failure this class
     * exists to make impossible.
     *
     * obProduct is left null on purpose: every caller that pins an offer passes
     * obProduct beside it, and re-making it here would be a second source of
     * truth for the same pair.
     *
     * @param mixed $obRenderOffer
     * @return array{obProduct: ProductItem|null, obOffer: OfferItem, bOfferSelected: bool}
     */
    protected static function resolvePinned($obRenderOffer): array
    {
        if (!$obRenderOffer instanceof OfferItem) {
            throw new \InvalidArgumentException(sprintf(
                'OfferRenderContext: obRenderOffer must be an OfferItem, %s given',
                is_object($obRenderOffer) ? get_class($obRenderOffer) : gettype($obRenderOffer)
            ));
        }
        if ($obRenderOffer->isEmpty()) {
            throw new \InvalidArgumentException(
                'OfferRenderContext: obRenderOffer is an empty OfferItem - a render cannot be pinned to a shade that does not exist'
            );
        }

        return [
            'obProduct'      => null,
            'obOffer'        => $obRenderOffer,
            'bOfferSelected' => true,
        ];
    }

    /**
     * Case 2: product_id, optionally offer_id, in the request.
     *
     * An unknown product resolves to no product rather than an empty item, so
     * the caller keeps the variables it already had instead of rendering blank.
     *
     * @return array{obProduct: ProductItem|null, obOffer: OfferItem|null, bOfferSelected: bool}
     */
    protected static function resolveFromRequest(int $iRequestProductId): array
    {
        $obProductItem = ProductItem::make($iRequestProductId);
        if ($obProductItem->isEmpty()) {
            return ['obProduct' => null, 'obOffer' => null, 'bOfferSelected' => false];
        }

        $iRequestOfferId = (int) input('offer_id');
        if ($iRequestOfferId < 1) {
            return ['obProduct' => $obProductItem, 'obOffer' => null, 'bOfferSelected' => false];
        }

        // ElementCollection::find() answers with an EMPTY item, never null, so an
        // offer belonging to another product has to be turned back into "none"
        $obOfferItem = $obProductItem->offer->find($iRequestOfferId);
        $bOfferFound = !empty($obOfferItem) && $obOfferItem->isNotEmpty();

        return [
            'obProduct'      => $obProductItem,
            'obOffer'        => $bOfferFound ? $obOfferItem : null,
            'bOfferSelected' => $bOfferFound,
        ];
    }
}
