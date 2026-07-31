<?php namespace Logingrupa\StoreExtender\Classes\Event\Product;

use Lovata\Shopaholic\Models\Product;

/**
 * Class ProductModelHandler
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Product
 */
class ProductModelHandler
{
    /**
     * Extend Product model
     *
     * Adds manufacturer and ingredients fields to fillable and cached field lists.
     */
    public function subscribe(): void
    {
        Product::extend(function ($obProduct): void {
            /** @var Product $obProduct */
            $obProduct->fillable[] = 'manufacturer';
            $obProduct->fillable[] = 'warning';
            $obProduct->fillable[] = 'ingredients';

            $obProduct->translatable[] = 'warning';

            $obProduct->addCachedField(['manufacturer', 'ingredients', 'warning']);
        });
    }
}