<?php namespace Logingrupa\StoreExtender;

// use App;
use File;
use Omnipay\Omnipay;
use Yaml;
use Event;
use Backend;
use System\Classes\PluginBase;

// use Illuminate\Foundation\AliasLoader;
use Lovata\Shopaholic\Models\Offer as ShopaholicOfferModel;
use Lovata\Shopaholic\Models\Product as ShopaholicProductModel;
use Lovata\OrdersShopaholic\Models\Order as ShopaholicOrderModel;
use Lovata\Shopaholic\Models\Currency as ShopaholicCurrencyModel;
use Lovata\Shopaholic\Controllers\Currencies as ShopaholicCurrenciesController;
use Lovata\Shopaholic\Controllers\Products as ShopaholicProductsController;
use Lovata\Shopaholic\Controllers\Offers as ShopaholicOffersController;
use Lovata\Shopaholic\Models\Tax as ShopaholicTaxModel;
use Lovata\Shopaholic\Models\XmlImportSettings;
use Lovata\Shopaholic\Classes\Import\ImportOfferModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportProductModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportCategoryModelFromXML;
use Lovata\Toolbox\Classes\Helper\AbstractImportModel;

//Events
use Logingrupa\StoreExtender\Classes\Event\ExtendPaymentGateway;
use Logingrupa\StoreExtender\Classes\Event\ExtendMenuHandler;
use Logingrupa\StoreExtender\Classes\Event\ExtendOfferHandler;

//Offer events
use Logingrupa\StoreExtender\Classes\Event\Offer\ExtendOfferImport;

//User group events
use Logingrupa\StoreExtender\Classes\Event\UserGroup\ExtendUserGroupModel;
use Logingrupa\StoreExtender\Classes\Event\UserGroup\ExtendUserGroupController;

//User events
use Logingrupa\StoreExtender\Classes\Event\User\UserModelHandler;
use Logingrupa\StoreExtender\Classes\Event\User\ExtendUserController;

//Cart component events
use Logingrupa\StoreExtender\Classes\Event\Cart\CartComponentHandler;

//CartPosition events
use Logingrupa\StoreExtender\Classes\Event\CartPosition\CartPositionItemHandler;

//Order position
use Logingrupa\StoreExtender\Classes\Event\OrderPosition\OrderPositionItemHandler;

//Currency rounding
use Logingrupa\StoreExtender\Classes\Event\Currency\ExtendCurrencyConversion;

/**
 * StoreExtender Plugin Information File
 */
class Plugin extends PluginBase
{
    public $require = ['Lovata.DiscountsShopaholic', 'Lovata.Toolbox', 'Lovata.Shopaholic', 'Lovata.OrdersShopaholic'];

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name' => 'StoreExtender',
            'description' => 'No description provided yet...',
            'author' => 'Logingrupa',
            'icon' => 'icon-leaf'
        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     *
     * @return void
     */
    public function register()
    {
        $this->registerConsoleCommand('storeextender.sqlimport', 'Logingrupa\StoreExtender\Console\SqlImportCommand');
    }

    /**
     * Boot method, called right before the request route.
     */
    public function boot()
    {
        $factory = Omnipay::getFactory();
        $factory->register('PayPal_Express');
        // Register ServiceProviders
        // App::register('\NikKanetiya\LaravelColorPalette\ColorPaletteServiceProvider');

        // Register aliases
        // $alias = AliasLoader::getInstance();
        // $alias->alias('ColorPalette', 'NikKanetiya\LaravelColorPalette\ColorPaletteFacade');
        $this->extendShopaholicProductsController();
        $this->extendShopaholicOffersController();
        $this->extendShopaholicProductModel();
        $this->extendShopaholicOfferModel();
        $this->extendXMLImporter();
        $this->extendShopaholicOrderModel();
        Event::subscribe(ExtendPaymentGateway::class);
        Event::subscribe(ExtendMenuHandler::class);
        //Offer events
        Event::subscribe(ExtendOfferImport::class);
        //User group events
        Event::subscribe(ExtendUserGroupModel::class);
        Event::subscribe(ExtendUserGroupController::class);
        Event::subscribe(UserModelHandler::class);
        //User events
        Event::subscribe(ExtendUserController::class);
        //Cart component events
        Event::subscribe(CartComponentHandler::class);
        //CartPosition events
        Event::subscribe(CartPositionItemHandler::class);
        //Order position
        Event::subscribe(OrderPositionItemHandler::class);
        //Offer sort by Name ASC
        Event::subscribe(ExtendOfferHandler::class);

        //Currency rounding for NOK, SEK, DKK
        ExtendCurrencyConversion::swapCurrencyHelper();

        //Extend currency form to allow more decimal places in rate field
        $this->extendShopaholicCurrenciesController();

        //Auto-link products to target Tax entries during import based on VAT mapping
        Event::listen(AbstractImportModel::EVENT_AFTER_IMPORT, function ($obModel, $arImportData) {
            if ($obModel instanceof ShopaholicProductModel) {
                $this->autoLinkProductTax($obModel, $arImportData);
            }
        });
    }

    public function extendShopaholicProductsController()
    {
        ShopaholicProductsController::extendFormFields(function ($widget) {
            // Prevent extending of related form instead of the intended ShopaholicProductModel form
            if (!$widget->model instanceof ShopaholicProductModel) {
                return;
            }
            $configTabFields = Yaml::parse(File::get(__DIR__ . '/config/shopaholic/addAditionalSettingsTab.yaml'));
            $widget->addTabFields($configTabFields);
        });
    }

    public function extendShopaholicOffersController()
    {
        ShopaholicOffersController::extendFormFields(function ($widget) {
            // Prevent extending of related form instead of the intended ShopaholicProductModel form
            if (!$widget->model instanceof ShopaholicOfferModel) {
                return;
            }
            $widget->removeField('preview_image');
            $widget->removeField('images');
            $configTabFields = Yaml::parse(File::get(__DIR__ . '/config/shopaholic/addToImagesTabVideoPreview.yaml'));
            $widget->addTabFields($configTabFields);
        });
    }

    public function extendShopaholicProductModel()
    {
        ShopaholicProductModel::extend(function ($obModel) {
            $obModel->translatable[] = 'how_to';
            $obModel->translatable[] = 'video_link';
            $obModel->addCachedField(['how_to', 'video_link', 'hide_dropdown']);
            $obModel->fillable[] = 'how_to';
            $obModel->fillable[] = 'video_link';
            $obModel->fillable[] = 'hide_dropdown';
        });
    }

    public function extendShopaholicOfferModel()
    {
        ShopaholicOfferModel::extend(function ($obModel) {
            $obModel->fillable[] = 'variation';
            $obModel->fillable[] = 'preview_video';
            $obModel->addCachedField(['variation', 'preview_video']);
        });
    }

    public function extendXMLImporter()
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

    public function extendShopaholicOrderModel()
    {
        ShopaholicOrderModel::extend(function ($obModel) {
            $obModel->addCachedField(['manager_id', 'transaction_id']);
        });
    }

    public function extendShopaholicCurrenciesController()
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
     * Auto-link product to the correct target Tax entry based on VAT mapping
     * Runs during product import (EVENT_AFTER_IMPORT)
     *
     * @param ShopaholicProductModel $obProduct
     * @param array $arImportData
     */
    protected function autoLinkProductTax($obProduct, $arImportData)
    {
        $bVatRecalculateEnabled = (bool) XmlImportSettings::getValue('import_vat_recalculate_enable', false);
        if (!$bVatRecalculateEnabled) {
            return;
        }

        $arVatMapping = (array) XmlImportSettings::getValue('import_vat_mapping', []);
        if (empty($arVatMapping)) {
            return;
        }

        $fSourceVatRate = array_get($arImportData, 'source_vat_rate');
        if ($fSourceVatRate === null || $fSourceVatRate === '') {
            return;
        }

        $fSourceVatRate = (float) $fSourceVatRate;

        // Get the default source rate (first mapping row)
        $arDefaultMapping = array_first($arVatMapping);
        $fDefaultSourceRate = (float) array_get($arDefaultMapping, 'source_vat_rate', 0);

        // If product has the default source VAT rate, skip — global tax handles it
        if ($fSourceVatRate == $fDefaultSourceRate) {
            return;
        }

        // Find the mapping row that matches this product's source VAT rate
        foreach ($arVatMapping as $arMappingRow) {
            $fMappingSourceRate = (float) array_get($arMappingRow, 'source_vat_rate', 0);
            if ($fMappingSourceRate == $fSourceVatRate) {
                $iTargetTaxId = (int) array_get($arMappingRow, 'target_tax_id', 0);
                if (!empty($iTargetTaxId)) {
                    $obTargetTax = ShopaholicTaxModel::find($iTargetTaxId);
                    if (!empty($obTargetTax)) {
                        $obTargetTax->product()->syncWithoutDetaching([$obProduct->id]);
                    }
                }

                return;
            }
        }
    }

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        // return []; // Remove this line to activate

        return [
            // 'Logingrupa\StoreExtender\Components\MyComponent' => 'myComponent',
            'Logingrupa\Storeextender\Components\CustomProductPage' => 'CustomProductPage',
        ];
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return []; // Remove this line to activate

        return [
            'logingrupa.storeextender.some_permission' => [
                'tab' => 'StoreExtender',
                'label' => 'Some permission'
            ],
        ];
    }

    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        // return []; // Remove this line to activate

        return [
            'storeextender' => [
                'label' => 'Edit frontend',
                'url' => Backend::url('cms/themeoptions/update/logingrupa-naisstore'),
                'icon' => 'icon-laptop',
                'permissions' => ['logingrupa.storeextender.*'],
                'order' => 500,
            ],
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'filters' => [
                // A global function, i.e str_plural()
                'plural' => 'str_plural',
                'highlight' => [$this, 'makeTextHighlighted'],
                // A local method, i.e $this->makeTextAllCaps()
                'uppercase' => [$this, 'makeTextAllCaps'],
                // Currency-aware price formatting (e.g., "225,-" for NOK)
                'currency_price' => [ExtendCurrencyConversion::class, 'formatPrice'],
            ],
            'functions' => [

                // Using an inline closure
                'helloWorld' => function () {
                    return 'Hello World!';
                }
            ]
        ];
    }

    public function makeTextHighlighted($text, $terms)
    {
        if (!is_array($terms)) $terms = [$terms];
        $highlight = array();
        foreach ($terms as $term) {
            $highlight[] = '<span class="highlight">' . $term . '</span>';
        }
        // dd(str_ireplace($terms, $highlight, $text));
        return str_ireplace($terms, $highlight, $text);
    }

    public function makeTextAllCaps($text)
    {
        return strtoupper($text);
    }

    public function registerFormWidgets()
    {
        return [
            'Logingrupa\Storeextender\FormWidgets\VideoFormWidget' => 'VideoFormWidget',
        ];
    }


}
