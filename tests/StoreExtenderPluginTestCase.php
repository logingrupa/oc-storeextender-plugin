<?php

/**
 * Hermetic base test case for StoreExtender integration tests.
 *
 * Stock October PluginTestCase calls Mail::pretend() at the end of setUp()
 * (modules/system/tests/PluginTestCase.php:69). Resolving the mailer fires the
 * callBeforeResolving('mail.manager') hook registered by
 * System\ServiceProvider::extendMailerService()
 * (modules/system/ServiceProvider.php:550), which calls
 * MailSetting::isConfigured() (ServiceProvider.php:551) and that runs a real
 * query against system_settings (modules/system/models/SettingModel.php:122
 * and :185-187). The only guard in front of that query is
 * System::hasDatabase() (SettingModel.php:181), and hasDatabase() trusts the
 * SHARED DISK manifest before it ever looks at the actual connection:
 * modules/system/helpers/System.php:102 returns true as soon as
 * storage/cms/manifest.php contains 'database.check' => true. Any process
 * booted against the dev MySQL database writes exactly that flag
 * (System.php:109 via ManifestCache, which reads and persists the disk file
 * whenever app.debug is off, ManifestCache.php:46 and :127-139).
 *
 * So a test class that disables autoMigrate and migrates AFTER parent::setUp()
 * crashes inside parent::setUp() whenever the ambient dev manifest exists:
 * hasDatabase() lies "yes" for a SQLite :memory: connection that has zero
 * tables, and MailSetting queries a system_settings table that is not there.
 * With the manifest absent the same tests pass, because hasDatabase() falls
 * back to Schema::hasTable('migrations') (System.php:106-107), gets false,
 * and SettingModel returns defaults without querying.
 *
 * The root fix is to make the manifest's answer irrelevant instead of fighting
 * over it: migrate the core module schema inside createApplication(), BEFORE
 * parent::setUp() reaches Mail::pretend(). Then system_settings exists no
 * matter what the manifest claims, both manifest states converge on the same
 * behavior, and nothing here ever writes to the shared disk manifest
 * (ManifestCache only persists on 'router.after', which no test dispatches).
 *
 * Two further ambient leaks are neutralized first, both createApplication()
 * concerns because they must hold before the first DB touch:
 *
 * 1. The DB config is re-pinned to SQLite :memory: after kernel bootstrap so
 *    no deferred callback can reach the dev MySQL database
 *    (CustomXMLImportPricingTestCase precedent).
 * 2. system.sites resetCache() forgets the manifest 'sites.all' key for THIS
 *    app instance only (modules/system/classes/SiteManager.php:288-293), so
 *    the three dev site definitions cannot activate multi-site behavior
 *    against the hermetic schema.
 */
abstract class StoreExtenderPluginTestCase extends PluginTestCase
{
    /**
     * Creates the application with the hermetic guards applied, then builds
     * the core module schema so the first settings read inside
     * parent::setUp() finds its tables regardless of ambient manifest state.
     * @return \Symfony\Component\HttpKernel\HttpKernelInterface
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->pinHermeticSqliteConfig($app);
        $this->migrateCoreModulesEarly($app);

        return $app;
    }

    /**
     * pinHermeticSqliteConfig re-pins SQLite :memory: after kernel bootstrap
     * and drops ambient site definitions cached from the shared disk manifest.
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return void
     */
    protected function pinHermeticSqliteConfig($app)
    {
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

        $app['system.sites']->resetCache();
    }

    /**
     * migrateCoreModulesEarly runs the module migrations before
     * PluginTestCase::setUp() resolves the mailer, so
     * MailSetting::isConfigured() can query system_settings even when the
     * ambient manifest short-circuits System::hasDatabase() to true.
     *
     * PerformsMigrations::migrateModules() resolves its managers through the
     * container that the facades already point at, but $this->app is assigned
     * here as well because refreshApplication() has not returned yet and the
     * trait's surroundings (guessPluginCodeFromTest) read it.
     * @param \Illuminate\Contracts\Foundation\Application $app
     * @return void
     */
    protected function migrateCoreModulesEarly($app)
    {
        $this->app = $app;

        $this->migrateModules();
    }
}
