<?php

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../../updates/create_table_slug_history.php';

use App;
use Db;
use Schema;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Logingrupa\StoreExtender\Classes\Event\Seo\SlugHistoryHandler;
use Logingrupa\StoreExtender\Classes\Helper\LegacyUrlResolver;
use Logingrupa\StoreExtender\Console\RegenerateSlugs;
use Logingrupa\StoreExtender\Models\SlugHistory;
use Logingrupa\StoreExtender\Updates\CreateTableSlugHistory;
use Lovata\Shopaholic\Models\Category;
use Lovata\Shopaholic\Models\Product;

/**
 * Rebuilding every catalogue slug from its source name, and refusing to when
 * the run would break a redirect.
 *
 * Two seams, as the coverage splits:
 *
 *  - Slug computation, the pinned language, the collisions, the soft-delete
 *    interaction, the no-op skip, the empty name and the whole preflight go
 *    through the command's own simulation (its --dry-run report) and through
 *    setSluggedValue, where a PRE-RUN comparison is the point. None of those
 *    need the save chain.
 *  - The SlugHistory and LegacyUrlResolver interaction fires
 *    model.beforeUpdate BY HAND, the seam LegacyUrlResolverTest:128-141
 *    already uses with its comment: Lovata's afterSave handlers want the full
 *    Shopaholic schema these hand-built tables do not provide.
 *
 * A real save() does reach Sluggable in this harness, which is what the
 * evolving-suffix and post-save-mismatch cases below exercise; the production
 * evidence for the handler chain is still the .no dry-run review and the live
 * head-tag check at rollout, not an assertion here.
 */
class RegenerateSlugsTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

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
    }

    public function testThePinnedLanguageTurnsNorwegianLettersIntoPlainOnes()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Støvbørste Håndstykke Æble', 'slug' => 'latvian-slug'],
        ]);

        [$iExit, $sOutput] = $this->runDryRun('products');

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertSame(['1' => 'stovborste-handstykke-aeble'], $this->parsePlan($sOutput), $sOutput);
    }

    /**
     * The simulation passes the language explicitly, and the write phase pins
     * App::getLocale() because that is the only hook setSluggedValue offers.
     * Both halves are asserted against an ambient locale voku maps the other
     * way, which is what makes the pin visible: inheriting would publish
     * stoevboerste for 493 URLs.
     */
    public function testThePinHoldsUnderAnAmbientLocaleThatMapsDifferently()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Støvbørste', 'slug' => 'latvian-slug'],
        ]);
        App::setLocale('no');

        try {
            [, $sDryOutput] = $this->runDryRun('products');
            $this->assertSame(['1' => 'stovborste'], $this->parsePlan($sDryOutput), 'the simulation pins the language');

            $this->assertSame('stoevboerste', $this->slugSluggableAssigns(1), 'the ambient locale WOULD decide the URL');

            App::setLocale(RegenerateSlugs::SLUG_LANGUAGE);
            $this->assertSame('stovborste', $this->slugSluggableAssigns(1), 'the locale the write phase pins');
        } finally {
            App::setLocale('en');
        }
    }

    public function testTheProbeAbortsTheRunBeforeAnyWrite()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Støvbørste', 'slug' => 'latvian-slug'],
        ]);

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugsWithADriftedProbe, ['--kind' => 'products']);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('ABORTED', $sOutput);
        $this->assertSame('latvian-slug', $this->slugOf(1), 'nothing was written');
    }

    public function testCollidingNamesGetTheLowestFreeSuffixStartingAtTwo()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gavekort', 'slug' => 'a'],
            ['id' => 2, 'name' => 'Gavekort', 'slug' => 'b'],
            ['id' => 3, 'name' => 'Gavekort', 'slug' => 'c'],
        ]);

        [, $sOutput] = $this->runDryRun('products');

        $this->assertSame([
            '1' => 'gavekort',
            '2' => 'gavekort-2',
            '3' => 'gavekort-3',
        ], $this->parsePlan($sOutput), $sOutput);
    }

    public function testASoftDeletedRowStillCountsTowardTheSuffix()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gone', 'slug' => 'gavekort', 'deleted_at' => '2026-10-01 10:00:00'],
            ['id' => 2, 'name' => 'Gavekort', 'slug' => 'b'],
        ]);

        [, $sOutput] = $this->runDryRun('products');

        $this->assertSame(['2' => 'gavekort-2'], $this->parsePlan($sOutput), 'newSluggableQuery applies withTrashed');
    }

    public function testASimulatedSlugEqualToTheCurrentOneIsNotSaved()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gavekort', 'slug' => 'gavekort', 'updated_at' => '2026-10-01 10:00:00'],
        ]);

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugs, ['--kind' => 'products']);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertStringContainsString('unchanged=1', $sOutput);
        $this->assertStringContainsString('changed=0', $sOutput);
        $this->assertSame('2026-10-01 10:00:00', $this->updatedAtOf(1), 'no save, so no new updated_at');
        $this->assertSame(0, SlugHistory::count());
    }

    /**
     * The named pair, seeded exactly. The companion assertion on B's PRE-RUN
     * setSluggedValue is the point: it pins WHY a preflight computed against
     * the unmodified database passes while the bug is live, so this test cannot
     * degrade back into one that checks nothing.
     */
    public function testAVacatedSlugTakenByAnotherRowIsRefusedBeforeAnyWrite()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Bar', 'slug' => 'foo', 'updated_at' => '2026-10-01 10:00:00'],
            ['id' => 2, 'name' => 'Foo', 'slug' => 'baz', 'updated_at' => '2026-10-01 10:00:00'],
        ]);

        $obRowB = Product::find(2);
        $obRowB->slug = '';
        $this->assertSame(
            'foo-2',
            $obRowB->setSluggedValue('slug', 'name'),
            'while #1 still holds foo, a pre-run computation comes back foo-2 and the pair is INVISIBLE'
        );

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugs, ['--kind' => 'products']);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('PREFLIGHT REFUSED', $sOutput);
        $this->assertStringContainsString('#2', $sOutput);
        $this->assertStringContainsString('#1', $sOutput);
        $this->assertNothingMoved(['1' => 'foo', '2' => 'baz']);
    }

    public function testTheSuffixedVariantIsRefusedToo()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Bar', 'slug' => 'foo-2', 'updated_at' => '2026-10-01 10:00:00'],
            ['id' => 2, 'name' => 'Foo', 'slug' => 'foo', 'updated_at' => '2026-10-01 10:00:00'],
            ['id' => 3, 'name' => 'Foo', 'slug' => 'baz', 'updated_at' => '2026-10-01 10:00:00'],
        ]);

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugs, ['--kind' => 'products']);

        $this->assertSame(Command::FAILURE, $iExit, 'the base check alone would miss this one');
        $this->assertStringContainsString('would take "foo-2"', $sOutput);
        $this->assertNothingMoved(['1' => 'foo-2', '2' => 'foo', '3' => 'baz']);
    }

    public function testASwapIsRefused()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Bar', 'slug' => 'foo', 'updated_at' => '2026-10-01 10:00:00'],
            ['id' => 2, 'name' => 'Foo', 'slug' => 'bar', 'updated_at' => '2026-10-01 10:00:00'],
        ]);

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugs, ['--kind' => 'products']);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('PREFLIGHT REFUSED', $sOutput);
        $this->assertNothingMoved(['1' => 'foo', '2' => 'bar']);
    }

    public function testATargetThatIsStillALiveRedirectIsRefused()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Bar', 'slug' => 'old-name', 'updated_at' => '2026-10-01 10:00:00'],
        ]);
        SlugHistory::record(SlugHistory::TYPE_PRODUCT, 'bar', 'something-live');

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugs, ['--kind' => 'products']);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('still redirects to "something-live"', $sOutput);
        $this->assertNothingMoved(['1' => 'old-name']);
    }

    /**
     * Sluggable is the oracle: row by row in the run's own order, each row's
     * assigned slug is persisted before the next is computed, exactly as the
     * run sequences them. That is what a pre-run computation cannot do, and it
     * is the property the simulation exists to reproduce.
     *
     * The oracle is setSluggedValue rather than a completed save() because the
     * save chain does not finish in this harness - it reaches Lovata's
     * multisite afterSave and the Shopaholic relation tables these hand-built
     * tables do not provide, the reason this file splits by seam at all.
     */
    public function testTheSimulationMatchesWhatSluggableAssignsRowByRow()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gone', 'slug' => 'gavekort', 'deleted_at' => '2026-10-01 10:00:00'],
            ['id' => 2, 'name' => 'Gavekort', 'slug' => 'b'],
            ['id' => 3, 'name' => 'Gavekort', 'slug' => 'c'],
            ['id' => 4, 'name' => 'Støvbørste', 'slug' => 'd'],
        ]);

        [, $sDryOutput] = $this->runDryRun('products');
        $arSimulated = $this->parsePlan($sDryOutput);

        $this->assertSame(['2' => 'gavekort-2', '3' => 'gavekort-3', '4' => 'stovborste'], $arSimulated);

        foreach ($arSimulated as $sRowID => $sSlug) {
            $sAssigned = $this->slugSluggableAssigns((int) $sRowID);
            Db::table('lovata_shopaholic_products')->where('id', (int) $sRowID)->update(['slug' => $sAssigned]);

            $this->assertSame($sSlug, $sAssigned, 'row ' . $sRowID . ' gets the simulated slug');
        }
    }

    /**
     * The simulation is a second implementation of a rule Sluggable owns, so
     * the run verifies it per row. The model here is a stub whose save keeps a
     * different slug: the point is the comparison, and the save chain cannot
     * complete in this harness.
     */
    public function testTheRunStopsOnTheFirstSlugSluggableAssignsDifferently()
    {
        $obCommand = new RegenerateSlugsExposingWriteRow;
        $obCommand->setLaravel($this->app);

        $obModel = new class {
            /** @var string */
            public $slug = 'a';

            public function save()
            {
                $this->slug = 'what-sluggable-actually-picked';

                return true;
            }
        };

        $bContinue = $obCommand->writeRowForTest('products', $obModel, [
            'id' => 1,
            'old' => 'a',
            'new' => 'what-the-simulation-expected',
        ]);

        $this->assertFalse($bContinue, 'the run stops rather than carrying the drift into the next row');
        $this->assertStringContainsString('products #1 got slug "what-sluggable-actually-picked"', (string) $obCommand->haltReasonForTest());
        $this->assertStringContainsString('expected "what-the-simulation-expected"', (string) $obCommand->haltReasonForTest());
    }

    public function testAHaltMakesTheWholeRunFailAndLeavesTheRestAlone()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gavekort', 'slug' => 'a'],
            ['id' => 2, 'name' => 'Aceton', 'slug' => 'b'],
        ]);

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugsThatHaltsOnTheFirstRow, ['--kind' => 'products']);

        $this->assertSame(Command::FAILURE, $iExit);
        $this->assertStringContainsString('HALTED', $sOutput);
        $this->assertSame('a', $this->slugOf(1));
        $this->assertSame('b', $this->slugOf(2), 'no later row was touched');
    }

    public function testACleanRunReportsThatThePreflightPassed()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gavekort', 'slug' => 'a'],
        ]);

        [$iExit, $sOutput] = $this->runDryRun('products');

        $this->assertSame(Command::SUCCESS, $iExit);
        $this->assertStringContainsString('PREFLIGHT PASSED', $sOutput, 'a green preflight is visible, not assumed');
    }

    public function testDryRunPrintsEveryRowAndWritesNothing()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => 'Gavekort', 'slug' => 'latvian-one', 'updated_at' => '2026-10-01 10:00:00'],
            ['id' => 2, 'name' => 'Aceton', 'slug' => 'latvian-two', 'updated_at' => '2026-10-01 10:00:00'],
        ]);

        [$iExit, $sOutput] = $this->runDryRun('products');

        $this->assertSame(Command::SUCCESS, $iExit);
        $this->assertStringContainsString('#1 CHANGE', $sOutput);
        $this->assertStringContainsString('name="Gavekort"', $sOutput);
        $this->assertStringContainsString('old="latvian-one"', $sOutput);
        $this->assertStringContainsString('new="gavekort"', $sOutput);
        $this->assertStringContainsString('#2 CHANGE', $sOutput);
        $this->assertStringContainsString('DRY RUN: nothing was written', $sOutput);
        $this->assertNothingMoved(['1' => 'latvian-one', '2' => 'latvian-two']);
    }

    public function testAnEmptyNameIsReportedAndSkipped()
    {
        $this->seedProducts([
            ['id' => 1, 'name' => '', 'slug' => 'keeps-its-slug', 'updated_at' => '2026-10-01 10:00:00'],
        ]);

        [$iExit, $sOutput] = $this->runCommand(new RegenerateSlugs, ['--kind' => 'products']);

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertStringContainsString('SKIP (the source name is empty)', $sOutput);
        $this->assertStringContainsString('skipped=1', $sOutput);
        $this->assertNothingMoved(['1' => 'keeps-its-slug']);
    }

    public function testCategoriesAreInTheRunAndNestedPathsStillResolve()
    {
        Db::table('lovata_shopaholic_categories')->insert([
            ['id' => 1, 'name' => 'Verktøy', 'slug' => 'instrumenti', 'parent_id' => null, 'active' => 1],
            ['id' => 2, 'name' => 'Filer', 'slug' => 'vilites', 'parent_id' => 1, 'active' => 1],
        ]);

        [$iExit, $sOutput] = $this->runDryRun('categories');

        $this->assertSame(Command::SUCCESS, $iExit, $sOutput);
        $this->assertSame(['1' => 'verktoy', '2' => 'filer'], $this->parsePlan($sOutput), $sOutput);

        // The handler binds model.beforeUpdate; the event is fired by hand
        // because Lovata's afterSave handlers need the full Shopaholic schema
        // (LegacyUrlResolverTest:128-141 explains it).
        (new SlugHistoryHandler)->subscribe(null);
        $this->renameCategoryThroughTheEventSeam(1, 'verktoy');
        $this->renameCategoryThroughTheEventSeam(2, 'filer');

        $obResolver = new LegacyUrlResolver;
        $this->assertSame(['verktoy'], $obResolver->resolveCategoryPath(['instrumenti']));
        $this->assertSame(
            ['verktoy', 'filer'],
            $obResolver->resolveCategoryPath(['instrumenti', 'vilites']),
            'a parent plus child rename still resolves the old nested path'
        );
    }

    /**
     * @param array<int,array<string,mixed>> $arRowList
     */
    protected function seedProducts(array $arRowList): void
    {
        foreach ($arRowList as $arRow) {
            Db::table('lovata_shopaholic_products')->insert($arRow + [
                'active' => 1,
                'deleted_at' => null,
                'updated_at' => '2026-10-01 10:00:00',
                'created_at' => '2026-10-01 10:00:00',
            ]);
        }
    }

    protected function renameCategoryThroughTheEventSeam(int $iCategoryID, string $sNewSlug): void
    {
        $obCategory = Category::find($iCategoryID);
        $obCategory->slug = $sNewSlug;
        $obCategory->fireEvent('model.beforeUpdate');
        Db::table('lovata_shopaholic_categories')->where('id', $iCategoryID)->update(['slug' => $sNewSlug]);
    }

    /**
     * @param array<string,string> $arSlugById
     */
    protected function assertNothingMoved(array $arSlugById): void
    {
        foreach ($arSlugById as $sRowID => $sSlug) {
            $this->assertSame($sSlug, $this->slugOf((int) $sRowID), 'row ' . $sRowID . ' slug');
            $this->assertSame('2026-10-01 10:00:00', $this->updatedAtOf((int) $sRowID), 'row ' . $sRowID . ' updated_at');
        }

        $this->assertSame(0, SlugHistory::where('new_slug', '<>', 'something-live')->count(), 'no new history row');
    }

    /**
     * What Sluggable itself assigns to a row right now, against the slugs
     * currently in the table. Nothing is saved.
     */
    protected function slugSluggableAssigns(int $iProductID): string
    {
        $obProduct = Product::find($iProductID);
        $obProduct->slug = '';

        return (string) $obProduct->setSluggedValue('slug', 'name');
    }

    protected function slugOf(int $iProductID): string
    {
        return (string) Db::table('lovata_shopaholic_products')->where('id', $iProductID)->value('slug');
    }

    protected function updatedAtOf(int $iProductID): string
    {
        return (string) Db::table('lovata_shopaholic_products')->where('id', $iProductID)->value('updated_at');
    }

    /**
     * id => simulated new slug, for the rows the report marks as changing.
     *
     * @return array<string,string>
     */
    protected function parsePlan(string $sOutput): array
    {
        preg_match_all('/#(\d+) CHANGE\s+name="[^"]*" old="[^"]*" new="([^"]*)"/', $sOutput, $arMatches, PREG_SET_ORDER);

        $arPlan = [];
        foreach ($arMatches as $arMatch) {
            $arPlan[$arMatch[1]] = $arMatch[2];
        }

        return $arPlan;
    }

    /**
     * @return array{0:int,1:string}
     */
    protected function runDryRun(string $sKind): array
    {
        return $this->runCommand(new RegenerateSlugs, ['--kind' => $sKind, '--dry-run' => true]);
    }

    /**
     * @return array{0:int,1:string}
     */
    protected function runCommand(Command $obCommand, array $arInput = []): array
    {
        $obCommand->setLaravel($this->app);
        $obOutput = new BufferedOutput;
        $iExit = $obCommand->run(new ArrayInput($arInput), $obOutput);

        return [$iExit, $obOutput->fetch()];
    }
}

/** The probe's expectation pinned to an answer the language does not give. */
class RegenerateSlugsWithADriftedProbe extends RegenerateSlugs
{
    const PROBE_EXPECTED = 'stoevboerste-haandstykke-aeble-blaa-roed';
}

/** Reaches the per-row verification without the write walk around it. */
class RegenerateSlugsExposingWriteRow extends RegenerateSlugs
{
    public function writeRowForTest(string $sKind, $obModel, array $arRow): bool
    {
        return $this->writeRow($sKind, $obModel, $arRow);
    }

    public function haltReasonForTest(): ?string
    {
        return $this->sHaltReason;
    }
}

/** A halt on row one, to prove it fails the run and spares the rest. */
class RegenerateSlugsThatHaltsOnTheFirstRow extends RegenerateSlugs
{
    protected function writeRow(string $sKind, $obModel, array $arRow): bool
    {
        $this->sHaltReason = $sKind . ' #' . $arRow['id'] . ' drifted from the simulation';

        return false;
    }
}
