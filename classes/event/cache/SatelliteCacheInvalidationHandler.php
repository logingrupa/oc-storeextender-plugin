<?php namespace Logingrupa\StoreExtender\Classes\Event\Cache;

use System\Models\File;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Price;
use Lovata\Shopaholic\Models\Product;
use Lovata\Shopaholic\Models\Category;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\PropertiesShopaholic\Models\PropertyValueLink;
use Lovata\MightySeo\Models\SeoParam;

/**
 * Class SatelliteCacheInvalidationHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Cache
 *
 * Toolbox ModelHandler::afterSave skips the item cache clear when the saved
 * model reports no changes (unchanged-save guard, see the toolbox composer
 * patch). Shopaholic keeps prices, property values, images and SEO params in
 * satellite rows whose mutations never dirty the parent model, so the parent
 * save alone can no longer invalidate the cached item. Each satellite model
 * clears the owning item cache from its OWN events, where the change is
 * visible.
 */
class SatelliteCacheInvalidationHandler
{
    /** Item classes cleared per owner model, keyed by morph class name */
    protected const OWNER_ITEM_MAP = [
        Product::class  => ProductItem::class,
        Offer::class    => OfferItem::class,
        Category::class => CategoryItem::class,
    ];

    /**
     * Add listeners
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        Price::extend(function ($obPrice) {
            $obPrice->bindEvent('model.afterSave', function () use ($obPrice) {
                $this->clearOfferCacheOnPriceChange($obPrice);
            });
            $obPrice->bindEvent('model.afterDelete', function () use ($obPrice) {
                $this->clearOwnerItemCache($obPrice->item_type, (int) $obPrice->item_id);
            });
        });

        PropertyValueLink::extend(function ($obValueLink) {
            $obValueLink->bindEvent('model.afterSave', function () use ($obValueLink) {
                if (!$obValueLink->wasChanged() && !$obValueLink->wasRecentlyCreated) {
                    return;
                }
                $this->clearOwnerItemCache($obValueLink->element_type, (int) $obValueLink->element_id);
            });
            $obValueLink->bindEvent('model.afterDelete', function () use ($obValueLink) {
                $this->clearOwnerItemCache($obValueLink->element_type, (int) $obValueLink->element_id);
            });
        });

        File::extend(function ($obFile) {
            $obFile->bindEvent('model.afterSave', function () use ($obFile) {
                if (!$obFile->wasChanged() && !$obFile->wasRecentlyCreated) {
                    return;
                }
                $this->clearOwnerItemCache((string) $obFile->attachment_type, (int) $obFile->attachment_id);
            });
            $obFile->bindEvent('model.afterDelete', function () use ($obFile) {
                $this->clearOwnerItemCache((string) $obFile->attachment_type, (int) $obFile->attachment_id);
            });
        });

        SeoParam::extend(function ($obSeoParam) {
            $obSeoParam->bindEvent('model.afterSave', function () use ($obSeoParam) {
                if (!$obSeoParam->wasChanged() && !$obSeoParam->wasRecentlyCreated) {
                    return;
                }
                $this->clearOwnerItemCache((string) $obSeoParam->external_type, (int) $obSeoParam->external_id);
            });
        });
    }

    /**
     * Clear the owner item cache when a price row NUMERICALLY changed.
     * Offer::afterSave re-saves its price rows on every offer save, and the
     * old_price mutator rewrites stored '0.00' as int 0 - Laravel's string
     * dirty check flags that as changed forever. Compare as floats so only
     * real price movement clears the cache.
     */
    protected function clearOfferCacheOnPriceChange(Price $obPrice): void
    {
        if ($obPrice->wasRecentlyCreated) {
            $this->clearOwnerItemCache((string) $obPrice->item_type, (int) $obPrice->item_id);

            return;
        }

        if (!$obPrice->wasChanged()) {
            return;
        }

        $arChangeList = $obPrice->getChanges();
        $bRealChange = false;
        foreach (['price', 'old_price', 'price_type_id', 'item_id', 'item_type'] as $sField) {
            if (!array_key_exists($sField, $arChangeList)) {
                continue;
            }
            if (in_array($sField, ['price', 'old_price'], true)) {
                if (abs((float) $arChangeList[$sField] - (float) $obPrice->getRawOriginal($sField)) > 0.00001) {
                    $bRealChange = true;
                    break;
                }
                continue;
            }
            $bRealChange = true;
            break;
        }

        if (!$bRealChange) {
            return;
        }

        $this->clearOwnerItemCache((string) $obPrice->item_type, (int) $obPrice->item_id);
    }

    /**
     * Clear the item cache for a satellite row's owner model, when the owner
     * type has a Toolbox item.
     */
    protected function clearOwnerItemCache(?string $sOwnerClass, int $iOwnerID): void
    {
        if (empty($sOwnerClass) || $iOwnerID < 1) {
            return;
        }

        $sItemClass = self::OWNER_ITEM_MAP[$sOwnerClass] ?? null;
        if ($sItemClass === null) {
            return;
        }

        $sItemClass::clearCache($iOwnerID);
    }
}
