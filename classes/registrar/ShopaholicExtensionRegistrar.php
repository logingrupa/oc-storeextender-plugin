<?php namespace Logingrupa\StoreExtender\Classes\Registrar;

use Event;
use File;
use Yaml;
use System\Classes\PluginManager;
use Lovata\Shopaholic\Models\Offer as ShopaholicOfferModel;
use Lovata\Shopaholic\Models\Product as ShopaholicProductModel;
use Lovata\Shopaholic\Models\Category as ShopaholicCategoryModel;
use Lovata\OrdersShopaholic\Models\Order as ShopaholicOrderModel;
use Lovata\Shopaholic\Models\Currency as ShopaholicCurrencyModel;
use Lovata\Shopaholic\Controllers\Currencies as ShopaholicCurrenciesController;
use Lovata\Shopaholic\Controllers\Products as ShopaholicProductsController;
use Lovata\Shopaholic\Controllers\Offers as ShopaholicOffersController;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Classes\Import\ImportOfferModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportProductModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportCategoryModelFromXML;

/**
 * ShopaholicExtensionRegistrar holds every extension this plugin hangs on a
 * Shopaholic model, backend controller or XML importer.
 *
 * Plugin::boot() calls these in a fixed order. October applies extensions in
 * registration order, so which handler sees a model first is decided there and
 * not here; nothing in this class may be reordered on its own.
 */
class ShopaholicExtensionRegistrar
{
    public static function extendShopaholicProductsController()
    {
        ShopaholicProductsController::extendFormFields(function ($widget) {
            // Prevent extending of related form instead of the intended ShopaholicProductModel form
            if (!$widget->model instanceof ShopaholicProductModel) {
                return;
            }
            $configTabFields = Yaml::parse(File::get(self::getPluginPath() . '/config/shopaholic/addAditionalSettingsTab.yaml'));
            $widget->addTabFields($configTabFields);
        });
    }

    public static function extendShopaholicOffersController()
    {
        ShopaholicOffersController::extendFormFields(function ($widget) {
            // Prevent extending of related form instead of the intended ShopaholicProductModel form
            if (!$widget->model instanceof ShopaholicOfferModel) {
                return;
            }
            $widget->removeField('preview_image');
            $widget->removeField('images');
            $configTabFields = Yaml::parse(File::get(self::getPluginPath() . '/config/shopaholic/addToImagesTabVideoPreview.yaml'));
            $widget->addTabFields($configTabFields);
        });
    }

    /**
     * CategoryItem primes its active children map once per request. A process
     * that saves categories and then rebuilds items (backend save, XML import)
     * would otherwise read the map it primed before the save.
     */
    public static function extendCategoryChildrenMapReset()
    {
        ShopaholicCategoryModel::extend(function ($obModel) {
            $obModel->bindEvent('model.afterSave', function () {
                CategoryItem::clearActiveChildrenMap();
            });

            $obModel->bindEvent('model.afterDelete', function () {
                CategoryItem::clearActiveChildrenMap();
            });
        });
    }

    /**
     * Eager load RainLab Translate 'translations' morphMany during Toolbox item
     * priming. Without this every Product/Offer/Category model AND every attached
     * MLFile image lazy-loads rainlab_translate_attributes one query per instance
     * (350+ queries on a cold home page). 'translations' is defined by the
     * TranslatableModel behavior constructor, so with() resolves it on models and
     * on MLFile attachments alike. Warm path unaffected - cache hits never reach
     * the query. Note: nested '<image>.translations' relies on preview_image and
     * images NOT being listed in $translatable on the parent model (RainLab swaps
     * the attachment class to MLFile only in that case).
     */
    public static function extendItemEagerLoading()
    {
        // seo_container: MightySeo caches seo_param_id on every item and reads
        // it via the morphOne relation - without eager loading that is one
        // lovata_mighty_seo_params query per model during cold item builds
        // (58 on one promo page). Eager loaded, collection builds batch it.
        ProductItem::$arQueryWith = array_merge(ProductItem::$arQueryWith, [
            'translations',
            'preview_image.translations',
            'images.translations',
            'offer.translations',
            'offer.preview_image.translations',
            'offer.images.translations',
            'seo_container',
        ]);

        OfferItem::$arQueryWith = array_merge(OfferItem::$arQueryWith, [
            'translations',
            'preview_image.translations',
            'images.translations',
        ]);

        $arCategoryWith = [
            'translations',
            'preview_image.translations',
            'icon.translations',
            'images.translations',
            'seo_container',
        ];

        // property_set is a PropertiesShopaholic relation, absent when that
        // plugin is off - an unknown path here throws RelationNotFoundException
        if (PluginManager::instance()->hasPlugin('Lovata.PropertiesShopaholic')) {
            $arCategoryWith[] = 'property_set';
        }

        CategoryItem::$arQueryWith = array_merge(CategoryItem::$arQueryWith, $arCategoryWith);
    }

    public static function extendShopaholicProductModel()
    {
        ShopaholicProductModel::extend(function ($obModel) {
            $translatable = ['how_to', 'video_link', 'warning', 'ingredients'];
            foreach ($translatable as $field) {
                $obModel->translatable[] = $field;
            }

            $obModel->addCachedField(['how_to', 'video_link', 'hide_dropdown']);

            $fillable = ['how_to', 'video_link', 'hide_dropdown'];
            foreach ($fillable as $field) {
                $obModel->fillable[] = $field;
            }
        });
    }

    public static function extendShopaholicOfferModel()
    {
        ShopaholicOfferModel::extend(function ($obModel) {
            $obModel->fillable[] = 'variation';
            $obModel->fillable[] = 'preview_video';
            // external_id feeds OfferColorGrouper (color-lab API join on 1C UUID)
            $obModel->addCachedField(['variation', 'preview_video', 'external_id']);
        });
    }

    public static function extendXMLImporter()
    {
        Event::listen(ImportProductModelFromXML::EXTEND_FIELD_LIST, function ($arFieldList) {
            $arCustumFields = [
                'video_link' => 'Video link/Youtube ID',
                'how_to' => 'How to - Step by step',
                'popularity' => 'Popularity',
                'search_synonym' => 'Search Synonym, tags',
                'search_content' => 'Search Content, tags',
                'hide_dropdown' => 'Hide dropdown and show variation images',
                'source_vat_rate' => 'Source Tax Rate (НДС Ставка)',
            ];
            $arFieldList = array_merge($arFieldList, $arCustumFields);
            return $arFieldList;
        }, 900);

        Event::listen(ImportCategoryModelFromXML::EXTEND_FIELD_LIST, function ($arFieldList) {
            $arCustumFields = [
                'search_synonym' => 'Search Synonym, tags',
                'search_content' => 'Search Content, tags',
            ];
            $arFieldList = array_merge($arFieldList, $arCustumFields);
            return $arFieldList;
        }, 900);

        Event::listen(ImportOfferModelFromXML::EXTEND_FIELD_LIST, function ($arFieldList) {
            $arCustumFields = [
                'variation' => 'Offer variation ID',
            ];
            $arFieldList = array_merge($arFieldList, $arCustumFields);
            return $arFieldList;
        }, 900);
    }

    public static function extendShopaholicOrderModel()
    {
        ShopaholicOrderModel::extend(function ($obModel) {
            $obModel->addCachedField(['manager_id', 'transaction_id']);
        });
    }

    public static function extendShopaholicCurrenciesController()
    {
        ShopaholicCurrenciesController::extendFormFields(function ($widget) {
            if (!$widget->model instanceof ShopaholicCurrencyModel) {
                return;
            }

            $widget->addFields([
                'rate' => [
                    'label' => 'lovata.shopaholic::lang.field.rate',
                    'span'  => 'right',
                    'type'  => 'text',
                ],
            ]);
        });
    }

    /**
     * The plugin directory. The two form-field extensions read their YAML
     * relative to it, and __DIR__ in this file is classes/registrar, two
     * levels below the root Plugin.php resolved to.
     *
     * @return string
     */
    private static function getPluginPath(): string
    {
        return dirname(__DIR__, 2);
    }
}
