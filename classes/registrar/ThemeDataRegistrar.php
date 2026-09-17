<?php namespace Logingrupa\StoreExtender\Classes\Registrar;

use Logingrupa\StoreExtender\Classes\Helper\UserPropertyHelper;

/**
 * ThemeDataRegistrar supplies the theme customization form with the dropdown
 * option sources it asks the model for, and with the preview-thumbnail handler
 * the product dropdown calls over AJAX.
 *
 * October resolves a dropdown's options by calling get<Field>Options() on the
 * model, so a form field whose method is missing renders an empty list with no
 * error. Every option method the theme's fields name has to be added here.
 */
class ThemeDataRegistrar
{
    /**
     * extendThemeDataDropdownMethods hooks into form field building to add dynamic
     * dropdown option methods to the theme customization model. This covers both
     * ThemeData (primary form) and MLThemeData (RainLab Translate proxy), regardless
     * of instantiation order.
     */
    public static function extendThemeDataDropdownMethods()
    {
        $fnAddDropdownMethods = function ($obModel) {
            $obModel->addDynamicMethod('getPromoBlockLeftOptions', function () {
                return \Lovata\Shopaholic\Models\PromoBlock::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getPromoBlockMiddleOptions', function () {
                return \Lovata\Shopaholic\Models\PromoBlock::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getPromoBlockRightOptions', function () {
                return \Lovata\Shopaholic\Models\PromoBlock::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getProductIdOptions', function () {
                return \Lovata\Shopaholic\Models\Product::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getCategoryIdOptions', function () {
                return \Lovata\Shopaholic\Models\Category::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getCategoryLeftOptions', function () {
                return \Lovata\Shopaholic\Models\Category::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getCategoryMiddleOptions', function () {
                return \Lovata\Shopaholic\Models\Category::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getCategoryRightOptions', function () {
                return \Lovata\Shopaholic\Models\Category::lists('name', 'id');
            });
            $obModel->addDynamicMethod('getTermsConditionsOptions', function () {
                $arPages = \Cms\Classes\Page::sortBy('baseFileName')->lists('title', 'baseFileName');
                if (class_exists('\\Rainlab\\Pages\\Classes\\Page')) {
                    $arPages = $arPages + \Rainlab\Pages\Classes\Page::sortBy('title')->lists('title', 'baseFileName');
                }
                return $arPages;
            });
            $obModel->addDynamicMethod('getUserFieldsOptions', function () {
                return UserPropertyHelper::instance()->getCodeNameList();
            });
            $obModel->addDynamicMethod('getShippingCodeOptions', function () {
                return \Lovata\OrdersShopaholic\Models\ShippingType::lists('name', 'code');
            });
            $obModel->addDynamicMethod('getDefaultCurrencyCodeOptions', function () {
                return \Lovata\Shopaholic\Models\Currency::where('active', true)
                    ->lists('name', 'code');
            });
            $obModel->addDynamicMethod('getTranslatedOptions', function () {
                return \System\Models\SiteDefinition::where('is_enabled', true)
                    ->get()
                    ->mapWithKeys(function ($obSite) {
                        $sCode = strtolower(substr($obSite->code, 0, 2));
                        return [$sCode => $obSite->name];
                    })
                    ->toArray();
            });
        };

        // Class-level extend - methods are added at construction time, before form renders
        \Cms\Models\ThemeData::extend($fnAddDropdownMethods);

        if (class_exists('\RainLab\Translate\Models\MLThemeData')) {
            \RainLab\Translate\Models\MLThemeData::extend($fnAddDropdownMethods);
        }
    }

    /**
     * extendThemeOptionsController adds the onGetProductPreviewImage AJAX handler
     * to the ThemeOptions controller for product dropdown preview thumbnails.
     */
    public static function extendThemeOptionsController()
    {
        \Cms\Controllers\ThemeOptions::extend(function ($obController) {
            $obController->addDynamicMethod('onGetProductPreviewImage', function () {
                $iProductId = (int) post('product_id');

                $obProduct = \Lovata\Shopaholic\Models\Product::with('preview_image')
                    ->find($iProductId);

                $sPreviewImageUrl = '';
                if ($obProduct && $obProduct->preview_image) {
                    $sPreviewImageUrl = $obProduct->preview_image->getThumb(300, 300, ['mode' => 'crop']);
                }

                return ['preview_image_url' => $sPreviewImageUrl];
            });
        });
    }
}
