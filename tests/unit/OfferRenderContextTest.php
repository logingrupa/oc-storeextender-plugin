<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\StoreExtender\Classes\Helper\OfferRenderContext;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The rule that decides WHICH offer a product-card fragment renders.
 *
 * This exists because the storefront shipped a bug that no test could have
 * caught, since the decision was spread across six Twig templates. The product
 * page URL is /p/:slug/:offer?, the offer sheet writes a picked shade into the
 * address bar to make it shareable, and Larajax posts to the current URL - so
 * from the first sheet pick onward every AJAX render arrived carrying an :offer
 * route param. The gallery partial re-resolved its own offer from that param and
 * overwrote the offer it had just been handed: the picture stopped following the
 * shade and froze on whatever the URL last named, while the title started
 * working at the same moment because it read the same param only to decide
 * whether a shade had been chosen at all.
 *
 * The invariant, asserted here: a caller that pins a render to one shade cannot
 * be overruled by request state. One batched response renders twelve shades, so
 * request state is not even capable of answering the question.
 *
 * No app: the pinned path returns before it reads any request, which is itself
 * part of the contract. The request-input path is covered by
 * tests/integration/OfferRenderContextRequestTest.php and, end to end, by the
 * theme's product.spec.js.
 */
class OfferRenderContextTest extends TestCase
{
    /**
     * An OfferItem with model data and no database.
     *
     * ElementItem::isEmpty() is empty($this->arModelData) and nothing else, so a
     * shade that exists is a shade with data. Deliberately not OfferItem::make()
     * - that reads the cache, the item storage and eventually the table, none of
     * which this decision touches.
     */
    protected function makeOfferItem(int $iOfferId): OfferItem
    {
        $obReflection = new ReflectionClass(OfferItem::class);
        /** @var OfferItem $obOfferItem */
        $obOfferItem = $obReflection->newInstanceWithoutConstructor();

        $obProperty = $obReflection->getProperty('arModelData');
        $obProperty->setAccessible(true);
        $obProperty->setValue($obOfferItem, ['id' => $iOfferId, 'product_id' => 366]);

        return $obOfferItem;
    }

    protected function makeEmptyOfferItem(): OfferItem
    {
        /** @var OfferItem $obOfferItem */
        $obOfferItem = (new ReflectionClass(OfferItem::class))->newInstanceWithoutConstructor();

        return $obOfferItem;
    }

    public function testAPinnedOfferIsTheOfferAndCountsAsChosen()
    {
        $obOfferItem = $this->makeOfferItem(4150);

        $arContext = OfferRenderContext::resolve($obOfferItem, null);

        $this->assertSame($obOfferItem, $arContext['obOffer']);
        $this->assertTrue($arContext['bOfferSelected']);
    }

    /**
     * The bug, at the level it was decided.
     *
     * A batched render of shade 4150 while the address bar still names shade 999
     * has to draw 4150. Before the rule moved here, the URL won and the gallery
     * froze - and it kept freezing, because the frozen markup was then cached
     * client-side under 4150's key.
     */
    public function testAPinnedOfferOutranksAnOfferInTheUrl()
    {
        $obOfferItem = $this->makeOfferItem(4150);

        $arContext = OfferRenderContext::resolve($obOfferItem, '999');

        $this->assertSame($obOfferItem, $arContext['obOffer']);
        $this->assertSame(4150, $arContext['obOffer']->id);
    }

    /**
     * The caller that pins an offer passes the product beside it. Re-deriving it
     * here would be a second source of truth for one pair, so the answer is
     * "keep what you have" and the template's ?: does the rest.
     */
    public function testAPinnedOfferLeavesTheProductToTheCaller()
    {
        $arContext = OfferRenderContext::resolve($this->makeOfferItem(4150), null);

        $this->assertNull($arContext['obProduct']);
    }

    public function testPinningSomethingThatIsNotAnOfferItemFails()
    {
        $this->expectException(\InvalidArgumentException::class);

        OfferRenderContext::resolve('4150', null);
    }

    /**
     * Pinning a shade that does not exist must not degrade into rendering the
     * page's own shade - that silent fallback IS the bug this class removes.
     */
    public function testPinningAnEmptyOfferItemFails()
    {
        $this->expectException(\InvalidArgumentException::class);

        OfferRenderContext::resolve($this->makeEmptyOfferItem(), null);
    }
}
