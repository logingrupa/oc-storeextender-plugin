<?php namespace Logingrupa\StoreExtender\Console;

use App;
use Db;
use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Models\SlugHistory;
use Lovata\Shopaholic\Models\Category;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Support\Str;

/**
 * Rebuild every product and category slug from its current source name.
 *
 * Why it exists: .no's catalogue text is Norwegian but its URLs were slugged
 * from Latvian names, and the 1C import only ever FILLS an empty slug, never
 * rewrites one. Emptying the slug and saving is the documented way to make
 * Sluggable regenerate it, and the Eloquent save is what fires
 * model.beforeUpdate so SlugHistoryHandler records the 301 for every old URL.
 *
 * Never scheduled and never called from a deploy: this rewrites the public
 * address of every catalogue page, so it is a deliberate, dry-run-first,
 * by-hand step.
 *
 * It needs no cache-busting step. The published id map's cache key carries
 * ShopIdMap::catalogueStamp(), so the first saved slug makes the old body
 * unreachable - which also covers an interrupted run.
 *
 *   php artisan storeextender:regenerate-slugs --kind=categories --dry-run
 *   php artisan storeextender:regenerate-slugs --kind=products
 */
class RegenerateSlugs extends Command
{
    /**
     * The slug language is PINNED, not inherited from App::getLocale().
     * Current constraint: voku's 'en' map is empty, so the generic
     * transliteration runs and gives o, a and ae - the plain letters the owner
     * ruled for, and the two slugs the 2026-11-13 .no import already created.
     * An empty map also cannot later be widened into one that rewrites the
     * public URLs; 'no' would give oe, aa and ae instead.
     */
    const SLUG_LANGUAGE = 'en';

    /** The probe that aborts the run if that answer ever changes */
    const PROBE_SOURCE = 'Støvbørste Håndstykke Æble Blå/Rød';
    const PROBE_EXPECTED = 'stovborste-handstykke-aeble-bla-rod';

    /** Sluggable truncates its SOURCE here before slugging */
    const SOURCE_MAX_LENGTH = 175;

    /** Rows hydrated per write batch, so a 493-row run never holds them all */
    const CHUNK_SIZE = 200;

    const KIND_PRODUCTS = 'products';
    const KIND_CATEGORIES = 'categories';

    /** @var string */
    protected $signature = 'storeextender:regenerate-slugs
        {--dry-run : Compute and print every row, write nothing}
        {--kind=all : all, products or categories}
        {--limit=0 : Stop after this many rows per kind (0 = every row)}';

    /** @var string */
    protected $description = 'Rebuild product and category slugs from their source names';

    /** @var array<string,int> */
    protected $arTotalList = ['changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

    /** @var string|null the row that stopped a real run */
    protected $sHaltReason = null;

    public function handle(): int
    {
        if (!$this->probeSlugLanguage()) {
            return self::FAILURE;
        }

        $arKindList = $this->kindList();
        if ($arKindList === []) {
            $this->error('--kind must be all, products or categories');

            return self::FAILURE;
        }

        // Simulate EVERY kind before writing anything: a refused run must not
        // have half-written the other table.
        $arPlanList = [];
        $arConflictList = [];
        foreach ($arKindList as $sKind) {
            $arPlanList[$sKind] = $this->buildPlan($sKind);
            $arConflictList = array_merge($arConflictList, $this->conflictList($sKind, $arPlanList[$sKind]));
        }

        if ($arConflictList !== []) {
            $this->error('PREFLIGHT REFUSED the run, ' . count($arConflictList) . ' conflict(s), nothing was written:');
            foreach ($arConflictList as $sConflict) {
                $this->error('  ' . $sConflict);
            }
            $this->error('A vacated slug taken by another row would delete the first row 301 and serve the wrong product. Review these rows by hand.');

            return self::FAILURE;
        }

        $this->info('PREFLIGHT PASSED: no simulated slug collides with one this run retires, and none is a live redirect source.');

        foreach ($arKindList as $sKind) {
            $this->reportPlan($sKind, $arPlanList[$sKind]);
        }

        if ($this->option('dry-run')) {
            $this->info('DRY RUN: nothing was written. The real run re-derives each slug through Sluggable and halts on any mismatch.');

            return self::SUCCESS;
        }

        return $this->writeKindList($arKindList, $arPlanList);
    }

    /**
     * The pinned language must still produce the plain-letter answer the owner
     * ruled for, checked before any row is touched.
     */
    protected function probeSlugLanguage(): bool
    {
        // static:: so the probe itself stays exercisable against a pinned
        // expectation that does not match
        $sProbe = Str::slug(static::PROBE_SOURCE, '-', static::SLUG_LANGUAGE);
        if ($sProbe === static::PROBE_EXPECTED) {
            return true;
        }

        $this->error('ABORTED: slug language "' . static::SLUG_LANGUAGE . '" now gives "' . $sProbe . '" instead of "' . static::PROBE_EXPECTED . '".');
        $this->error('The transliteration map changed under us, so this run would rewrite every public URL differently than reviewed.');

        return false;
    }

    /**
     * @return string[]
     */
    protected function kindList(): array
    {
        $sKind = (string) $this->option('kind');

        if ($sKind === 'all') {
            return [self::KIND_CATEGORIES, self::KIND_PRODUCTS];
        }

        return in_array($sKind, [self::KIND_PRODUCTS, self::KIND_CATEGORIES], true) ? [$sKind] : [];
    }

    /**
     * The whole run, computed in memory against an EVOLVING set of taken
     * slugs: each row's own slug is removed as it moves and its new one added,
     * so the suffixes are the ones the real run will produce.
     *
     * Computing against the unmodified database cannot see the collision that
     * matters: while row A still holds "foo", a row targeting "foo" comes back
     * "foo-2" and the pair is invisible.
     *
     * @return array<int,array{id:int,name:string,old:string,base:?string,new:?string,skip:?string}>
     */
    protected function buildPlan(string $sKind): array
    {
        $sSeparator = $this->modelOf($sKind)->getSluggableSeparator();

        $arTaken = [];
        foreach (Db::table($this->tableOf($sKind))->pluck('slug') as $sSlug) {
            // Soft-deleted rows count: newSluggableQuery applies withTrashed()
            $arTaken[mb_strtolower((string) $sSlug)] = true;
        }

        $arPlan = [];
        foreach ($this->candidateList($sKind) as $obRow) {
            $sOldSlug = (string) $obRow->slug;
            $sName = trim((string) $obRow->name);

            if ($sName === '') {
                $arPlan[] = $this->planRow($obRow->id, $sName, $sOldSlug, null, null, 'the source name is empty');

                continue;
            }

            unset($arTaken[mb_strtolower($sOldSlug)]);

            $sBase = Str::slug(mb_substr($sName, 0, self::SOURCE_MAX_LENGTH), $sSeparator, self::SLUG_LANGUAGE);
            if ($sBase === '') {
                $arTaken[mb_strtolower($sOldSlug)] = true;
                $arPlan[] = $this->planRow($obRow->id, $sName, $sOldSlug, null, null, 'the name slugs to nothing');

                continue;
            }

            $sNewSlug = $this->firstFreeSlug($sBase, $sSeparator, $arTaken);
            $arTaken[mb_strtolower($sNewSlug)] = true;

            $arPlan[] = $this->planRow($obRow->id, $sName, $sOldSlug, $sBase, $sNewSlug, null);
        }

        return $arPlan;
    }

    /**
     * The three conditions that refuse the whole run, each naming both rows.
     *
     * SlugHistory::record() opens by deleting rows whose old_slug equals the
     * incoming new slug - right when a product reclaims its own slug, wrong
     * when a different one takes it: A moving foo to bar records foo -> bar,
     * then B taking the freed foo deletes that row and /p/foo serves B with no
     * 301 to A.
     *
     * @param array<int,array> $arPlan
     * @return string[]
     */
    protected function conflictList(string $sKind, array $arPlan): array
    {
        $arChangingOldSlugList = [];
        foreach ($arPlan as $arRow) {
            if ($arRow['new'] === null || $arRow['new'] === $arRow['old']) {
                continue;
            }
            $arChangingOldSlugList[mb_strtolower($arRow['old'])] = $arRow['id'];
        }

        $arConflictList = [];
        $arRetiredBefore = [];

        foreach ($arPlan as $arRow) {
            if ($arRow['new'] === null) {
                continue;
            }

            $sNewLower = mb_strtolower($arRow['new']);
            $sBaseLower = mb_strtolower((string) $arRow['base']);

            if (isset($arRetiredBefore[$sNewLower])) {
                $arConflictList[] = $sKind . ' #' . $arRow['id'] . ' would take "' . $arRow['new']
                    . '", the slug #' . $arRetiredBefore[$sNewLower] . ' retires earlier in this run';
            }

            if (isset($arChangingOldSlugList[$sBaseLower]) && $arChangingOldSlugList[$sBaseLower] !== $arRow['id']) {
                $arConflictList[] = $sKind . ' #' . $arRow['id'] . ' targets base "' . $arRow['base']
                    . '", which #' . $arChangingOldSlugList[$sBaseLower] . ' is giving up in this run';
            }

            if ($arRow['new'] !== $arRow['old']) {
                $arRetiredBefore[mb_strtolower($arRow['old'])] = $arRow['id'];
            }
        }

        return array_merge($arConflictList, $this->historyConflictList($sKind, $arPlan));
    }

    /**
     * A target that is already a live redirect source: taking it would retire
     * that redirect silently.
     *
     * @param array<int,array> $arPlan
     * @return string[]
     */
    protected function historyConflictList(string $sKind, array $arPlan): array
    {
        $arTargetList = [];
        foreach ($arPlan as $arRow) {
            if ($arRow['new'] !== null && $arRow['new'] !== $arRow['old']) {
                $arTargetList[$arRow['new']] = $arRow['id'];
            }
        }

        if ($arTargetList === []) {
            return [];
        }

        $arConflictList = [];
        $obHistoryList = SlugHistory::where('model_type', $this->historyTypeOf($sKind))
            ->whereIn('old_slug', array_keys($arTargetList))
            ->get(['old_slug', 'new_slug']);

        foreach ($obHistoryList as $obHistory) {
            $arConflictList[] = $sKind . ' #' . $arTargetList[$obHistory->old_slug] . ' would take "'
                . $obHistory->old_slug . '", which still redirects to "' . $obHistory->new_slug . '"';
        }

        return $arConflictList;
    }

    /**
     * @param array<string,bool> $arTaken
     */
    protected function firstFreeSlug(string $sBase, string $sSeparator, array $arTaken): string
    {
        if (!isset($arTaken[mb_strtolower($sBase)])) {
            return $sBase;
        }

        // Sluggable starts its counter at 2 and compares case insensitively
        $iCounter = 2;
        while (isset($arTaken[mb_strtolower($sBase . $sSeparator . $iCounter)])) {
            $iCounter++;
        }

        return $sBase . $sSeparator . $iCounter;
    }

    /**
     * @param array<int,array> $arPlan
     */
    protected function reportPlan(string $sKind, array $arPlan): void
    {
        $this->line('--- ' . $sKind . ', ' . count($arPlan) . ' row(s) in the run');

        foreach ($arPlan as $arRow) {
            if ($arRow['skip'] !== null) {
                $this->warn('  #' . $arRow['id'] . ' SKIP (' . $arRow['skip'] . ') name="' . $arRow['name'] . '" slug="' . $arRow['old'] . '"');

                continue;
            }

            $sState = $arRow['new'] === $arRow['old'] ? 'UNCHANGED' : 'CHANGE   ';
            $this->line('  #' . $arRow['id'] . ' ' . $sState . ' name="' . $arRow['name'] . '" old="' . $arRow['old'] . '" new="' . $arRow['new'] . '"');
        }
    }

    /**
     * @param string[] $arKindList
     * @param array<string,array> $arPlanList
     */
    protected function writeKindList(array $arKindList, array $arPlanList): int
    {
        $sPreviousLocale = App::getLocale();

        // Sluggable reads App::getLocale() for the transliteration and offers
        // no other hook. It does not change which translated name is read:
        // that comes from the Translator's site locale.
        App::setLocale(self::SLUG_LANGUAGE);

        try {
            foreach ($arKindList as $sKind) {
                $this->writeKind($sKind, $arPlanList[$sKind]);
                if ($this->sHaltReason !== null) {
                    break;
                }
            }
        } finally {
            App::setLocale($sPreviousLocale);
        }

        $this->line('totals: changed=' . $this->arTotalList['changed']
            . ' unchanged=' . $this->arTotalList['unchanged']
            . ' skipped=' . $this->arTotalList['skipped']
            . ' failed=' . $this->arTotalList['failed']);

        if ($this->sHaltReason !== null) {
            $this->error('HALTED: ' . $this->sHaltReason);

            return self::FAILURE;
        }

        return $this->arTotalList['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<int,array> $arPlan
     */
    protected function writeKind(string $sKind, array $arPlan): void
    {
        $arPlanById = [];
        foreach ($arPlan as $arRow) {
            if ($arRow['skip'] !== null) {
                $this->arTotalList['skipped']++;

                continue;
            }
            if ($arRow['new'] === $arRow['old']) {
                $this->arTotalList['unchanged']++;

                continue;
            }

            $arPlanById[$arRow['id']] = $arRow;
        }

        if ($arPlanById === []) {
            return;
        }

        $this->modelOf($sKind)->newQuery()
            ->whereIn('id', array_keys($arPlanById))
            ->chunkById(self::CHUNK_SIZE, function ($obRowList) use ($sKind, $arPlanById) {
                foreach ($obRowList as $obRow) {
                    if (!$this->writeRow($sKind, $obRow, $arPlanById[$obRow->id])) {
                        return false;
                    }
                }

                $this->line('  ' . $sKind . ' chunk done, last id ' . $obRowList->last()->id);

                return true;
            });
    }

    /**
     * @param array{id:int,old:string,new:?string} $arRow
     */
    protected function writeRow(string $sKind, $obModel, array $arRow): bool
    {
        $obModel->slug = '';

        try {
            $obModel->save();
        } catch (\Throwable $obException) {
            $this->arTotalList['failed']++;
            $this->error('  #' . $arRow['id'] . ' failed to save: ' . $obException->getMessage());

            return true;
        }

        // The simulation is a second implementation of a rule Sluggable owns,
        // so a drift between them stops the run instead of being carried into
        // the hazard the preflight exists to prevent.
        if (mb_strtolower((string) $obModel->slug) !== mb_strtolower((string) $arRow['new'])) {
            $this->sHaltReason = $sKind . ' #' . $arRow['id'] . ' got slug "' . $obModel->slug
                . '" where the simulation expected "' . $arRow['new'] . '"';

            return false;
        }

        $this->arTotalList['changed']++;

        return true;
    }

    /**
     * Every non-soft-deleted product regardless of active, every category: a
     * later activation must not bring back a Latvian URL.
     */
    protected function candidateList(string $sKind)
    {
        $obQuery = $this->modelOf($sKind)->newQuery()->orderBy('id');

        if ($sKind === self::KIND_PRODUCTS) {
            $obQuery->whereNull('deleted_at');
        }

        $iLimit = (int) $this->option('limit');
        if ($iLimit > 0) {
            $obQuery->limit($iLimit);
        }

        return $obQuery->get(['id', 'slug', 'name']);
    }

    protected function modelOf(string $sKind)
    {
        return $sKind === self::KIND_PRODUCTS ? new Product : new Category;
    }

    protected function tableOf(string $sKind): string
    {
        return $sKind === self::KIND_PRODUCTS ? 'lovata_shopaholic_products' : 'lovata_shopaholic_categories';
    }

    protected function historyTypeOf(string $sKind): string
    {
        return $sKind === self::KIND_PRODUCTS ? SlugHistory::TYPE_PRODUCT : SlugHistory::TYPE_CATEGORY;
    }

    /**
     * @return array{id:int,name:string,old:string,base:?string,new:?string,skip:?string}
     */
    protected function planRow(int $iRowID, string $sName, string $sOldSlug, ?string $sBase, ?string $sNewSlug, ?string $sSkip): array
    {
        return [
            'id' => $iRowID,
            'name' => $sName,
            'old' => $sOldSlug,
            'base' => $sBase,
            'new' => $sNewSlug,
            'skip' => $sSkip,
        ];
    }
}
