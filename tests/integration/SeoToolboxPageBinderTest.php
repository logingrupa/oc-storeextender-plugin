<?php declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Schema\Blueprint;
use Lovata\MightySeo\Components\SeoToolbox;
use Logingrupa\StoreExtender\Classes\Helper\SeoToolboxPageBinder;

/**
 * SeoToolboxPageBinder against the real MightySeo component and item chain.
 *
 * MightySeo keys page-level SEO params by the CMS page file name, so a
 * SeoToolbox running on product2 finds no row and prints an empty meta
 * description for every product without its own override. The binder
 * points the component at the params of a named page code and leaves every
 * getter to the upstream code.
 *
 * Only lovata_mighty_seo_params is created: the SeoParam model chain reads
 * nothing else for a default-locale render (proved by the RED run, see the
 * plan 05-02 summary). App boot is needed for SeoParamItem::make (Toolbox
 * cache) and for the Twig facade TemplateProcessor runs the value through.
 */
class SeoToolboxPageBinderTest extends StoreExtenderPluginTestCase
{
    /** @var bool core module schema only, see class comment */
    protected $autoMigrate = false;

    const PAGE_CODE = 'product';
    const META_DESCRIPTION = 'Nagu laka apraksts';
    const OG_TITLE = 'OG virsraksts';

    public function setUp(): void
    {
        parent::setUp();

        Schema::create('lovata_mighty_seo_params', function (Blueprint $obTable) {
            $obTable->increments('id');
            $obTable->integer('external_id')->nullable();
            $obTable->string('external_type')->nullable();
            $obTable->string('page_id')->nullable();
            $obTable->string('seo_title')->nullable();
            $obTable->text('seo_description')->nullable();
            $obTable->text('seo_keywords')->nullable();
            $obTable->string('page_h1')->nullable();
            $obTable->text('page_description')->nullable();
            $obTable->string('robots_follow')->nullable();
            $obTable->string('robots_index')->nullable();
            $obTable->string('robots_index_addition')->nullable();
            $obTable->string('canonical_url')->nullable();
            $obTable->timestamps();
            $obTable->string('og_type')->nullable();
            $obTable->string('og_title')->nullable();
            $obTable->text('og_description')->nullable();
            $obTable->string('og_image')->nullable();
        });

        DB::table('lovata_mighty_seo_params')->insert([
            'page_id'         => self::PAGE_CODE,
            'seo_description' => self::META_DESCRIPTION,
            'og_title'        => self::OG_TITLE,
        ]);
    }

    public function testABoundComponentAnswersTheNamedPagesParams()
    {
        $obComponent = SeoToolboxPageBinder::bind(new SeoToolbox(), self::PAGE_CODE, null, []);

        $this->assertSame(self::META_DESCRIPTION, $obComponent->getMetaDescription());
        $this->assertSame(self::OG_TITLE, $obComponent->getOGTitle());
        $this->assertSame(self::META_DESCRIPTION, $obComponent->getOGDescription(), 'og_description is empty, so the upstream getter falls back to the meta description');
    }

    public function testAnUnknownPageCodeAnswersNoDescription()
    {
        $obComponent = SeoToolboxPageBinder::bind(new SeoToolbox(), 'product2', null, []);

        $this->assertSame('', (string) $obComponent->get('seo_description'));
    }

    public function testBindReturnsTheSameInstanceItWasGiven()
    {
        $obComponent = new SeoToolbox();

        $this->assertSame($obComponent, SeoToolboxPageBinder::bind($obComponent, self::PAGE_CODE, null, []));
    }

    public function testAnEmptyPageCodeThrows()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('page code is empty');

        SeoToolboxPageBinder::bind(new SeoToolbox(), ' ', null, []);
    }

    public function testSeoToolboxStillDeclaresTheProtectedPageItemProperty()
    {
        $obClass = new ReflectionClass(SeoToolbox::class);

        $this->assertTrue($obClass->hasProperty('obPageSeoParamItem'), 'MightySeo renamed the page item property; the binder writes it by name');
        $this->assertTrue($obClass->getProperty('obPageSeoParamItem')->isProtected());
    }
}
