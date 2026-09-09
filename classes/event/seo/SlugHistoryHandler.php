<?php namespace Logingrupa\StoreExtender\Classes\Event\Seo;

use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Models\Category;
use Logingrupa\StoreExtender\Models\SlugHistory;

/**
 * Records every product and category slug change, whatever wrote it: the
 * backend form, the 1C XML import, a console command.
 */
class SlugHistoryHandler
{
    public function subscribe($obEvent)
    {
        Product::extend(function ($obProduct) {
            $obProduct->bindEvent('model.beforeUpdate', function () use ($obProduct) {
                $this->recordRename(SlugHistory::TYPE_PRODUCT, $obProduct);
            });
        });

        Category::extend(function ($obCategory) {
            $obCategory->bindEvent('model.beforeUpdate', function () use ($obCategory) {
                $this->recordRename(SlugHistory::TYPE_CATEGORY, $obCategory);
            });
        });
    }

    protected function recordRename(string $sType, $obModel): void
    {
        if (!$obModel->isDirty('slug')) {
            return;
        }

        SlugHistory::record($sType, (string) $obModel->getOriginal('slug'), (string) $obModel->slug);
    }
}
