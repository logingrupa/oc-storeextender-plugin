<?php

require_once __DIR__.'/StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Schema;
use System\Classes\UpdateManager;

use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Base test case for tests that need a real user table.
 *
 * The active user plugin is resolved through the Toolbox seam, loaded (it is not in
 * StoreExtender's $require, so loadCurrentPlugin() alone never boots it) and migrated,
 * then this plugin's own user migrations run so the Buddies-shaped columns exist
 * (users.phone/phone_short/property, user_groups.price_type_id, the user_properties
 * table). The full StoreExtender migration chain is not run: most of it targets
 * lovata_shopaholic_* tables that do not exist on the hermetic SQLite schema.
 *
 * Tests that only make sense for one user plugin call skipUnlessUserPlugin().
 */
abstract class StoreExtenderUserPluginTestCase extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var string */
    protected $sUserPluginName;

    public function setUp(): void
    {
        parent::setUp();

        UserHelper::forgetInstance();
        $this->sUserPluginName = (string) UserHelper::instance()->getPluginName();
        if ($this->sUserPluginName === '') {
            $this->fail('Neither Lovata.Buddies nor RainLab.User is enabled');
        }

        $this->loadPlugins([$this->sUserPluginName]);

        UpdateManager::instance()->migratePlugin('Lovata.Toolbox');
        UpdateManager::instance()->migratePlugin($this->sUserPluginName);

        $this->runOwnUserMigrations();

        // Auth singleton survives between tests inside one process, force guest state
        $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
        $sAuthFacadeClass::logout();
    }

    public function tearDown(): void
    {
        UserHelper::forgetInstance();

        parent::tearDown();
    }

    /**
     * Run this plugin's three user migrations directly - they are the real classes,
     * each guarded on hasTable/hasColumn, so running them here also proves they
     * apply cleanly on top of a fresh user plugin schema.
     * @return void
     */
    protected function runOwnUserMigrations()
    {
        $sUpdatesPath = __DIR__.'/../updates/';

        require_once $sUpdatesPath.'update_table_users_add_buddies_columns.php';
        require_once $sUpdatesPath.'update_table_user_groups_add_price_type_id.php';
        require_once $sUpdatesPath.'create_table_user_properties.php';

        (new \Logingrupa\StoreExtender\Updates\UpdateTableUsersAddBuddiesColumns())->up();
        (new \Logingrupa\StoreExtender\Updates\UpdateTableUserGroupsAddPriceTypeId())->up();
        (new \Logingrupa\StoreExtender\Updates\CreateTableUserProperties())->up();
    }

    /**
     * Hermetic cart tables for the listeners on rainlab.user.login/register/logout
     * and the cart identity readers; the Shopaholic migration chain is not run here.
     * @return void
     */
    protected function createCartTables()
    {
        Schema::create('lovata_orders_shopaholic_carts', function ($obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->string('email')->nullable();
            $obTable->text('user_data')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_orders_shopaholic_cart_positions', function ($obTable) {
            $obTable->increments('id');
            $obTable->integer('cart_id')->default(0);
            $obTable->integer('item_id')->default(0);
            $obTable->string('item_type')->default('Lovata\Shopaholic\Models\Offer');
            $obTable->integer('quantity')->default(0);
            $obTable->timestamps();
            $obTable->timestamp('deleted_at')->nullable();
        });
    }

    /**
     * @return void
     */
    protected function dropCartTables()
    {
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        Schema::dropIfExists('lovata_orders_shopaholic_carts');
        Schema::dropIfExists('lovata_orders_shopaholic_cart_positions');
    }

    /**
     * @param string $sPluginName
     * @return void
     */
    protected function skipUnlessUserPlugin($sPluginName)
    {
        if ($this->sUserPluginName === $sPluginName) {
            return;
        }

        $this->markTestSkipped(
            'Active user plugin is '.$this->sUserPluginName.'; this test pins the '.$sPluginName.' branch.'
        );
    }
}
