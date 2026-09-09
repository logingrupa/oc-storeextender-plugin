<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Models\Category;
use Logingrupa\StoreExtender\Models\SlugHistory;

/**
 * Where a dead catalogue URL should go.
 *
 * A category URL dies when the category was switched off (the 1C import
 * retires manual categories), moved under another parent, or renamed. A
 * product URL dies on a rename. All of those still have a live page the
 * visitor and the crawler are looking for; a product that is gone has none.
 */
class LegacyUrlResolver
{
    /**
     * The live slug path for a category slug list that did not resolve, or
     * null when nothing in it is known any more.
     * @param string[] $arSlugList the URL segments, root category first
     * @return string[]|null
     */
    public function resolveCategoryPath(array $arSlugList): ?array
    {
        foreach (array_reverse($arSlugList) as $sSlug) {
            $obCategory = $this->findCategory($sSlug);
            if (empty($obCategory)) {
                continue;
            }

            $obCategory = $this->firstActive($obCategory);
            if (empty($obCategory)) {
                return null;
            }

            $arPath = $this->pathOf($obCategory);

            return $arPath === $arSlugList ? null : $arPath;
        }

        return null;
    }

    /**
     * The live slug for a product slug that did not resolve, or null when the
     * product is gone or was never known.
     */
    public function resolveProductSlug(string $sSlug): ?string
    {
        $sNewSlug = SlugHistory::findTarget(SlugHistory::TYPE_PRODUCT, $sSlug);
        if ($sNewSlug === null) {
            return null;
        }

        $bLive = Product::active()->where('slug', $sNewSlug)->exists();

        return $bLive ? $sNewSlug : null;
    }

    protected function findCategory(string $sSlug): ?Category
    {
        $obCategory = Category::where('slug', $sSlug)->orderBy('active', 'desc')->first();
        if (!empty($obCategory)) {
            return $obCategory;
        }

        $sNewSlug = SlugHistory::findTarget(SlugHistory::TYPE_CATEGORY, $sSlug);

        return $sNewSlug === null ? null : Category::where('slug', $sNewSlug)->first();
    }

    protected function firstActive(Category $obCategory): ?Category
    {
        while (!empty($obCategory) && !$obCategory->active) {
            $obCategory = $obCategory->parent_id ? Category::find($obCategory->parent_id) : null;
        }

        return $obCategory;
    }

    /**
     * @return string[] slugs from the root down to $obCategory
     */
    protected function pathOf(Category $obCategory): array
    {
        $arPath = [];
        $iGuard = 0;
        while (!empty($obCategory) && $iGuard++ < 10) {
            array_unshift($arPath, $obCategory->slug);
            $obCategory = $obCategory->parent_id ? Category::find($obCategory->parent_id) : null;
        }

        return $arPath;
    }
}
