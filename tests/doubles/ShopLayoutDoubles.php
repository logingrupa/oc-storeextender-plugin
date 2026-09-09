<?php

use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Logingrupa\StoreExtender\Classes\Helper\ActivePriceHelper;

/**
 * Layout double for the ShopLayoutBinder tests. FakeBinderLayout already exists
 * at global scope in ThemeUserBinderTest.php and always answers a session
 * component, while this binder registers three aliases, so the double here is
 * named apart and returns one component object per alias.
 */
class FakeShopLayout implements ArrayAccess
{
    public $arBag = [];
    public $arComponentList = [];
    public $iAddComponentCallCount = 0;

    /** @var array<string, object> pre-declared component instances, keyed by alias */
    public $arDeclaredList = [];

    public function __get($sName)
    {
        return $this->arDeclaredList[$sName] ?? null;
    }

    public function __isset($sName)
    {
        return isset($this->arDeclaredList[$sName]);
    }

    public function addComponent($sClass, $sAlias, $arProperties)
    {
        $this->iAddComponentCallCount++;
        $this->arComponentList[$sAlias] = $sClass;

        return new FakeShopComponent($sAlias);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($sKey, $value)
    {
        $this->arBag[$sKey] = $value;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($sKey)
    {
        return $this->arBag[$sKey] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($sKey)
    {
        return isset($this->arBag[$sKey]);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($sKey)
    {
        unset($this->arBag[$sKey]);
    }
}

/**
 * One component double per alias; user() answers the RainLab Session shape
 * without touching the auth guard, so a test that wants the guard primed has
 * to see the binder do it.
 */
class FakeShopComponent
{
    public $sAlias;

    public $obFakeUser;

    public function __construct($sAlias)
    {
        $this->sAlias = $sAlias;
        $this->obFakeUser = (object) ['email' => 'shop-binder-probe@nc.test'];
    }

    public function user()
    {
        return $this->obFakeUser;
    }
}

/**
 * The four tables ShopLayoutBinder::bind() reads on the hermetic SQLite schema
 * (CartStateReaderTest pattern), plus the ActivePriceHelper singleton reset
 * every binder test needs in tearDown.
 */
trait ShopLayoutStubTables
{
    /**
     * @return void
     */
    protected function createShopLayoutStubTables()
    {
        $this->createStubTable('lovata_orders_shopaholic_carts', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('user_id')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_orders_shopaholic_cart_positions', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('cart_id')->default(0);
            $obTable->integer('item_id')->default(0);
            $obTable->string('item_type')->default('Lovata\Shopaholic\Models\Offer');
            $obTable->integer('quantity')->default(0);
            $obTable->timestamps();
            $obTable->timestamp('deleted_at')->nullable();
        });

        $this->createStubTable('lovata_shopaholic_price_types', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(0);
            $obTable->string('name');
            $obTable->string('code')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->integer('currency_id')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_shopaholic_currency', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(0);
            $obTable->boolean('is_default')->default(0);
            $obTable->string('name');
            $obTable->string('code');
            $obTable->string('symbol');
            $obTable->decimal('rate');
            $obTable->string('external_id')->nullable();
            $obTable->integer('sort_order')->nullable();
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    /**
     * @param string   $sTableName
     * @param \Closure $fnDefineTable
     * @return void
     */
    protected function createStubTable($sTableName, $fnDefineTable)
    {
        if (Schema::hasTable($sTableName)) {
            return;
        }
        Schema::create($sTableName, $fnDefineTable);
    }

    /**
     * The singleton static survives the test, so it is emptied for the next one
     * @return void
     */
    protected function restoreActivePriceHelper()
    {
        $obProperty = new ReflectionProperty(ActivePriceHelper::class, 'instance');
        $obProperty->setAccessible(true);
        $obProperty->setValue(null, null);
    }
}
