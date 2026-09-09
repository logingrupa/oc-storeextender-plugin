<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../../updates/create_table_slug_history.php';

use Db;
use Schema;
use Lovata\Shopaholic\Models\Category;
use Logingrupa\StoreExtender\Models\SlugHistory;
use Logingrupa\StoreExtender\Classes\Event\Seo\SlugHistoryHandler;
use Logingrupa\StoreExtender\Updates\CreateTableSlugHistory;
use Logingrupa\StoreExtender\Classes\Helper\LegacyUrlResolver;

/**
 * A dead catalogue URL resolves to the live page it stands for, and only
 * then: a switched-off category to its nearest active ancestor, a moved or
 * renamed one to its current path, a renamed product to its new slug. A
 * path that is live already, or that nothing remembers, resolves to nothing
 * so the page keeps its 404.
 *
 * Shopaholic's schema does not build on SQLite (see ProductOpenOnAjaxTest),
 * so the two tables the resolver reads are created here with the columns it
 * and the Category model touch.
 */
class LegacyUrlResolverTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    /** @var LegacyUrlResolver */
    protected $obResolver;

    public function setUp(): void
    {
        parent::setUp();

        Schema::create('lovata_shopaholic_categories', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->integer('parent_id')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('nest_left')->nullable();
            $obTable->integer('nest_right')->nullable();
            $obTable->integer('nest_depth')->nullable();
            $obTable->timestamps();
        });
        Schema::create('lovata_shopaholic_products', function ($obTable) {
            $obTable->increments('id');
            $obTable->string('name');
            $obTable->string('slug');
            $obTable->boolean('active')->default(true);
            $obTable->timestamp('deleted_at')->nullable();
            $obTable->timestamps();
        });
        (new CreateTableSlugHistory)->up();

        // root(1) > tools(2) > files(3, off) ; root(1) > gels(4) ; retired(5, off, root)
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 1, 'name' => 'Root', 'slug' => 'root', 'parent_id' => null, 'active' => 1],
            ['id' => 2, 'name' => 'Tools', 'slug' => 'tools', 'parent_id' => 1, 'active' => 1],
            ['id' => 3, 'name' => 'Files', 'slug' => 'files', 'parent_id' => 2, 'active' => 0],
            ['id' => 4, 'name' => 'Gels', 'slug' => 'gels', 'parent_id' => 1, 'active' => 1],
            ['id' => 5, 'name' => 'Retired', 'slug' => 'retired', 'parent_id' => null, 'active' => 0],
        ]);
        Db::table('lovata_shopaholic_products')->insert([
            ['id' => 1, 'name' => 'Polygel', 'slug' => 'polygel-uvled', 'active' => 1],
            ['id' => 2, 'name' => 'Old gel', 'slug' => 'old-gel', 'active' => 0],
        ]);

        (new SlugHistoryHandler)->subscribe(null);
        $this->obResolver = new LegacyUrlResolver;
    }

    public function testInactiveCategoryGoesToItsNearestActiveAncestor()
    {
        $this->assertSame(['root', 'tools'], $this->obResolver->resolveCategoryPath(['root', 'tools', 'files']));
    }

    public function testMovedCategoryGoesToItsCurrentPath()
    {
        $this->assertSame(['root', 'gels'], $this->obResolver->resolveCategoryPath(['root', 'old-parent', 'gels']));
    }

    public function testUnknownLeafGoesToTheDeepestKnownSegment()
    {
        $this->assertSame(['root', 'tools'], $this->obResolver->resolveCategoryPath(['root', 'tools', 'never-existed']));
    }

    public function testRenamedCategorySlugIsFollowedThroughTheHistory()
    {
        SlugHistory::record(SlugHistory::TYPE_CATEGORY, 'gel-systems', 'gels');

        $this->assertSame(['root', 'gels'], $this->obResolver->resolveCategoryPath(['root', 'gel-systems']));
    }

    public function testLivePathAndUnknownPathResolveToNothing()
    {
        $this->assertNull($this->obResolver->resolveCategoryPath(['root', 'tools']));
        $this->assertNull($this->obResolver->resolveCategoryPath(['nope', 'nothing']));
        $this->assertNull($this->obResolver->resolveCategoryPath(['retired']));
    }

    public function testRenamedProductGoesToItsLiveSlugOnly()
    {
        SlugHistory::record(SlugHistory::TYPE_PRODUCT, 'polygel-uv-led', 'polygel-uvled');
        SlugHistory::record(SlugHistory::TYPE_PRODUCT, 'gel-old', 'old-gel');

        $this->assertSame('polygel-uvled', $this->obResolver->resolveProductSlug('polygel-uv-led'));
        $this->assertNull($this->obResolver->resolveProductSlug('gel-old'), 'target is inactive');
        $this->assertNull($this->obResolver->resolveProductSlug('never-existed'));
    }

    public function testHistoryCollapsesChainsAndForgetsASlugBackInUse()
    {
        SlugHistory::record(SlugHistory::TYPE_PRODUCT, 'a', 'b');
        SlugHistory::record(SlugHistory::TYPE_PRODUCT, 'b', 'c');

        $this->assertSame('c', SlugHistory::findTarget(SlugHistory::TYPE_PRODUCT, 'a'), 'a -> c, one hop');
        $this->assertSame('c', SlugHistory::findTarget(SlugHistory::TYPE_PRODUCT, 'b'));

        SlugHistory::record(SlugHistory::TYPE_PRODUCT, 'c', 'a');

        $this->assertNull(SlugHistory::findTarget(SlugHistory::TYPE_PRODUCT, 'a'), 'a is live again');
        $this->assertSame('a', SlugHistory::findTarget(SlugHistory::TYPE_PRODUCT, 'c'));
        $this->assertSame('a', SlugHistory::findTarget(SlugHistory::TYPE_PRODUCT, 'b'));
    }

    /**
     * The handler binds model.beforeUpdate; the event is fired by hand because
     * Lovata's afterSave handlers need the full Shopaholic schema.
     */
    public function testCategoryRenameThroughTheModelIsRecorded()
    {
        $obCategory = Category::find(4);
        $obCategory->slug = 'gels-renamed';
        $obCategory->fireEvent('model.beforeUpdate');
        Db::table('lovata_shopaholic_categories')->where('id', 4)->update(['slug' => 'gels-renamed']);

        $this->assertSame('gels-renamed', SlugHistory::findTarget(SlugHistory::TYPE_CATEGORY, 'gels'));
        $this->assertSame(['root', 'gels-renamed'], $this->obResolver->resolveCategoryPath(['root', 'gels']));
    }
}
