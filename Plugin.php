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
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Classes\Import\ImportOfferModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportProductModelFromXML;
use Lovata\Shopaholic\Classes\Import\ImportCategoryModelFromXML;

//Events
use Logingrupa\StoreExtender\Classes\Event\ExtendPaymentGateway;
use Logingrupa\StoreExtender\Classes\Event\ExtendMenuHandler;
use Logingrupa\StoreExtender\Classes\Event\ExtendOfferHandler;

//Offer events
use Logingrupa\StoreExtender\Classes\Event\Offer\ExtendOfferImportMetadata;
use Logingrupa\StoreExtender\Classes\Event\Offer\OfferDiscountImportSubscriber;

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
//Product events
use Logingrupa\StoreExtender\Classes\Event\Product\ExtendProductFieldsHandler as StoreExtenderExtendProductFieldsHandler;
use Logingrupa\StoreExtender\Classes\Event\Product\ProductModelHandler as StoreExtenderProductModelHandler;
use Logingrupa\StoreExtender\Classes\Event\Product\ExtendProductImport as StoreExtenderExtendProductImport;

//Currency rounding
use Logingrupa\StoreExtender\Classes\Event\Currency\ExtendCurrencyConversion;

//Settings multisite fallback
use Logingrupa\StoreExtender\Classes\Event\Settings\SettingsSiteFallbackHandler;

//Vite asset pipeline for migrated theme pages
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use Logingrupa\StoreExtender\Classes\Helper\OfferRenderContext;
use Logingrupa\StoreExtender\Classes\Helper\ViteAssetHelper;

/**
 * StoreExtender Plugin Information File
 */
class Plugin extends PluginBase
{
    public $require = ['Lovata.DiscountsShopaholic', 'Lovata.Toolbox', 'Lovata.Shopaholic', 'Lovata.OrdersShopaholic', 'Logingrupa.CustomXMLImportPricing'];

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
        $this->registerConsoleCommand('storeextender.syncoffercolors', 'Logingrupa\StoreExtender\Console\SyncOfferColors');
        $this->registerConsoleCommand('storeextender.importthememessages', 'Logingrupa\StoreExtender\Console\ImportThemeMessages');
        $this->registerConsoleCommand('storeextender.verifyxmlimportsettings', 'Logingrupa\StoreExtender\Console\VerifyXmlImportSettings');
        $this->registerConsoleCommand('storeextender.warmofferthumbs', 'Logingrupa\StoreExtender\Console\WarmOfferThumbs');

        // Extend `mail.manager` so every Mail::*() entry point routes through SafeMailer.
        // MUST use extend() not singleton(): Laravel's MailServiceProvider is a
        // DeferrableProvider, so it registers `mail.manager` lazily on first Mail call -
        // AFTER plugin register(). A singleton() rebind here gets clobbered when the
        // deferred provider finally registers. extend() accumulates regardless of
        // registration order and runs on resolve, after the original binding is built.
        $this->app->extend('mail.manager', function ($obOriginal, $app) {
            $this->app['events']->dispatch('mailer.beforeRegister', [$this]);
            $obManager = new \Logingrupa\StoreExtender\Classes\Mail\SafeMailManager($app);
            $this->app['events']->dispatch('mailer.register', [$this, $obManager]);
            return $obManager;
        });
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
        // Extend ThemeData/MLThemeData with dropdown option methods needed by theme
        // customization form. Hooks into form field building to guarantee methods exist
        // on whichever model class the form is using at render time.
        $this->extendThemeDataDropdownMethods();
        $this->extendThemeOptionsController();
        $this->registerProductPageLookupType();
        $this->registerSlugPageLookupTypes();

        $this->extendShopaholicProductsController();
        $this->extendShopaholicOffersController();
        $this->extendShopaholicProductModel();
        $this->extendShopaholicOfferModel();
        $this->extendItemEagerLoading();
        $this->extendXMLImporter();
        $this->extendShopaholicOrderModel();
        Event::subscribe(ExtendPaymentGateway::class);
        Event::subscribe(ExtendMenuHandler::class);
        //Offer events: PASS 1 metadata + discount steps. The PASS 2 price
        //pipeline moved to Logingrupa.CustomXMLImportPricing; the discount
        //steps hang on its after_vat/after_factor hooks (03-migration.md C.4).
        Event::subscribe(ExtendOfferImportMetadata::class);
        Event::subscribe(OfferDiscountImportSubscriber::class);
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

        //Shopaholic settings fallback to primary site values on sites without own record
        Event::subscribe(SettingsSiteFallbackHandler::class);

        //Product events: extend backend fields and model
        Event::subscribe(StoreExtenderExtendProductFieldsHandler::class);
        Event::subscribe(StoreExtenderProductModelHandler::class);
        Event::subscribe(StoreExtenderExtendProductImport::class);

        //Currency rounding for NOK, SEK, DKK
        ExtendCurrencyConversion::swapCurrencyHelper();

        //Extend currency form to allow more decimal places in rate field
        $this->extendShopaholicCurrenciesController();

        //Auto-link products to target Tax entries during import: MOVED to
        //Logingrupa.CustomXMLImportPricing (ProductTaxAutoLinkHandler) - it
        //consumes exclusively moved settings keys (00-context.md Amendment 4).

        //Redirect to order checkout page instead of homepage after payment cancel/return
        $this->addPaymentGatewayRedirectListeners();
    }

    /**
     * Listen to Omnipay gateway cancel/return events and redirect
     * back to the order checkout page instead of homepage.
     */
    protected function addPaymentGatewayRedirectListeners(): void
    {
        $fnGetCheckoutURL = function ($obOrder) {
            if (empty($obOrder) || empty($obOrder->secret_key)) {
                return null;
            }

            // Find CMS page with OrderPage component dynamically
            $sPageName = $this->findOrderPage();

            if (!empty($sPageName)) {
                return \Cms\Classes\Page::url($sPageName, ['slug' => $obOrder->secret_key]);
            }

            return null;
        };

        Event::listen(
            \Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_CANCEL_URL,
            $fnGetCheckoutURL
        );

        Event::listen(
            \Lovata\OmnipayShopaholic\Classes\Helper\PaymentGateway::EVENT_GET_PAYMENT_GATEWAY_RETURN_URL,
            $fnGetCheckoutURL
        );
    }

    /**
     * Find the first CMS page that has the OrderPage component.
     * Skips proforma/print pages by preferring pages without :print param.
     *
     * @return string|null CMS page file name
     */
    protected function findOrderPage(): ?string
    {
        $obTheme = \Cms\Classes\Theme::getActiveTheme();
        $arPages = \Cms\Classes\Page::listInTheme($obTheme);

        $sFirstMatch = null;

        foreach ($arPages as $obPage) {
            $arComponents = $obPage->settings['components'] ?? [];

            if (!isset($arComponents['OrderPage'])) {
                continue;
            }

            // Skip proforma/print pages
            if (str_contains($obPage->url, ':print')) {
                continue;
            }

            return $obPage->getBaseFileName();
        }

        return $sFirstMatch;
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
    public function extendItemEagerLoading()
    {
        ProductItem::$arQueryWith = array_merge(ProductItem::$arQueryWith, [
            'translations',
            'preview_image.translations',
            'images.translations',
            'offer.translations',
            'offer.preview_image.translations',
            'offer.images.translations',
        ]);

        OfferItem::$arQueryWith = array_merge(OfferItem::$arQueryWith, [
            'translations',
            'preview_image.translations',
            'images.translations',
        ]);

        CategoryItem::$arQueryWith = array_merge(CategoryItem::$arQueryWith, [
            'translations',
            'preview_image.translations',
            'icon.translations',
            'images.translations',
        ]);
    }

    public function extendShopaholicProductModel()
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

    public function extendShopaholicOfferModel()
    {
        ShopaholicOfferModel::extend(function ($obModel) {
            $obModel->fillable[] = 'variation';
            $obModel->fillable[] = 'preview_video';
            // external_id feeds OfferColorGrouper (color-lab API join on 1C UUID)
            $obModel->addCachedField(['variation', 'preview_video', 'external_id']);
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
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        // return []; // Remove this line to activate

        return [
            'Logingrupa\Storeextender\Components\CustomProductPage' => 'CustomProductPage',
            'Logingrupa\Storeextender\Components\LazyPromoBlockLoader' => 'LazyPromoBlockLoader',
            'Logingrupa\Storeextender\Components\OfferSheet' => 'OfferSheet',
        ];
    }

    /**
     * Register scheduled tasks
     * @param \Illuminate\Console\Scheduling\Schedule $obSchedule
     */
    public function registerSchedule($obSchedule)
    {
        // Hourly, matching the export's own Cache-Control: max-age=3600, so
        // the storefront is at most an hour behind a curation change. Twice a
        // day left a real gap: the 2026-08-05 regrouping landed at 09:49 UTC,
        // just after the 09:00 run, and would have sat unseen until 13:00.
        // An unchanged export costs one 304 and no write, because the command
        // compares the payload version against the one it last imported.
        $obSchedule->command('storeextender:sync-offer-colors')->hourly();
    }

    /**
     * Register file-based mail partials.
     * `bankdetails` is read from views/mail/bankdetails.htm at runtime, allowing each
     * deployed site to render its own seller block from its own theme settings DB.
     *
     * Note: a DB row with code='bankdetails' in system_mail_partials takes precedence
     * over this file. Existing sites must delete that row once to switch over.
     *
     * @return array
     */
    public function registerMailPartials()
    {
        return [
            'bankdetails' => 'logingrupa.storeextender::mail.bankdetails',
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
                },
                // Script/style tags for a Vite entry built into the active theme
                'vite_entry' => [ViteAssetHelper::class, 'renderEntry'],
                // Sized offer image derivatives - the ONLY way a template is
                // allowed to size an offer picture, so the sizes stay in one place
                'offer_swatch_src' => [OfferImageHelper::class, 'swatch'],
                'offer_preview_src' => [OfferImageHelper::class, 'preview'],
                // Which offer a product-card fragment renders - decided in ONE
                // place, because one batched response renders many offers and
                // request state cannot answer that question for a single render
                'offer_render_context' => [OfferRenderContext::class, 'resolve'],
                'theme_var' => function ($sKey) {
                    $obTheme = \Cms\Classes\Theme::getActiveTheme();
                    if (empty($obTheme)) {
                        return null;
                    }
                    $obData = $obTheme->getCustomData();
                    return $obData ? ($obData->{$sKey} ?? null) : null;
                },
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

    /**
     * extendThemeDataDropdownMethods hooks into form field building to add dynamic
     * dropdown option methods to the theme customization model. This covers both
     * ThemeData (primary form) and MLThemeData (RainLab Translate proxy), regardless
     * of instantiation order.
     */
    protected function extendThemeDataDropdownMethods()
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
                return \Lovata\Buddies\Models\Property::lists('name', 'code');
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
    protected function extendThemeOptionsController()
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

    /**
     * registerProductPageLookupType registers a "shop-product" type for the
     * pagefinder widget so individual products can be selected as link targets.
     */
    protected function registerProductPageLookupType()
    {
        // Register shop-product type on both event sets (same pattern as RainLab Pages)
        Event::listen(['cms.pageLookup.listTypes', 'pages.menuitem.listTypes'], function () {
            return ['shop-product' => 'Product'];
        });

        Event::listen(['cms.pageLookup.getTypeInfo', 'pages.menuitem.getTypeInfo'], function ($sType) {
            if ($sType !== 'shop-product') {
                return;
            }

            $arReferences = \Lovata\Shopaholic\Models\Product::lists('name', 'id');

            $obTheme = \Cms\Classes\Theme::getActiveTheme();
            $obPageList = \Cms\Classes\Page::listInTheme($obTheme, true);
            $arCmsPages = [];
            foreach ($obPageList as $obPage) {
                if (!$obPage->hasComponent('CustomProductPage')) {
                    continue;
                }

                $arPropertyList = $obPage->getComponentProperties('CustomProductPage');
                if (!isset($arPropertyList['slug']) || !preg_match('/{{\s*:/', $arPropertyList['slug'])) {
                    continue;
                }

                $arCmsPages[] = $obPage;
            }

            return [
                'references' => $arReferences,
                'cmsPages' => $arCmsPages,
            ];
        });

        Event::listen(['cms.pageLookup.resolveItem', 'pages.menuitem.resolveItem'], function ($sType, $obItem, $sURL) {
            if ($sType !== 'shop-product') {
                return;
            }

            if (empty($obItem->reference)) {
                return [];
            }

            $obProductItem = \Lovata\Shopaholic\Classes\Item\ProductItem::make($obItem->reference);
            if ($obProductItem->isEmpty()) {
                return [];
            }

            // Build URL via Page::url() because getPageUrl() fails when the
            // ProductPage component is aliased as "CustomProductPage ProductPage"
            // - Lovata's PageHelper regex only matches keys starting with "ProductPage".
            $sPageUrl = \Cms\Classes\Page::url(
                $obItem->cmsPage ?: 'product',
                ['slug' => $obProductItem->slug]
            );

            return [
                'title' => $obProductItem->name,
                'url' => $sPageUrl,
                'isActive' => $sPageUrl == $sURL,
                'mtime' => $obProductItem->updated_at,
            ];
        });

        // Mirror Shopaholic's category/catalog types to cms.pageLookup.* events
        // (Shopaholic only registers on pages.menuitem.* - pagefinder needs both)
        $arShopaholicMenuTypes = [
            \Lovata\Shopaholic\Classes\Helper\CatalogMenuType::MENU_TYPE => \Lovata\Shopaholic\Classes\Helper\CatalogMenuType::class,
            \Lovata\Shopaholic\Classes\Helper\CategoryMenuType::MENU_TYPE => \Lovata\Shopaholic\Classes\Helper\CategoryMenuType::class,
            \Lovata\Shopaholic\Classes\Helper\AllCategoriesMenuType::MENU_TYPE => \Lovata\Shopaholic\Classes\Helper\AllCategoriesMenuType::class,
        ];

        Event::listen('cms.pageLookup.listTypes', function () {
            return [
                \Lovata\Shopaholic\Classes\Helper\CatalogMenuType::MENU_TYPE => 'lovata.shopaholic::lang.menu.shop_catalog',
                \Lovata\Shopaholic\Classes\Helper\CategoryMenuType::MENU_TYPE => 'lovata.shopaholic::lang.menu.shop_category',
                \Lovata\Shopaholic\Classes\Helper\AllCategoriesMenuType::MENU_TYPE => 'lovata.shopaholic::lang.menu.all_shop_categories',
            ];
        });

        Event::listen('cms.pageLookup.getTypeInfo', function ($sType) use ($arShopaholicMenuTypes) {
            if (!isset($arShopaholicMenuTypes[$sType])) {
                return;
            }
            return (new $arShopaholicMenuTypes[$sType]())->getMenuTypeInfo();
        });

        Event::listen('cms.pageLookup.resolveItem', function ($sType, $obItem, $sURL) use ($arShopaholicMenuTypes) {
            if (!isset($arShopaholicMenuTypes[$sType])) {
                return;
            }
            return (new $arShopaholicMenuTypes[$sType]())->resolveMenuItem($obItem, $sURL);
        });
    }

    /**
     * registerSlugPageLookupTypes registers slug-keyed pagefinder types for
     * products and promo blocks.
     *
     * The id-keyed "shop-product" type has to load the whole ProductItem -
     * offers, images, categories - only to read one field, its slug. Measured
     * on the home page's seven campaign banners that is 140 queries on a cold
     * cache, 20 per banner, against 0 for these types: the route takes a slug
     * and the stored reference already IS the slug, so nothing has to be
     * loaded to build the URL.
     *
     * Slugs are also stable across environments where ids are not, so one
     * theme-data record works on local, .lv, .no and .lt alike.
     *
     * @return void
     */
    protected function registerSlugPageLookupTypes(): void
    {
        $arTypeList = [
            'shop-product-slug' => [
                'label'    => 'Product (by slug)',
                'page'     => 'product',
                'model'    => \Lovata\Shopaholic\Models\Product::class,
                'listType' => 'shop-product-slug',
            ],
            'shop-promo-block-slug' => [
                'label'    => 'Promo block (by slug)',
                'page'     => 'promo-block-page',
                'model'    => \Lovata\Shopaholic\Models\PromoBlock::class,
                'listType' => 'shop-promo-block-slug',
            ],
        ];

        Event::listen(['cms.pageLookup.listTypes', 'pages.menuitem.listTypes'], function () use ($arTypeList) {
            return array_map(function ($arType) {
                return $arType['label'];
            }, $arTypeList);
        });

        Event::listen(['cms.pageLookup.getTypeInfo', 'pages.menuitem.getTypeInfo'], function ($sType) use ($arTypeList) {
            if (!isset($arTypeList[$sType])) {
                return;
            }

            // The backend dropdown is keyed by slug, so what the editor picks is
            // what gets stored - no id ever enters the value
            $sModelClass = $arTypeList[$sType]['model'];
            $arReferences = $sModelClass::orderBy('name')->pluck('name', 'slug')->all();

            return [
                'references'    => $arReferences,
                'nesting'       => false,
                'dynamicItems'  => false,
            ];
        });

        Event::listen(['cms.pageLookup.resolveItem', 'pages.menuitem.resolveItem'], function ($sType, $obItem, $sURL) use ($arTypeList) {
            if (!isset($arTypeList[$sType])) {
                return;
            }

            $sSlug = (string) ($obItem->reference ?? '');
            if ($sSlug === '') {
                return [];
            }

            // No model load: Page::url resolves the route in the ACTIVE locale,
            // which is what makes one stored value render /lv/, /en/ and /ru/
            // links from the same repeater row
            $sPageUrl = \Cms\Classes\Page::url(
                $obItem->cmsPage ?: $arTypeList[$sType]['page'],
                ['slug' => $sSlug]
            );

            return [
                'title'    => $sSlug,
                'url'      => $sPageUrl,
                'isActive' => $sPageUrl == $sURL,
            ];
        });
    }

}
