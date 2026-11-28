<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Item\ItemStorage;
use ReflectionProperty;

/**
 * Explicit two-argument ItemStorage resets for Shopaholic offers and products.
 */
final class ItemCacheResetter
{
    /**
     * Clears the given offer IDs and any cached parent product IDs from ItemStorage.
     *
     * @param  array<int|string>  $arOfferIdList
     */
    public static function clearOffers(array $arOfferIdList): void
    {
        $arClearedProducts = [];
        foreach ($arOfferIdList as $mOfferId) {
            $iOfferId = is_numeric($mOfferId) ? (int) $mOfferId : 0;
            if ($iOfferId < 1) {
                continue;
            }

            $obCachedOffer = ItemStorage::get(OfferItem::class, $iOfferId);
            $iProductId = $obCachedOffer !== null ? (int) $obCachedOffer->getAttribute('product_id') : 0;
            ItemStorage::clear(OfferItem::class, $iOfferId);

            if ($iProductId > 0 && !isset($arClearedProducts[$iProductId])) {
                ItemStorage::clear(ProductItem::class, $iProductId);
                $arClearedProducts[$iProductId] = true;
            }
        }
    }

    /**
     * Clears every currently cached instance of a Toolbox item class from ItemStorage.
     */
    public static function clearAllOfClass(string $sItemClass): void
    {
        $obProperty = new ReflectionProperty(ItemStorage::class, 'arItemStore');
        $arStore = $obProperty->getValue();
        if (!is_array($arStore) || empty($arStore)) {
            return;
        }

        $sPrefix = $sItemClass . '|';
        $iPrefixLength = strlen($sPrefix);
        foreach (array_keys($arStore) as $sKey) {
            if (!is_string($sKey) || !str_starts_with($sKey, $sPrefix)) {
                continue;
            }

            $sId = substr($sKey, $iPrefixLength);
            if ($sId !== '' && ctype_digit($sId)) {
                ItemStorage::clear($sItemClass, (int) $sId);
            }
        }
    }
}
