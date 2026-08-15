<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use System\Classes\UpdateManager;
use Lovata\PropertiesShopaholic\Models\Property;
use Lovata\PropertiesShopaholic\Models\PropertyValue;
use Lovata\PropertiesShopaholic\Models\PropertyValueLink;
use Lovata\Shopaholic\Models\Product;
use Lovata\Toolbox\Classes\Helper\AbstractImportModel;

/**
 * RELEASE BLOCKER: externally-owned property links must survive the 1C XML
 * import. PropertiesShopaholic's beforeImport listener unconditionally
 * injects 'property' into the import data, and any 'property' value reaching
 * the model triggers CommonPropertyHelper::removeOldValue(), which deletes
 * every link the import did not re-process. The PropertyImportGuardHandler
 * overrides that key with the element's current links (listener ordering:
 * theirs at priority 900 runs first, the guard's default 0 merges last).
 *
 * The test replicates AbstractImportModel::fireBeforeImportEvent()'s exact
 * merge loop against the REAL listener chain both plugins registered at
 * boot, then applies the merged data through the real property mutator -
 * ordering is proven, not assumed.
 *
 * Selective migration (wishlistextender pattern): the full Lovata.Shopaholic
 * chain is SQLite-incompatible (update_table_offers_remove_price_field drops
 * indexed columns), so only Toolbox + PropertiesShopaholic migrate and the
 * products table is a stub. The guard code path is identical for Offer and
 * Product (shared neutralizePropertyImport), so the Product coverage stands
 * for both.
 */
class PropertyImportGuardTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var Product */
    protected $obProduct;

    /** @var Property */
    protected $obProperty;

    /** @var PropertyValueLink */
    protected $obLink;

    public function setUp(): void
    {
        parent::setUp();

        // PropertiesShopaholic is not in storeextender's $require, so the
        // test harness does not boot it - load it explicitly or the wipe
        // listener under test never registers
        $this->loadPlugins(['Lovata.PropertiesShopaholic']);

        $obUpdateManager = UpdateManager::instance();
        $obUpdateManager->migratePlugin('Lovata.Toolbox');
        $obUpdateManager->migratePlugin('Lovata.PropertiesShopaholic');

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

        // raw insert: Product::create would fire every booted plugin's model
        // chain (discounts, sites, ...) needing dozens of stub tables; the
        // guard only needs the ROW for getByExternalID and the property
        // mutator, which runs on attribute ASSIGNMENT, not on save
        $iProductId = \Illuminate\Support\Facades\DB::table('lovata_shopaholic_products')->insertGetId([
            'name'        => 'Guarded product',
            'slug'        => 'guarded-product',
            'external_id' => 'guarded-product-uuid',
            'active'      => true,
        ]);
        $this->obProduct = Product::find($iProductId);

        // NULL external_id: exempt from import deactivation AND invisible to
        // 1C property matching - the externally-owned property shape
        $this->obProperty = Property::create([
            'name'   => 'Color Family',
            'code'   => 'color_family',
            'slug'   => 'color-family',
            'type'   => Property::TYPE_SELECT,
            'active' => true,
        ]);

        $obValue = PropertyValue::create(['value' => 'Red']);

        $this->obLink = PropertyValueLink::create([
            'value_id'     => $obValue->id,
            'property_id'  => $this->obProperty->id,
            'product_id'   => $this->obProduct->id,
            'element_id'   => $this->obProduct->id,
            'element_type' => Product::class,
        ]);
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
     * AbstractImportModel::fireBeforeImportEvent(), replicated verbatim: fire
     * the real event, merge every listener response key-by-key.
     * @param array $arImportData
     * @return array
     */
    protected function runBeforeImportMerge(array $arImportData): array
    {
        $arEventData = Event::fire(AbstractImportModel::EVENT_BEFORE_IMPORT, [Product::class, $arImportData]);
        if (empty($arEventData)) {
            return $arImportData;
        }

        foreach ($arEventData as $arModelData) {
            if (empty($arModelData)) {
                continue;
            }
            foreach ($arModelData as $sKey => $sValue) {
                $arImportData[$sKey] = $sValue;
            }
        }

        return $arImportData;
    }

    public function testLinkSurvivesImportWithoutPropertyNodes()
    {
        $arMerged = $this->runBeforeImportMerge([
            'external_id' => 'guarded-product-uuid',
            'name'        => 'Guarded product',
        ]);

        // the guard's round-trip won the merge (not the wipe-triggering [])
        $this->assertSame(
            [$this->obProperty->id => 'Red'],
            $arMerged['property'],
            'guard listener must merge last and freeze the existing links'
        );

        // apply through the REAL mutator, exactly like the import save path
        // (assignment invokes setPropertyAttribute -> removeOldValue)
        $this->obProduct->property = $arMerged['property'];

        $this->assertNotNull(
            PropertyValueLink::find($this->obLink->id),
            'manually attached property link must survive an import without property nodes'
        );
    }

    public function testLinkSurvivesImportWithPropertyNodes()
    {
        // XML contributed a property payload of its own - it must be discarded
        $arMerged = $this->runBeforeImportMerge([
            'external_id' => 'guarded-product-uuid',
            'name'        => 'Guarded product',
            'property'    => [$this->obProperty->id => 'Blue', '999' => 'Junk'],
        ]);

        $this->assertSame([$this->obProperty->id => 'Red'], $arMerged['property']);

        $this->obProduct->property = $arMerged['property'];

        $obSurvivingLink = PropertyValueLink::find($this->obLink->id);
        $this->assertNotNull($obSurvivingLink, 'link must survive an import WITH property nodes');
        $this->assertSame('Red', $obSurvivingLink->value->value, 'the XML value must not overwrite the link');
    }

    public function testUnknownElementFreezesToEmptyProperty()
    {
        $arMerged = $this->runBeforeImportMerge([
            'external_id' => 'never-seen-before',
            'name'        => 'Brand new product',
        ]);

        // a new element has no links to protect; [] is inert while the model
        // has no id and removeOldValue has an empty link list after create
        $this->assertSame([], $arMerged['property']);
    }
}
