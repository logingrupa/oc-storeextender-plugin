<?php

// Global namespace: matches the existing storeextender test convention (directory scan,
// no PSR-4 autoload for tests). Support classes are required explicitly for the same reason.
require_once __DIR__ . '/support/PricingSettingsFixture.php';
require_once __DIR__ . '/support/PricingSchemaSeeder.php';
require_once __DIR__ . '/support/PricingPipelineHarness.php';

use Backend\Classes\AuthManager;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Model as ActiveRecord;
use October\Rain\Database\Pivot;

/**
 * Phase 0 characterization base case for the storeextender offer-price import pipeline.
 *
 * PHPUnit 12 / Pest 4 compatible (October's PluginTestCase declares setUp() public,
 * which PHPUnit 12 rejects). Boots October on SQLite :memory: with hermetic hand-built
 * Lovata tables instead of the SQLite-incompatible Lovata migration chain
 * (see storeextender/tests/integration/XmlImportPriceFlickerTest.php:44-67).
 */
abstract class PricingCharacterizationTestCase extends TestCase
{
    use \October\Tests\Concerns\InteractsWithAuthentication;
    use \October\Tests\Concerns\PerformsMigrations;
    use \October\Tests\Concerns\PerformsRegistrations;

    /** @var bool */
    protected $autoMigrate = false;

    /** @var bool */
    protected $autoRegister = false;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Force the test DB configuration AFTER kernel bootstrap.
        // Laravel's dotenv loader overwrites the PHPUnit <env force> directives during
        // bootstrap, so we re-pin SQLite :memory: here. Without this, DB reads inside the
        // pipeline hit the dev MySQL 'nc' database. (Copied from MetapixelTestCase.)
        config([
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);

        $app['db']->purge();

        $app->singleton('auth', function ($app) {
            $app['auth.loaded'] = true;

            return AuthManager::instance();
        });

        // Discovered dependency: ActivePriceHelper::init() calls
        // Lovata\Buddies\Facades\AuthHelper::getUser(); the 'auth.helper' container binding
        // lives in Buddies Plugin::register(), which never runs under unit tests
        // (PluginManager::registerFromProvider() early-returns). Bind the REAL manager -
        // with an array session and no logged-in user it returns null without touching the DB.
        $app->singleton('auth.helper', function () {
            return \Lovata\Buddies\Classes\AuthHelperManager::instance();
        });

        $this->ensureSystemSettingsTable($app);
        $this->ensureMigrationsTableForHasDatabaseProbe($app);

        return $app;
    }

    /**
     * Provision system_settings early so XmlImportSettings::set()/getValue() work.
     * Column set copied from MetapixelTestCase (site-scoped SettingModel columns).
     */
    private function ensureSystemSettingsTable($app): void
    {
        $obSchema = $app['db']->connection()->getSchemaBuilder();
        if ($obSchema->hasTable('system_settings')) {
            return;
        }

        $obSchema->create('system_settings', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('item')->nullable()->index();
            $obTable->mediumtext('value')->nullable();
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedInteger('site_root_id')->nullable();
            $obTable->unsignedInteger('site_group_id')->nullable();
        });
    }

    /**
     * Provision a stub `migrations` table and pin System::hasDatabase() truthy so
     * SettingModel::getSettingsRecord() does not short-circuit to null.
     * (Copied from MetapixelTestCase - see its docblock for the full rationale.)
     */
    private function ensureMigrationsTableForHasDatabaseProbe($app): void
    {
        $obSchema = $app['db']->connection()->getSchemaBuilder();
        if (!$obSchema->hasTable('migrations')) {
            $obSchema->create('migrations', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('migration');
                $obTable->integer('batch');
            });
        }

        // Facade::$resolvedInstance survives across tests; clear it so System::/Manifest::
        // route to THIS app's bindings.
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        $app['system.manifest']->put('database.check', true);
        $obSystem = $app->make('system.helper');
        $obReflect = new \ReflectionObject($obSystem);
        if ($obReflect->hasProperty('hasDatabaseCache')) {
            $obProp = $obReflect->getProperty('hasDatabaseCache');
            $obProp->setAccessible(true);
            $obProp->setValue($obSystem, true);
        }
    }

    protected function setUp(): void
    {
        $this->pluginTestCaseMigratedPlugins = [];
        $this->pluginTestCaseLoadedPlugins = [];

        parent::setUp();

        $this->assertRunningOnSqliteMemory();

        // parent::setUp() refreshed the app - re-pin the hasDatabase probe on the fresh app.
        $this->ensureMigrationsTableForHasDatabaseProbe($this->app);

        // Fixed mid-month clock: keeps startOfMonth/endOfMonth math in applyOfferDiscount
        // away from month-boundary flakes. Released in tearDown.
        Carbon::setTestNow('2026-08-15 12:00:00');

        Cache::flush();

        // Both settings caches are process-static and survive app refreshes; without
        // clearing them, XmlImportSettings values from a previous test leak into this one.
        // XmlImportSettings -> CommonSettings -> System\Models\SettingModel (October v4
        // model-based cache); the behavior variant is cleared too for other SettingModels.
        \System\Models\SettingModel::clearInternalCache();
        \System\Behaviors\SettingsModel::clearInternalCache();

        $this->forgetPricingSingletons();
        $this->buildHermeticPricingSchema();
        $this->subscribeDiscountCollectionExtension();

        \Mail::pretend();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        $this->forgetPricingSingletons();
        $this->flushModelEventListeners();
        $this->dropHermeticSchemas();
        parent::tearDown();
        unset($this->app);
    }

    /**
     * Tiger-style negative-space guard: abort immediately when not on SQLite :memory:.
     */
    protected function assertRunningOnSqliteMemory(): void
    {
        $sConnection = $this->app['db']->getDefaultConnection();
        $sDatabase = $this->app['config']->get("database.connections.{$sConnection}.database");
        if ($sConnection !== 'sqlite' || $sDatabase !== ':memory:') {
            $this->fail(
                "SAFETY: Tests must run on sqlite/:memory:, got {$sConnection}/{$sDatabase}. "
                . 'Check phpunit-characterization.xml force="true" env vars AND the '
                . 'post-bootstrap re-pin in createApplication().'
            );
        }
    }

    /**
     * Hermetic minimal-column Lovata tables (metapixel ShopaholicAdapterTestCase pattern).
     * The real Lovata migration chain fails on SQLite (dropColumn on indexed columns).
     */
    protected function buildHermeticPricingSchema(): void
    {
        // Read by Offer::withTrashed() lookups in recalculateMainPriceVat /
        // resolveProductIdFromExternalId and by ListByDiscountStore::getByOfferRelation.
        if (!Schema::hasTable('lovata_shopaholic_offers')) {
            Schema::create('lovata_shopaholic_offers', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('product_id')->nullable();
                $obTable->string('external_id')->nullable();
                $obTable->string('name')->nullable();
                $obTable->boolean('active')->default(1);
                $obTable->integer('discount_id')->nullable();
                $obTable->decimal('discount_value', 15, 2)->nullable();
                $obTable->string('discount_type')->nullable();
                // Discovered by smoke test: Offer uses the Sortable trait - the belongsToMany
                // 'offer' relation in ListByDiscountStore::getByOfferRelation orders by it.
                $obTable->integer('sort_order')->nullable();
                $obTable->timestamp('deleted_at')->nullable();
                $obTable->timestamps();
            });
        }

        // Read by Product::active() inside Shopaholic Product\ActiveListStore.
        if (!Schema::hasTable('lovata_shopaholic_products')) {
            Schema::create('lovata_shopaholic_products', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name')->nullable();
                $obTable->string('slug')->nullable();
                $obTable->string('external_id')->nullable();
                $obTable->boolean('active')->default(1);
                $obTable->timestamp('deleted_at')->nullable();
                $obTable->timestamps();
            });
        }

        // Read by findVatRatesForProduct (Tax::active() is SoftDelete-scoped -> deleted_at)
        // and by TaxHelper::init() via TaxCollection active/sorting stores.
        if (!Schema::hasTable('lovata_shopaholic_taxes')) {
            Schema::create('lovata_shopaholic_taxes', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name')->nullable();
                $obTable->decimal('percent', 15, 2)->default(0);
                $obTable->boolean('active')->default(1);
                $obTable->boolean('is_global')->default(0);
                $obTable->integer('sort_order')->nullable();
                $obTable->timestamp('deleted_at')->nullable();
                $obTable->timestamps();
            });
        }

        // Pivot for the whereHas('product') reverse VAT lookup.
        if (!Schema::hasTable('lovata_shopaholic_tax_product_link')) {
            Schema::create('lovata_shopaholic_tax_product_link', function ($obTable): void {
                $obTable->integer('tax_id');
                $obTable->integer('product_id');
            });
        }

        // Read by PriceTypeHelper::init() (PriceType::active() is SoftDelete-scoped).
        if (!Schema::hasTable('lovata_shopaholic_price_types')) {
            Schema::create('lovata_shopaholic_price_types', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name')->nullable();
                $obTable->string('code')->nullable();
                $obTable->boolean('active')->default(1);
                $obTable->integer('sort_order')->nullable();
                $obTable->timestamp('deleted_at')->nullable();
                $obTable->timestamps();
            });
        }

        // Column set verified against Lovata\DiscountsShopaholic\Models\Discount
        // ($fillable + $cached + Sortable).
        if (!Schema::hasTable('lovata_discounts_shopaholic_discounts')) {
            Schema::create('lovata_discounts_shopaholic_discounts', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('promo_block_id')->nullable();
                $obTable->boolean('active')->default(0);
                $obTable->string('name')->nullable();
                $obTable->string('code')->nullable();
                $obTable->string('external_id')->nullable();
                $obTable->dateTime('date_begin')->nullable();
                $obTable->dateTime('date_end')->nullable();
                $obTable->decimal('discount_value', 15, 2)->default(0);
                $obTable->string('discount_type')->nullable();
                $obTable->text('preview_text')->nullable();
                $obTable->text('description')->nullable();
                $obTable->integer('sort_order')->nullable();
                $obTable->timestamps();
            });
        }

        // DiscountItem->product = ProductCollection::make()->discount($id) merges FIVE
        // relation sources in ListByDiscountStore::getIDListFromDB - all pivots must exist.
        if (!Schema::hasTable('lovata_discounts_shopaholic_discount_product')) {
            Schema::create('lovata_discounts_shopaholic_discount_product', function ($obTable): void {
                $obTable->integer('discount_id');
                $obTable->integer('product_id');
            });
        }

        if (!Schema::hasTable('lovata_discounts_shopaholic_discount_offer')) {
            Schema::create('lovata_discounts_shopaholic_discount_offer', function ($obTable): void {
                $obTable->integer('discount_id');
                $obTable->integer('offer_id');
            });
        }

        if (!Schema::hasTable('lovata_discounts_shopaholic_discount_brand')) {
            Schema::create('lovata_discounts_shopaholic_discount_brand', function ($obTable): void {
                $obTable->integer('discount_id');
                $obTable->integer('brand_id');
            });
        }

        if (!Schema::hasTable('lovata_discounts_shopaholic_discount_category')) {
            Schema::create('lovata_discounts_shopaholic_discount_category', function ($obTable): void {
                $obTable->integer('discount_id');
                $obTable->integer('category_id');
            });
        }

        // Lovata.TagsShopaholic IS installed (PluginManager::hasPlugin true even under unit
        // tests - plugins are loaded, just not registered/booted), so getByTagRelation queries
        // these two tables unconditionally.
        if (!Schema::hasTable('lovata_discounts_shopaholic_discount_tag')) {
            Schema::create('lovata_discounts_shopaholic_discount_tag', function ($obTable): void {
                $obTable->integer('discount_id');
                $obTable->integer('tag_id');
            });
        }

        if (!Schema::hasTable('lovata_tagsshopaholic_tag_product')) {
            Schema::create('lovata_tagsshopaholic_tag_product', function ($obTable): void {
                $obTable->integer('tag_id');
                $obTable->integer('product_id');
            });
        }
    }

    /**
     * Drop every hermetic table so no schema leaks past this test.
     */
    protected function dropHermeticSchemas(): void
    {
        $arTableList = [
            'lovata_shopaholic_offers',
            'lovata_shopaholic_products',
            'lovata_shopaholic_taxes',
            'lovata_shopaholic_tax_product_link',
            'lovata_shopaholic_price_types',
            'lovata_discounts_shopaholic_discounts',
            'lovata_discounts_shopaholic_discount_product',
            'lovata_discounts_shopaholic_discount_offer',
            'lovata_discounts_shopaholic_discount_brand',
            'lovata_discounts_shopaholic_discount_category',
            'lovata_discounts_shopaholic_discount_tag',
            'lovata_tagsshopaholic_tag_product',
            'system_settings',
            'migrations',
        ];

        foreach ($arTableList as $sTable) {
            Schema::dropIfExists($sTable);
        }
    }

    /**
     * List of hermetic tables (shared with the teardown-hygiene test).
     * @return string[]
     */
    public static function getHermeticTableList(): array
    {
        return [
            'lovata_shopaholic_offers',
            'lovata_shopaholic_products',
            'lovata_shopaholic_taxes',
            'lovata_shopaholic_tax_product_link',
            'lovata_shopaholic_price_types',
            'lovata_discounts_shopaholic_discounts',
            'lovata_discounts_shopaholic_discount_product',
            'lovata_discounts_shopaholic_discount_offer',
            'lovata_discounts_shopaholic_discount_brand',
            'lovata_discounts_shopaholic_discount_category',
            'lovata_discounts_shopaholic_discount_tag',
            'lovata_tagsshopaholic_tag_product',
        ];
    }

    /**
     * Forget every Singleton the pipeline reads through, in setUp AND tearDown.
     * PriceTypeHelper::init() snapshots PriceType::active()->get() on first instance();
     * the Lovata stores memoize ID lists per instance - stale singletons would see
     * dropped tables or leak previous-test data.
     */
    protected function forgetPricingSingletons(): void
    {
        \Lovata\Toolbox\Classes\Helper\PriceHelper::forgetInstance();
        \Lovata\Shopaholic\Classes\Helper\PriceTypeHelper::forgetInstance();
        \Lovata\Shopaholic\Classes\Helper\TaxHelper::forgetInstance();
        \Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper::forgetInstance();

        \Lovata\Shopaholic\Classes\Store\ProductListStore::forgetInstance();
        \Lovata\Shopaholic\Classes\Store\Product\ActiveListStore::forgetInstance();
        \Lovata\Shopaholic\Classes\Store\TaxListStore::forgetInstance();
        \Lovata\Shopaholic\Classes\Store\Tax\ActiveListStore::forgetInstance();
        \Lovata\Shopaholic\Classes\Store\Tax\SortingListStore::forgetInstance();
        \Lovata\DiscountsShopaholic\Classes\Store\ProductListStore::forgetInstance();
        \Lovata\DiscountsShopaholic\Classes\Store\Product\ListByDiscountStore::forgetInstance();

        $this->resetToolboxItemStorage();
    }

    /**
     * ItemStorage::$arItemStore is a process-static ElementItem cache with no clear-all
     * API - reset via reflection so DiscountItem/TaxItem objects never leak across tests.
     */
    protected function resetToolboxItemStorage(): void
    {
        $obReflect = new \ReflectionClass(\Lovata\Toolbox\Classes\Item\ItemStorage::class);
        $obProp = $obReflect->getProperty('arItemStore');
        $obProp->setAccessible(true);
        $obProp->setValue(null, []);
    }

    /**
     * Discovered dependency: DiscountItem->product calls ProductCollection->discount($id),
     * a dynamic method registered by DiscountsShopaholic's ExtendProductCollectionHandler
     * in Plugin boot - which never runs under unit tests. Must be subscribed EVERY setUp:
     * October's RegisterOctober bootstrap calls Extension\Container::clearExtensions() on
     * each app refresh, wiping the registry between tests.
     */
    protected function subscribeDiscountCollectionExtension(): void
    {
        (new \Lovata\DiscountsShopaholic\Classes\Event\Product\ExtendProductCollectionHandler())->subscribe();
    }

    protected function flushModelEventListeners(): void
    {
        foreach (get_declared_classes() as $sClass) {
            if ($sClass === Pivot::class) {
                continue;
            }

            $obReflect = new \ReflectionClass($sClass);
            if (
                !$obReflect->isInstantiable() ||
                !$obReflect->isSubclassOf(ActiveRecord::class) ||
                $obReflect->isSubclassOf(Pivot::class)
            ) {
                continue;
            }

            $sClass::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    protected function guessPluginCodeFromTest()
    {
        $obReflect = new \ReflectionClass($this);
        $sPath = $obReflect->getFilename();
        $sPluginPath = $this->app->pluginsPath();

        if (strpos($sPath, $sPluginPath) === 0) {
            $sResult = ltrim(str_replace('\\', '/', substr($sPath, strlen($sPluginPath))), '/');

            return implode('.', array_slice(explode('/', $sResult), 0, 2));
        }

        return false;
    }

    protected function isAppCodeFromTest()
    {
        return false;
    }
}
