<?php namespace Logingrupa\StoreExtender;

// use App;
use Lang;
use Config;
use Omnipay\Omnipay;
use Event;
use Backend;
use Throwable;
use Illuminate\Support\Facades\Log;
use System\Classes\PluginBase;

// use Illuminate\Foundation\AliasLoader;

//Events
use Logingrupa\StoreExtender\Classes\Event\ExtendPaymentGateway;
use Logingrupa\StoreExtender\Classes\Event\ExtendMenuHandler;
use Logingrupa\StoreExtender\Classes\Event\Category\PrimeCategoryTreeHandler;
use Logingrupa\StoreExtender\Classes\Event\ExtendOfferHandler;
use Logingrupa\StoreExtender\Classes\Event\Device\DeviceLayoutHandler;

//Offer events
use Logingrupa\StoreExtender\Classes\Event\Offer\ExtendOfferImportMetadata;
use Logingrupa\StoreExtender\Classes\Event\Offer\OfferDiscountImportSubscriber;

//User group events
use Logingrupa\StoreExtender\Classes\Event\UserGroup\ExtendUserGroupModel;
use Logingrupa\StoreExtender\Classes\Event\UserGroup\ExtendUserGroupController;

//User events
use Logingrupa\StoreExtender\Classes\Event\User\UserModelHandler;
use Logingrupa\StoreExtender\Classes\Event\User\ExtendUserController;
use Logingrupa\StoreExtender\Classes\Event\User\ExtendUserPropertyFieldHandler;
use Logingrupa\StoreExtender\Classes\Event\User\RainLabRegistrationHandler;
use Logingrupa\StoreExtender\Classes\Event\User\UserIpAddressHandler;
use Logingrupa\StoreExtender\Classes\Event\User\AccountCartIdentityHandler;
use Logingrupa\StoreExtender\Classes\Event\User\PhoneLoginHandler;

//Cart component events
use Logingrupa\StoreExtender\Classes\Event\Cart\CartComponentHandler;

use Logingrupa\StoreExtender\Classes\Event\Metapixel\MarginValueHandler;
use Logingrupa\StoreExtender\Classes\Event\Metapixel\GuestCheckoutIdentityHandler;

use Logingrupa\StoreExtender\Classes\Event\Import\PropertyImportGuardHandler;

//Color Family property slug pinning
use Logingrupa\StoreExtender\Classes\Event\Property\ColorFamilySlugHandler;
use Logingrupa\StoreExtender\Classes\Event\Cache\SatelliteCacheInvalidationHandler;
use Logingrupa\StoreExtender\Classes\Event\Image\WarmDerivativesOnAttach;
use Logingrupa\StoreExtender\Classes\Queue\WarmImageDerivatives;
use Logingrupa\StoreExtender\Classes\Event\Price\EqualOldPriceHandler;
use Logingrupa\StoreExtender\Classes\Event\Seo\SlugHistoryHandler;
use Logingrupa\StoreExtender\Classes\Event\Seo\LegacyUrlRedirectHandler;
use Logingrupa\StoreExtender\Classes\Event\Review\ReviewValidationHandler;

//CartPosition events
use Logingrupa\StoreExtender\Classes\Event\CartPosition\CartPositionItemHandler;

//Order position
use Logingrupa\StoreExtender\Classes\Event\OrderPosition\OrderPositionItemHandler;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderPropertySecretHandler;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderUserPhoneHandler;
//Product events
use Logingrupa\StoreExtender\Classes\Event\Product\ExtendProductFieldsHandler as StoreExtenderExtendProductFieldsHandler;
use Logingrupa\StoreExtender\Classes\Event\Product\ProductModelHandler as StoreExtenderProductModelHandler;
use Logingrupa\StoreExtender\Classes\Event\Product\ExtendProductImport as StoreExtenderExtendProductImport;

//Currency rounding
use Logingrupa\StoreExtender\Classes\Event\Currency\ExtendCurrencyConversion;

//Settings multisite fallback
use Logingrupa\StoreExtender\Classes\Event\Settings\SettingsSiteFallbackHandler;

//Cart cookie identity
use Logingrupa\StoreExtender\Classes\Middleware\ClearShadowCartCookie;
use Logingrupa\StoreExtender\Classes\Middleware\DeviceVaryHeader;

//Registration surfaces boot() delegates to, one class per responsibility
use Logingrupa\StoreExtender\Classes\Registrar\PageLookupRegistrar;
use Logingrupa\StoreExtender\Classes\Registrar\PaymentRedirectRegistrar;
use Logingrupa\StoreExtender\Classes\Registrar\ShopaholicExtensionRegistrar;
use Logingrupa\StoreExtender\Classes\Registrar\ThemeDataRegistrar;

//Vite asset pipeline for migrated theme pages
use Logingrupa\StoreExtender\Classes\Helper\ColorFamilyHelper;
use Logingrupa\StoreExtender\Classes\Helper\LocalizedMediaHelper;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use Logingrupa\StoreExtender\Classes\Helper\OfferRenderContext;
use Logingrupa\StoreExtender\Classes\Helper\ProductStructuredData;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;
use Logingrupa\StoreExtender\Classes\Helper\SearchOfferHelper;
use Logingrupa\StoreExtender\Classes\Helper\ViteAssetHelper;
use Logingrupa\StoreExtender\Classes\Helper\RainLabUserHelperFix;
use Logingrupa\StoreExtender\Classes\Ajax\SafeAjaxResponse;

/**
 * StoreExtender Plugin Information File
 */
class Plugin extends PluginBase
{
    const MAIL_SALON_LEAD_MANAGER = 'logingrupa.storeextender::mail.salon_lead_manager';
    const MAIL_SALON_LEAD_APPLICANT = 'logingrupa.storeextender::mail.salon_lead_applicant';
    const MAIL_MD_RESERVATION_DELETED = 'logingrupa.storeextender::mail.md_reservation_deleted';
    const MAIL_MD_RESERVATION_REMINDER = 'logingrupa.storeextender::mail.md_reservation_reminder';

    public $require = ['Lovata.DiscountsShopaholic', 'Lovata.Toolbox', 'Lovata.Shopaholic', 'Lovata.OrdersShopaholic', 'Logingrupa.CustomXMLImportPricing', 'RainLab.User', 'RainLab.Pages'];

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
        $this->registerConsoleCommand('storeextender.purgeorderpropertysecrets', 'Logingrupa\StoreExtender\Console\PurgeOrderPropertySecrets');
        $this->registerConsoleCommand('storeextender.migratebuddiesusers', 'Logingrupa\StoreExtender\Console\MigrateBuddiesUsers');
        $this->registerConsoleCommand('storeextender.refreshpickuppoints', 'Logingrupa\StoreExtender\Console\RefreshPickupPoints');

        // Toolbox RainLabUserHelper::findUserByEmail() calls a method RainLab.User 3.5.3
        // does not define. UserHelper resolves its inner helper through the container, so
        // binding the fixed subclass here reaches every caller, checkout included.
        $this->app->bind(\Lovata\Toolbox\Classes\Helper\Users\RainLabUserHelper::class, RainLabUserHelperFix::class);

        // ajax() resolves the response class from the container on every call.
        \Larajax\Classes\AjaxResponse::registerCustomResponse(SafeAjaxResponse::class);

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
        // lang/<locale>/validation.php supplies validation.attributes for the user
        // fields, so every validator names them in the shopper's language. Plugin
        // namespaces cannot override the core validation group; a loader path can.
        Lang::getLoader()->addPath(__DIR__ . '/lang');

        // A duplicate shopaholic_cart_id cookie (stale longer-path shadow)
        // makes CartProcessor mint a fresh cart per request: adds succeed
        // into throwaway carts while the header/sidebar read empty ones.
        // Frontend only - the backend never resolves a guest cart.
        \Cms\Classes\CmsController::extend(function ($obController) {
            $obController->middleware(ClearShadowCartCookie::class);
            $obController->middleware(DeviceVaryHeader::class);
        });

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
        $this->shareMailBrandLogo();
        ThemeDataRegistrar::extendThemeDataDropdownMethods();
        ThemeDataRegistrar::extendThemeOptionsController();
        PageLookupRegistrar::registerProductPageLookupType();
        PageLookupRegistrar::registerSlugPageLookupTypes();

        ShopaholicExtensionRegistrar::extendShopaholicProductsController();
        ShopaholicExtensionRegistrar::extendShopaholicOffersController();
        ShopaholicExtensionRegistrar::extendShopaholicProductModel();
        ShopaholicExtensionRegistrar::extendShopaholicOfferModel();
        ShopaholicExtensionRegistrar::extendItemEagerLoading();
        ShopaholicExtensionRegistrar::extendCategoryChildrenMapReset();
        ShopaholicExtensionRegistrar::extendXMLImporter();
        ShopaholicExtensionRegistrar::extendShopaholicOrderModel();
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
        Event::subscribe(ExtendUserPropertyFieldHandler::class);
        Event::subscribe(RainLabRegistrationHandler::class);
        Event::subscribe(UserIpAddressHandler::class);
        //Account contact fields onto the cart row at login, registration and logout,
        //so a signed-out visitor keeps the checkout prefill and the Meta identity
        Event::subscribe(AccountCartIdentityHandler::class);
        //Login form accepts a phone number in the email field (checkout "log in" offer)
        Event::subscribe(PhoneLoginHandler::class);
        //Cart component events
        Event::subscribe(CartComponentHandler::class);
        //Meta Purchase value = margin (order total minus izpl cost), via
        //Metapixel's before_dispatch payload hook - restores the v1 rule
        Event::subscribe(MarginValueHandler::class);
        //Guest identity for Meta from the checkout fields on the cart row;
        //logged-in accounts stay with Metapixel's own AccountIdentityHandler
        Event::subscribe(GuestCheckoutIdentityHandler::class);
        //1C import must never write or delete property links again: freezes
        //the element's links against PropertiesShopaholic's beforeImport wipe
        Event::subscribe(PropertyImportGuardHandler::class);
        //Color Family values keep the stable family slug from families.json,
        //so a display-name rename never moves URLs or filter cache keys
        Event::subscribe(ColorFamilySlugHandler::class);
        //Satellite rows (prices, property links, files, seo params) clear
        //their owner's item cache - the toolbox unchanged-save guard means
        //the parent save no longer does it for them
        Event::subscribe(SatelliteCacheInvalidationHandler::class);
        Event::subscribe(EqualOldPriceHandler::class);
        Event::subscribe(SlugHistoryHandler::class);
        Event::subscribe(LegacyUrlRedirectHandler::class);
        Event::subscribe(ReviewValidationHandler::class);
        //CartPosition events
        Event::subscribe(CartPositionItemHandler::class);
        //Order position
        Event::subscribe(OrderPositionItemHandler::class);

        // Keeps raw checkout credentials out of the order property snapshot.
        Event::subscribe(OrderPropertySecretHandler::class);
        Event::subscribe(OrderUserPhoneHandler::class);
        //Offer sort by Name ASC
        Event::subscribe(ExtendOfferHandler::class);

        //Shopaholic settings fallback to primary site values on sites without own record
        Event::subscribe(SettingsSiteFallbackHandler::class);

        //Product events: extend backend fields and model
        Event::subscribe(StoreExtenderExtendProductFieldsHandler::class);
        Event::subscribe(StoreExtenderProductModelHandler::class);
        Event::subscribe(StoreExtenderExtendProductImport::class);

        //A picture the 1C import attaches or re-attaches between deploys warms
        //its own derivatives: one queued job per offer or product picture. The
        //import grows no step, and the phone hero slide is never resized inside
        //a visitor's request. In boot() and not register(), because the
        //dispatcher has to exist before a listener can attach to it.
        //The dispatch acquires a cache lock and pushes to redis, both of
        //which throw on an outage, and Eloquent fires `saved` with no catch:
        //without the boundary below a queue hiccup would abort the import row
        //or the backend save that attached the picture.
        //A re-save that wrote nothing dispatches nothing: `saved` fires before
        //syncOriginal(), so isDirty() still says what this save wrote.
        Event::listen('eloquent.saved: System\Models\File', function ($obFile) {
            if (!WarmDerivativesOnAttach::isWatched($obFile) || !$obFile->isDirty()) {
                return;
            }
            try {
                WarmImageDerivatives::dispatch((int) $obFile->id);
            } catch (Throwable $obException) {
                Log::warning(sprintf(
                    'warm-image-derivatives: dispatch for file %d failed, the picture stays cold: %s',
                    (int) $obFile->id,
                    $obException->getMessage()
                ));
            }
        });

        //Currency rounding for NOK, SEK, DKK
        ExtendCurrencyConversion::swapCurrencyHelper();
        PrimeCategoryTreeHandler::primeOnPageDisplay();

        //Phone layout branch for the product page - no-ops until a page named product2 exists
        DeviceLayoutHandler::switchLayoutOnPageDisplay();

        //Extend currency form to allow more decimal places in rate field
        ShopaholicExtensionRegistrar::extendShopaholicCurrenciesController();

        //Auto-link products to target Tax entries during import: MOVED to
        //Logingrupa.CustomXMLImportPricing (ProductTaxAutoLinkHandler) - it
        //consumes exclusively moved settings keys (00-context.md Amendment 4).

        //Redirect to order checkout page instead of homepage after payment cancel/return
        PaymentRedirectRegistrar::addPaymentGatewayRedirectListeners();
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
            'Logingrupa\Storeextender\Components\UserPhoneCheck' => 'UserPhoneCheck',
        ];
    }

    /**
     * Register scheduled tasks
     * @param \Illuminate\Console\Scheduling\Schedule $obSchedule
     */
    public function registerSchedule($obSchedule)
    {
        // Once a day before the shop opens. Colors move only after a manual
        // curation on nailolab, and that host sleeps between requests, so an
        // hourly poll mostly paid to wake it for a 304. The ColorSync settings
        // page runs the same command on demand when a change cannot wait.
        $obSchedule->command('storeextender:sync-offer-colors')->dailyAt('07:00')->timezone('Europe/Riga');

        // Carrier pickup point feeds change a few times a month: one pull before the shop day
        // keeps the checkout from fetching a 1.3 MB feed inside a customer request.
        $obSchedule->command('storeextender:refresh-pickup-points')->dailyAt('04:10');
    }

    /**
     * Register file-based mail partials.
     * `bankdetails` is read from views/mail/bankdetails.htm at runtime, allowing each
     * deployed site to render its own seller block from its own theme settings DB.
     *
     * `product`, `orderSummary` and `buttons` are the order mail body: the line item
     * rows, the totals block and the proforma links. The order templates call them and
     * no site carries a DB row for them, so October rendered "Missing partial" comments
     * where the customer's products belong.
     *
     * Note: a DB row with the same code in system_mail_partials takes precedence over
     * these files. Existing sites must delete that row once to switch over.
     *
     * @return array
     */
    public function registerMailPartials()
    {
        return [
            'bankdetails' => 'logingrupa.storeextender::mail.bankdetails',
            'product' => 'logingrupa.storeextender::mail.product',
            'orderSummary' => 'logingrupa.storeextender::mail.ordersummary',
            'buttons' => 'logingrupa.storeextender::mail.buttons',
        ];
    }

    /**
     * Points the RainLab.User password recovery mail at our own view so October can
     * resolve a localized variant next to it (views/mail/<locale>/recover_password.htm).
     * RainLab ships one English view and October only looks for locale variants beside
     * the registered view, so the shop must own the registration to own the locales.
     *
     * Registrations merge left to right, so the later plugin owns the code. RainLab.User
     * sits in $require, so this plugin always sorts after it.
     *
     * Note: a DB row with code='user:recover_password' in system_mail_templates takes
     * precedence over this file and is never localized from views.
     *
     * @return array
     */
    public function registerMailTemplates()
    {
        return [
            'user:recover_password' => 'logingrupa.storeextender::mail.recover_password',
            self::MAIL_SALON_LEAD_MANAGER => self::MAIL_SALON_LEAD_MANAGER,
            self::MAIL_SALON_LEAD_APPLICANT => self::MAIL_SALON_LEAD_APPLICANT,
            self::MAIL_MD_RESERVATION_DELETED => self::MAIL_MD_RESERVATION_DELETED,
            self::MAIL_MD_RESERVATION_REMINDER => self::MAIL_MD_RESERVATION_REMINDER,
        ];
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        return [
            'logingrupa.storeextender.color_sync' => [
                'tab' => 'logingrupa.storeextender::lang.color_sync.permission_tab',
                'label' => 'logingrupa.storeextender::lang.color_sync.permission_label',
            ],
        ];
    }

    /**
     * Share the mail logo URL and its link with every view.
     *
     * The mail header partial is a database row shared by all shops, so it cannot hold an
     * absolute host or a theme directory name. The mailer Twig environment reads shared
     * view variables, the same way the mail layout reads appName.
     */
    protected function shareMailBrandLogo()
    {
        $this->callAfterResolving('view', function ($obView) {
            $sAppURL = rtrim((string) Config::get('app.url'), '/');
            $sThemeDir = (string) Config::get('cms.active_theme');

            $obView->share('brandLogoLink', $sAppURL);
            $obView->share('brandLogoUrl', $sAppURL.'/themes/'.$sThemeDir.'/assets/images/logo.png');
        });
    }

    /**
     * Registers back-end settings pages for this plugin.
     *
     * @return array
     */
    public function registerSettings()
    {
        return [
            'color_sync' => [
                'label' => 'logingrupa.storeextender::lang.color_sync.label',
                'description' => 'logingrupa.storeextender::lang.color_sync.description',
                'category' => 'lovata.shopaholic::lang.tab.settings',
                'icon' => 'icon-paint-brush',
                'url' => Backend::url('logingrupa/storeextender/colorsync'),
                'order' => 520,
                'permissions' => ['logingrupa.storeextender.color_sync'],
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
                // Banner artwork uploaded per language ("...-lv.jpg", "-ru.jpg"):
                // serve the file that matches the locale being read
                'localized_media' => [LocalizedMediaHelper::class, 'localize'],
            ],
            'functions' => [

                // Using an inline closure
                'helloWorld' => function () {
                    return 'Hello World!';
                },
                // Script/style tags for a Vite entry built into the active theme
                'vite_entry' => [ViteAssetHelper::class, 'renderEntry'],
                // A CSS-only Vite entry (a .scss rollup input) as a stylesheet link
                'vite_style' => [ViteAssetHelper::class, 'renderStyle'],
                // Sized offer image derivatives - the ONLY way a template is
                // allowed to size an offer picture, so the sizes stay in one place
                'offer_swatch_src' => [OfferImageHelper::class, 'swatch'],
                'offer_preview_src' => [OfferImageHelper::class, 'preview'],
                'offer_hero_src' => [OfferImageHelper::class, 'hero'],
                // The same hero URL, but only when the derivative already
                // exists: a template that renders hundreds of labels may not
                // pay a resize per picture
                'offer_hero_warm_src' => [OfferImageHelper::class, 'heroIfWarm'],
                // The phone hero crop, 780x680: the three eager /p2 slides ask
                // for this one and may pay a resize for it
                'offer_hero_phone_src' => [OfferImageHelper::class, 'heroPhone'],
                // The same phone URL as a lookup only, for the label list: the
                // rest window can render 218 rows and may not pay a resize per
                // picture, so a cold shade gets an empty string
                'offer_hero_phone_warm_src' => [OfferImageHelper::class, 'heroPhoneIfWarm'],
                // Which offer a product-card fragment renders - decided in ONE
                // place, because one batched response renders many offers and
                // request state cannot answer that question for a single render
                'offer_render_context' => [OfferRenderContext::class, 'resolve'],
                // schema.org Product JSON for the product page: offers only
                // when a sellable offer exists, ratings only from real reviews
                'product_json_ld' => [ProductStructuredData::class, 'render'],
                // Cross-domain hreflang: does the other shop list this URL
                // in its sitemap; fails closed when the sitemap is unreadable
                'shop_has_url' => [SiblingShopSitemap::class, 'hasUrl'],
                // Color Family storefront queries: the ?color= offer filter
                // and the search-sheet family pill row
                'color_family_offer_filter' => [ColorFamilyHelper::class, 'filterOfferIds'],
                'color_family_list' => [ColorFamilyHelper::class, 'familyList'],
                // Catalog search grid: offers matching the query directly
                // plus every offer of a matching product
                'search_offer_filter' => [SearchOfferHelper::class, 'searchOfferIds'],
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

}
