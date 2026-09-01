<?php namespace Logingrupa\StoreExtender\Classes\Event\Category;

use Event;
use Lovata\Shopaholic\Classes\Collection\CategoryCollection;

/**
 * Class PrimeCategoryTreeHandler
 *
 * Every layout renders the full active category tree in the header menu.
 * Category items reach that menu through ten separate hydration events
 * (five single CategoryItem::make calls for breadcrumb ancestors, five
 * collection batches for the menu levels), and each event costs five
 * queries on a cold item cache - model, files, translations, seo params,
 * property sets. Priming the whole active set in one batch first leaves
 * every later lookup on the item cache.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Category
 */
class PrimeCategoryTreeHandler
{
    /**
     * Prime the active category items once per page display.
     * cms.page.beforeDisplay precedes layout onInit, so the header menu
     * never triggers a hydration of its own.
     */
    public static function primeOnPageDisplay()
    {
        Event::listen('cms.page.beforeDisplay', function () {
            static $bPrimed = false;
            if ($bPrimed) {
                return;
            }
            $bPrimed = true;

            CategoryCollection::make()->active()->all();
        });
    }
}
