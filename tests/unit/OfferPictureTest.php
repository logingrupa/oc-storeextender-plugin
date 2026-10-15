<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\StoreExtender\Classes\Helper\OfferPicture;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use October\Rain\Database\Attach\File;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The picture the product page draws for a shade. A lone shade without a
 * picture borrows the product photo; with two or more shades it keeps its
 * lettered circle. No app and no database: the items carry their file in
 * model data the way the Toolbox cache hands it over.
 */
class OfferPictureTest extends TestCase
{
    protected function makeFile(int $iFileId): File
    {
        $obFile = new class extends File {
        };
        $obFile->id = $iFileId;

        return $obFile;
    }

    /**
     * @param class-string $sItemClass
     */
    protected function makeItem(string $sItemClass, int $iItemId, ?File $obPreviewImage)
    {
        $obReflection = new ReflectionClass($sItemClass);
        $obItem = $obReflection->newInstanceWithoutConstructor();

        $arModelData = ['id' => $iItemId];
        if ($obPreviewImage !== null) {
            $arModelData['attachOne|preview_image'] = ['id' => $obPreviewImage->id];
            $arModelData['preview_image'] = $obPreviewImage;
        }

        $obProperty = $obReflection->getProperty('arModelData');
        $obProperty->setAccessible(true);
        $obProperty->setValue($obItem, $arModelData);

        return $obItem;
    }

    public function testALoneShadeWithItsOwnPictureKeepsIt()
    {
        $obOfferImage = $this->makeFile(501);
        $obOfferItem = $this->makeItem(OfferItem::class, 1040, $obOfferImage);
        $obProductItem = $this->makeItem(ProductItem::class, 1, $this->makeFile(11130));

        $this->assertSame($obOfferImage, OfferPicture::resolve($obOfferItem, $obProductItem, 1));
    }

    public function testALoneShadeWithoutAPictureShowsTheProductPhoto()
    {
        $obProductImage = $this->makeFile(11130);
        $obOfferItem = $this->makeItem(OfferItem::class, 1040, null);
        $obProductItem = $this->makeItem(ProductItem::class, 1, $obProductImage);

        $this->assertSame($obProductImage, OfferPicture::resolve($obOfferItem, $obProductItem, 1));
    }

    public function testAShadeWithoutAPictureAmongSeveralKeepsItsLetteredCircle()
    {
        $obOfferItem = $this->makeItem(OfferItem::class, 4288, null);
        $obProductItem = $this->makeItem(ProductItem::class, 383, $this->makeFile(11131));

        $this->assertNull(OfferPicture::resolve($obOfferItem, $obProductItem, 2));
    }

    public function testALoneShadeOfAProductWithoutAPhotoHasNoPicture()
    {
        $obOfferItem = $this->makeItem(OfferItem::class, 1040, null);
        $obProductItem = $this->makeItem(ProductItem::class, 1, null);

        $this->assertNull(OfferPicture::resolve($obOfferItem, $obProductItem, 1));
    }
}
