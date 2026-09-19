<?php namespace Logingrupa\StoreExtender\Classes\Shipping;

use Logingrupa\StoreExtender\Classes\Helper\ColorFamilyHelper;
use Logingrupa\StoreExtender\Models\Settings;
use Lovata\Shopaholic\Classes\Item\CategoryItem;

/**
 * The free-delivery nudge's "find an item" button: the whole-catalog offer
 * grid filtered from the missing amount up, cheapest first. The button shows
 * only while the missing amount sits inside the window from this site's
 * settings, so the theme needs the window and the catalog URL, not a verdict:
 * the client repaints the button as the subtotal changes.
 */
class NudgeCatalogButton
{
    /** Query parameter the catalog offer grid reads (ajax-catalog-product-list.htm) */
    const PRICE_FROM_PARAMETER = 'price_from';

    /**
     * @return array|null ['min' => float, 'max' => float, 'url' => string], null when the site offers no button
     */
    public static function make(): ?array
    {
        $arWindow = static::window(
            Settings::get('nudge_catalog_min_missing'),
            Settings::get('nudge_catalog_max_missing')
        );
        if ($arWindow === null) {
            return null;
        }

        $sCatalogUrl = CategoryItem::make(ColorFamilyHelper::CATALOG_ROOT_CATEGORY_ID)
            ->getPageUrl(ColorFamilyHelper::CATALOG_PAGE_CODE);
        if (empty($sCatalogUrl)) {
            return null;
        }

        return $arWindow + ['url' => $sCatalogUrl];
    }

    /**
     * An empty or zero maximum switches the button off; so does a window
     * whose minimum lies above its maximum.
     *
     * @param mixed $mMinMissing raw settings value
     * @param mixed $mMaxMissing raw settings value
     * @return array|null ['min' => float, 'max' => float]
     */
    public static function window($mMinMissing, $mMaxMissing): ?array
    {
        $fMinMissing = is_numeric($mMinMissing) ? (float) $mMinMissing : 0.0;
        $fMaxMissing = is_numeric($mMaxMissing) ? (float) $mMaxMissing : 0.0;
        if ($fMaxMissing <= 0.0 || $fMinMissing < 0.0 || $fMinMissing > $fMaxMissing) {
            return null;
        }

        return ['min' => $fMinMissing, 'max' => $fMaxMissing];
    }

    /**
     * @param float $fRemaining amount still missing for the free shipping type
     * @param array $arWindow   window() output
     * @return bool
     */
    public static function isShown(float $fRemaining, array $arWindow): bool
    {
        return $fRemaining >= $arWindow['min'] && $fRemaining <= $arWindow['max'];
    }
}
