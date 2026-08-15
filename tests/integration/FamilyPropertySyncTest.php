<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use System\Classes\UpdateManager;
use Lovata\PropertiesShopaholic\Models\Property;
use Lovata\PropertiesShopaholic\Models\PropertyValue;
use Lovata\PropertiesShopaholic\Models\PropertyValueLink;
use Lovata\Shopaholic\Models\Offer;
use Logingrupa\StoreExtender\Classes\Color\FamilyPropertySync;
use Logingrupa\StoreExtender\Classes\Event\Property\ColorFamilySlugHandler;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;
use Logingrupa\StoreExtender\Models\OfferColor;

/**
 * FamilyPropertySync against the REAL PropertiesShopaholic model chain: the
 * property upsert, the slug handler pinning value slugs to the stable family
 * slugs (via the live shopaholic.property.generate_value listener the plugin
 * registered at boot), model-based link create/update/delete, and the
 * fail-safe boundaries.
 *
 * Selective migration (PropertyImportGuardTest pattern): the full
 * Lovata.Shopaholic chain is SQLite-incompatible, so only Toolbox +
 * PropertiesShopaholic migrate; Shopaholic tables are stubs. The two
 * storeextender tables come from their real migration classes
 * (SyncOfferColorsTest pattern) - the full storeextender chain alters
 * Shopaholic tables the stubs do not carry.
 */
class FamilyPropertySyncTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var int */
    protected $iProductId;

    /** @var int */
    protected $iOfferOneId;

    /** @var int */
    protected $iOfferTwoId;

    public function setUp(): void
    {
        parent::setUp();

        $this->loadPlugins(['Lovata.PropertiesShopaholic']);

        $obUpdateManager = UpdateManager::instance();
        $obUpdateManager->migratePlugin('Lovata.Toolbox');
        $obUpdateManager->migratePlugin('Lovata.PropertiesShopaholic');

        require_once __DIR__.'/../../updates/create_table_offer_colors.php';
        require_once __DIR__.'/../../updates/create_table_color_family_meta.php';
        (new \Logingrupa\StoreExtender\Updates\CreateTableOfferColors())->up();
        (new \Logingrupa\StoreExtender\Updates\CreateTableColorFamilyMeta())->up();

        $this->createStubTable('lovata_shopaholic_products', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('external_id')->nullable();
            $obTable->text('preview_text')->nullable();
            $obTable->text('description')->nullable();
            $obTable->integer('category_id')->nullable();
            $obTable->integer('brand_id')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_shopaholic_categories', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->integer('parent_id')->nullable();
            $obTable->integer('nest_left')->nullable();
            $obTable->integer('nest_right')->nullable();
            $obTable->integer('nest_depth')->nullable();
            $obTable->timestamps();
        });

        $this->createStubTable('lovata_shopaholic_additional_categories', function (Blueprint $obTable) {
            $obTable->integer('product_id')->nullable();
            $obTable->integer('category_id')->nullable();
        });

        $this->createStubTable('lovata_shopaholic_product_site_relation', function (Blueprint $obTable) {
            $obTable->integer('product_id')->nullable();
            $obTable->integer('site_id')->nullable();
        });

        $this->createStubTable('lovata_shopaholic_offers', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('product_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->string('name')->nullable();
            $obTable->string('external_id')->nullable();
            // the Offer model's Sortable global scope orders every query by it
            $obTable->integer('sort_order')->nullable();
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });

        // raw inserts: model create() would fire every booted plugin's model
        // chain needing dozens of stub tables; the sync only reads these rows
        $this->iProductId = DB::table('lovata_shopaholic_products')->insertGetId([
            'name'   => 'Gel polish',
            'slug'   => 'gel-polish',
            'active' => true,
        ]);
        $this->iOfferOneId = DB::table('lovata_shopaholic_offers')->insertGetId([
            'product_id'  => $this->iProductId,
            'name'        => 'Shade 01',
            'external_id' => 'uuid-offer-1',
            'active'      => true,
        ]);
        $this->iOfferTwoId = DB::table('lovata_shopaholic_offers')->insertGetId([
            'product_id'  => $this->iProductId,
            'name'        => 'Shade 02',
            'external_id' => 'uuid-offer-2',
            'active'      => true,
        ]);

        DB::table('logingrupa_storeextender_offer_colors')->insert([
            ['offer_uuid' => 'uuid-offer-1', 'family' => 'Red', 'hex' => '#d32f2f', 'hue' => 2, 'lightness' => 0.45],
            ['offer_uuid' => 'uuid-offer-2', 'family' => 'Blue', 'hex' => '#1976d2', 'hue' => 210, 'lightness' => 0.5],
        ]);

        // the name map memo is a static: a previous test in this process may
        // have memoized it against ITS database
        ColorFamilySlugHandler::resetMemo();
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
     * @return array the two-family taxonomy fixture, shaped like getFamilyMap()
     */
    protected function getFamilyMap(): array
    {
        return [
            'red' => [
                'name'     => 'Red',
                'names'    => ['en' => 'Red', 'lv' => 'Sarkans', 'ru' => 'Красный'],
                'synonyms' => ['en' => [], 'lv' => ['sarkana', 'sarkanā'], 'ru' => ['красная']],
                'hex'      => '#d32f2f',
            ],
            'blue' => [
                'name'     => 'Blue',
                'names'    => ['en' => 'Blue', 'lv' => 'Zils', 'ru' => 'Синий'],
                'synonyms' => ['en' => [], 'lv' => ['zila'], 'ru' => ['синяя']],
                'hex'      => '#1976d2',
            ],
        ];
    }

    /**
     * @return array offer map shaped exactly like the console command builds it
     */
    protected function getOfferColorMapFromTable(): array
    {
        $arOfferColorMap = [];
        foreach (OfferColor::query()->get() as $obOfferColor) {
            $arOfferColorMap[$obOfferColor->offer_uuid] = ['family' => $obOfferColor->family];
        }

        return $arOfferColorMap;
    }

    /**
     * @param int $iOfferId
     * @return PropertyValueLink|null
     */
    protected function findOfferLink($iOfferId)
    {
        return PropertyValueLink::query()
            ->where('element_type', Offer::class)
            ->where('element_id', $iOfferId)
            ->first();
    }

    public function testFirstSyncCreatesPropertyValuesLinksAndMeta()
    {
        $arStats = (new FamilyPropertySync())->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());

        $this->assertSame(2, $arStats['meta']);
        $this->assertSame(2, $arStats['values']);
        $this->assertSame(2, $arStats['links_created']);
        $this->assertSame(0, $arStats['links_updated']);
        $this->assertSame(0, $arStats['links_deleted']);

        $obProperty = Property::query()->where('code', 'color_family')->first();
        $this->assertNotNull($obProperty, 'the color_family property must exist');
        $this->assertNull($obProperty->external_id, 'external_id must stay NULL (invisible to 1C matching)');
        $this->assertSame(Property::TYPE_SELECT, $obProperty->type);

        // the slug handler pinned the STABLE family slugs, not str_slug of the LV names
        $obRedValue = PropertyValue::getBySlug('red')->first();
        $obBlueValue = PropertyValue::getBySlug('blue')->first();
        $this->assertNotNull($obRedValue, 'value slug must be the stable family slug "red"');
        $this->assertNotNull($obBlueValue, 'value slug must be the stable family slug "blue"');
        $this->assertSame('Sarkans', $obRedValue->value, 'base value must be the LV display name');
        $this->assertSame('Zils', $obBlueValue->value, 'base value must be the LV display name');

        $obLinkOne = $this->findOfferLink($this->iOfferOneId);
        $obLinkTwo = $this->findOfferLink($this->iOfferTwoId);
        $this->assertNotNull($obLinkOne);
        $this->assertNotNull($obLinkTwo);
        $this->assertEquals($obProperty->id, $obLinkOne->property_id);
        $this->assertEquals($this->iProductId, $obLinkOne->product_id);
        $this->assertEquals($obRedValue->id, $obLinkOne->value_id);
        $this->assertEquals($this->iProductId, $obLinkTwo->product_id);
        $this->assertEquals($obBlueValue->id, $obLinkTwo->value_id);

        $obRedMeta = ColorFamilyMeta::query()->where('value_slug', 'red')->first();
        $this->assertNotNull($obRedMeta);
        $this->assertSame('Red', $obRedMeta->family);
        $this->assertSame('#d32f2f', $obRedMeta->hex);
        $this->assertSame('Sarkans', $obRedMeta->names['lv']);
        $this->assertContains('sarkana', $obRedMeta->synonyms['lv']);
        $this->assertSame(2, ColorFamilyMeta::query()->count());
    }

    public function testSecondIdenticalRunChangesNothing()
    {
        $obSync = new FamilyPropertySync();
        $obSync->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());

        $iLinkOneId = $this->findOfferLink($this->iOfferOneId)->id;
        $iLinkTwoId = $this->findOfferLink($this->iOfferTwoId)->id;
        $iValueCount = PropertyValue::query()->count();

        $arStats = $obSync->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());

        $this->assertSame(0, $arStats['links_created']);
        $this->assertSame(0, $arStats['links_updated']);
        $this->assertSame(0, $arStats['links_deleted']);
        $this->assertSame($iLinkOneId, $this->findOfferLink($this->iOfferOneId)->id, 'link ids must be stable');
        $this->assertSame($iLinkTwoId, $this->findOfferLink($this->iOfferTwoId)->id, 'link ids must be stable');
        $this->assertSame($iValueCount, PropertyValue::query()->count(), 'no duplicate values on re-run');
        $this->assertSame(1, Property::query()->where('code', 'color_family')->count());
    }

    public function testRemappedOfferUpdatesValueIdInPlace()
    {
        $obSync = new FamilyPropertySync();
        $obSync->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());
        $iLinkTwoId = $this->findOfferLink($this->iOfferTwoId)->id;

        // upstream regrouped offer 2 from Blue to Red
        $arOfferColorMap = $this->getOfferColorMapFromTable();
        $arOfferColorMap['uuid-offer-2'] = ['family' => 'Red'];

        $arStats = $obSync->sync($this->getFamilyMap(), $arOfferColorMap);

        $this->assertSame(1, $arStats['links_updated']);
        $this->assertSame(0, $arStats['links_created']);
        $this->assertSame(0, $arStats['links_deleted']);

        $obLinkTwo = $this->findOfferLink($this->iOfferTwoId);
        $this->assertSame($iLinkTwoId, $obLinkTwo->id, 'value_id changes in place, the link row survives');
        $this->assertSame(PropertyValue::getBySlug('red')->first()->id, $obLinkTwo->value_id);
    }

    public function testOfferRemovedFromMapLosesItsLink()
    {
        $obSync = new FamilyPropertySync();
        $obSync->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());

        $arOfferColorMap = $this->getOfferColorMapFromTable();
        unset($arOfferColorMap['uuid-offer-2']);

        $arStats = $obSync->sync($this->getFamilyMap(), $arOfferColorMap);

        $this->assertSame(1, $arStats['links_deleted']);
        $this->assertNull($this->findOfferLink($this->iOfferTwoId), 'unmapped offer must lose its link');
        $this->assertNotNull($this->findOfferLink($this->iOfferOneId), 'the mapped offer keeps its link');
    }

    public function testEmptyFamilyMapDeletesNothing()
    {
        $obSync = new FamilyPropertySync();
        $obSync->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());

        $arStats = $obSync->sync([], []);

        $this->assertSame(
            ['values' => 0, 'links_created' => 0, 'links_updated' => 0, 'links_deleted' => 0, 'meta' => 0],
            $arStats
        );
        $this->assertSame(2, ColorFamilyMeta::query()->count(), 'empty payload must never wipe meta');
        $this->assertNotNull($this->findOfferLink($this->iOfferOneId), 'empty payload must never delete links');
        $this->assertNotNull($this->findOfferLink($this->iOfferTwoId), 'empty payload must never delete links');
    }

    public function testUnknownFamilyKeepsTheExistingLink()
    {
        $obSync = new FamilyPropertySync();
        $obSync->sync($this->getFamilyMap(), $this->getOfferColorMapFromTable());
        $iLinkTwoId = $this->findOfferLink($this->iOfferTwoId)->id;

        // a partial taxonomy payload no longer knows Blue: offer 2 stays mapped
        // in the offer table but its family resolves to nothing
        $arFamilyMap = $this->getFamilyMap();
        unset($arFamilyMap['blue']);

        $arStats = $obSync->sync($arFamilyMap, $this->getOfferColorMapFromTable());

        $this->assertSame(0, $arStats['links_deleted']);
        $this->assertSame($iLinkTwoId, $this->findOfferLink($this->iOfferTwoId)->id, 'an unknown family must not delete data');
    }
}
