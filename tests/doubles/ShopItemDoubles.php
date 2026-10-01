<?php

use Lovata\ReviewsShopaholic\Classes\Item\ReviewItem;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Item\ElementItem;
use System\Models\File;

/**
 * Shop items built from model data through reflection, for tests of helpers
 * that read Items and need no row: the Shopaholic schema does not build on
 * SQLite. Used by ProductStructuredDataTest and ProductOfferPriceTest.
 */
trait ShopItemDoubles
{
    /**
     * @param array $arModelData
     * @return ProductItem
     */
    protected function makeProduct(array $arModelData): ProductItem
    {
        /** @var ProductItem $obItem */
        $obItem = $this->makeItem(ProductItem::class, $arModelData);

        return $obItem;
    }

    /**
     * price_value and currency_code read the currency table on a real
     * OfferItem; the double answers with what the fixture says.
     * @param array  $arModelData
     * @param float  $fPrice
     * @param string $sCurrencyCode
     * @return OfferItem
     */
    protected function makeOffer(array $arModelData, float $fPrice, string $sCurrencyCode): OfferItem
    {
        $obPrototype = new class(0, null) extends OfferItem {
            /** @var float */
            public $fFakePrice = 0.0;
            /** @var string */
            public $sFakeCurrencyCode = '';

            protected function getPriceValueAttribute()
            {
                return $this->fFakePrice;
            }

            protected function getCurrencyCodeAttribute()
            {
                return $this->sFakeCurrencyCode;
            }
        };

        /** @var OfferItem $obItem */
        $obItem = $this->makeItem(get_class($obPrototype), $arModelData);
        $obItem->fFakePrice = $fPrice;
        $obItem->sFakeCurrencyCode = $sCurrencyCode;

        return $obItem;
    }

    /**
     * @param array $arModelData
     * @return ReviewItem
     */
    protected function makeReview(array $arModelData): ReviewItem
    {
        /** @var ReviewItem $obItem */
        $obItem = $this->makeItem(ReviewItem::class, $arModelData);

        return $obItem;
    }

    /**
     * An item with model data and no database, no cache and no constructor.
     * @param string $sClass
     * @param array  $arModelData
     * @return ElementItem
     */
    protected function makeItem(string $sClass, array $arModelData): ElementItem
    {
        $obReflection = new ReflectionClass($sClass);
        /** @var ElementItem $obItem */
        $obItem = $obReflection->newInstanceWithoutConstructor();

        $obProperty = $obReflection->getProperty('arModelData');
        $obProperty->setAccessible(true);
        $obProperty->setValue($obItem, $arModelData);

        return $obItem;
    }

    /**
     * A public upload whose path resolves through the booted app's url().
     * @param string $sDiskName
     * @return File
     */
    protected function makeFile(string $sDiskName): File
    {
        $obFile = new File();
        $obFile->disk_name = $sDiskName;
        $obFile->file_name = $sDiskName;
        $obFile->is_public = true;

        return $obFile;
    }
}
