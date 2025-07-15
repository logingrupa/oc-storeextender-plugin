<?php

use Lovata\Toolbox\Classes\Component\SortingElementList;
use Lovata\Shopaholic\Classes\Collection\ProductCollection;
use Lovata\Shopaholic\Classes\Collection\OfferCollection;
use Lovata\Shopaholic\Classes\Store\ProductListStore;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Logingrupa\ExtendShopaholic\Components\OfferList;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Models\Offer;

Route::group(['middleware' => 'web'], function () {
    Route::group(['prefix' => 'api'], function () {
        Route::any('offers', function () {
            $color = \ColorPalette::getColor('http://naiscosmetics.eu.ngrok.io/storage/app/uploads/public/5db/058/bd8/thumb__0_0_0_0_auto.jpeg');
            // dd($color->rgbaString);
            $obProductCollection = ProductCollection::make([246, 247])->active();
            $obList = Offer::whereProductId([366, 246])->get();
            // dd($obList);
            // 'rgb' => isset($obOffer->preview_image->path) ? \ColorPalette::getColor($obOffer->preview_image->getThumb('390', '1000', ['mode' => 'crop', 'offset' => [-0,-0]]))->rgbaString : false,
            // 'colors' => isset($obOffer->preview_image->path) ? array_combine(\ColorPalette::getPalette($obOffer->preview_image->getThumb('390', '1000', ['mode' => 'crop', 'offset' => [-0,-0]]), $colorCount = 6, $quality = 10, $area = null),['14%', '34%', '43%', '52%', '85%', '100%' ] ) : false,
            foreach ($obList as $obOffer) {
                $data[] = [
                    'name' => $obOffer->name,
                    'id' => $obOffer->id,
                    'path' => isset($obOffer->preview_image->path) ? $obOffer->preview_image->getThumb('390', '1000', ['mode' => 'crop', 'offset' => [-0, -0]]) : false,
                    'rgb' => isset($obOffer->preview_image->path) ? \ColorPalette::getColor($obOffer->preview_image->getThumb('390', '1000', ['mode' => 'crop', 'offset' => [-0, -0]]))->rgbaString : false,

                ];
            };
            // dd($data);

            return View::make('logingrupa.storeextender::offer_colors')->with('data', $data);
        });
    });


});