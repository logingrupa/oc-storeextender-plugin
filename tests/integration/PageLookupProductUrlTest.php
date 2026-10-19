<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/ShopItemDoubles.php';

use Cms\Classes\Controller;
use Cms\Classes\Theme;
use Cms\Models\PageLookupItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Item\ItemStorage;

/**
 * A product link resolved by the pagefinder (|link, menu items, banners) is
 * the bare /p/<slug> URL even when it renders on a product page that has an
 * offer in its route: Page::url() merges the current router params, so the
 * resolvers have to drop :offer themselves.
 *
 * The links go through PageLookupItem::resolveFromSchema(), the seam |link
 * uses, against the active theme's real product page (/p/:slug/:offer?).
 */
class PageLookupProductUrlTest extends StoreExtenderPluginTestCase
{
    use ShopItemDoubles;

    /** @var bool core module schema only, the product item is a double */
    protected $autoMigrate = false;

    const PAGE_SLUG = 'quick-polish-leduv-gellaka-12ml';
    const PAGE_OFFER_ID = '4411';
    const LINKED_PRODUCT_ID = 17;
    const LINKED_SLUG = 'viles-un-bafiki';

    public function setUp(): void
    {
        parent::setUp();

        $obController = new Controller(Theme::getActiveTheme());
        $obController->getRouter()->setParameters(['slug' => self::PAGE_SLUG, 'offer' => self::PAGE_OFFER_ID]);
    }

    /**
     * The controller and the item storage are process-wide statics that
     * outlive this test's application.
     * @return void
     */
    public function tearDown(): void
    {
        (new ReflectionProperty(Controller::class, 'instance'))->setValue(null, null);
        ItemStorage::clear(ProductItem::class, self::LINKED_PRODUCT_ID);

        parent::tearDown();
    }

    public function testSlugTypeLinksTheBareProductUrlOnAProductPageWithAnOffer()
    {
        $obItem = PageLookupItem::resolveFromSchema('october://shop-product-slug@link/' . self::LINKED_SLUG);

        $this->assertStringEndsWith('/p/' . self::LINKED_SLUG, $obItem->url);
    }

    public function testIdTypeLinksTheBareProductUrlOnAProductPageWithAnOffer()
    {
        $obProduct = $this->makeProduct([
            'id' => self::LINKED_PRODUCT_ID,
            'name' => 'Viles un bafiki',
            'slug' => self::LINKED_SLUG,
        ]);
        ItemStorage::set(ProductItem::class, self::LINKED_PRODUCT_ID, $obProduct);

        $obItem = PageLookupItem::resolveFromSchema('october://shop-product@link/' . self::LINKED_PRODUCT_ID);

        $this->assertStringEndsWith('/p/' . self::LINKED_SLUG, $obItem->url);
    }
}
