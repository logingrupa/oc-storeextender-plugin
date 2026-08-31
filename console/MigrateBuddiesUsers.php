<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

use Logingrupa\StoreExtender\Classes\Helper\BuddiesUserPorter;
use Logingrupa\StoreExtender\Classes\Helper\BuddiesUserChecksum;

/**
 * Class MigrateBuddiesUsers
 *
 * Ports Lovata.Buddies accounts onto RainLab.User: users, groups, memberships, dynamic
 * property definitions and password hashes, with the original ids preserved.
 *
 * Run it while Buddies is still installed - it reads the Buddies tables. Removing the
 * plugin is a separate, later step.
 *
 * The run is idempotent, so the verification pass can be repeated on demand:
 *
 *   php artisan storeextender:migrate-buddies-users --dry-run
 *   php artisan storeextender:migrate-buddies-users
 *   php artisan storeextender:migrate-buddies-users --verify-only
 *
 * Exit code is non zero when any column checksum disagrees. Verify right after the port:
 * "last_seen" starts drifting the moment anyone logs in, because RainLab touches it on every
 * request.
 *
 * @package Logingrupa\StoreExtender\Console
 */
class MigrateBuddiesUsers extends Command
{
    protected $name = 'storeextender:migrate-buddies-users';
    protected $description = 'Port Lovata.Buddies accounts, groups and properties onto RainLab.User';

    public function handle()
    {
        $bDryRun = (bool) $this->option('dry-run');
        $bVerifyOnly = (bool) $this->option('verify-only');

        $arBlockerList = BuddiesUserPorter::instance()->getBlockerList();
        if (!empty($arBlockerList)) {
            foreach ($arBlockerList as $sBlocker) {
                $this->error($sBlocker);
            }

            return 1;
        }

        if (!$bVerifyOnly) {
            $this->reportPlan();
        }

        if ($bDryRun) {
            $this->warn('Dry run - no rows written.');

            return 0;
        }

        if (!$bVerifyOnly) {
            if (!$this->confirmRewrite()) {
                return 1;
            }

            $this->runPort();
        }

        return $this->verify();
    }

    /**
     * What the run is about to touch
     */
    protected function reportPlan()
    {
        $arRowList = [];
        foreach (BuddiesUserPorter::instance()->getPlan() as $sLabel => $iCount) {
            $arRowList[] = [$sLabel, $iCount];
        }

        $this->table(['plan', 'rows'], $arRowList);
    }

    /**
     * A first run only inserts. A second run rewrites every ported row back to the Buddies
     * snapshot, which after go-live discards real activity: last_seen, and any phone,
     * property or name the customer has changed since. Rewriting is still the right repair
     * for a failed migration, so it is gated rather than removed.
     *
     * @return bool
     */
    protected function confirmRewrite()
    {
        if ((bool) $this->option('force')) {
            return true;
        }

        $iRewriteCount = (int) array_get(BuddiesUserPorter::instance()->getPlan(), 'users to rewrite');
        if ($iRewriteCount == 0) {
            return true;
        }

        $this->warn(sprintf(
            'This run rewrites %d existing user rows back to their Buddies values, discarding '
            .'anything changed since the port (last_seen, phone, property, name).',
            $iRewriteCount
        ));

        if ($this->confirm('Continue?', false)) {
            return true;
        }

        $this->info('Aborted. Use --verify-only to compare without writing, or --force to skip this prompt.');

        return false;
    }

    /**
     * Execute the port and report per step
     */
    protected function runPort()
    {
        $arResultList = BuddiesUserPorter::instance()->port();

        $arRowList = [];
        foreach ($arResultList as $sStep => $iCount) {
            $arRowList[] = [$sStep, $iCount];
        }

        // MySQL reports 1 for an inserted row and 2 for a rewritten one, so this column
        // is a progress signal, not a row count. The verification table below carries the
        // authoritative counts.
        $this->table(['step', 'affected'], $arRowList);
    }

    /**
     * Per column source against target, and the exit code
     * @return int
     */
    protected function verify()
    {
        $arComparisonList = BuddiesUserChecksum::instance()->compare();

        $arRowList = [];
        $iMismatch = 0;
        foreach ($arComparisonList as $arComparison) {
            if (!$arComparison['match']) {
                $iMismatch++;
            }

            $arRowList[] = [
                $arComparison['section'],
                $arComparison['column'],
                $this->formatDigest($arComparison['source']),
                $this->formatDigest($arComparison['target']),
                $arComparison['match'] ? 'ok' : 'MISMATCH',
            ];
        }

        $this->table(['section', 'column', 'source rows/filled/digest', 'target rows/filled/digest', ''], $arRowList);

        if ($iMismatch > 0) {
            $this->error($iMismatch.' of '.count($arComparisonList).' checksums disagree.');

            return 1;
        }

        $this->info('All '.count($arComparisonList).' checksums match.');

        return 0;
    }

    /**
     * @param array $arDigest
     * @return string
     */
    protected function formatDigest(array $arDigest)
    {
        return $arDigest['rows'].' / '.$arDigest['filled'].' / '.$arDigest['digest'];
    }

    protected function getOptions()
    {
        return [
            ['dry-run', null, InputOption::VALUE_NONE, 'Report the plan without writing a row'],
            ['verify-only', null, InputOption::VALUE_NONE, 'Skip the port and only re-run the checksum comparison'],
            ['force', null, InputOption::VALUE_NONE, 'Rewrite existing rows without the confirmation prompt'],
        ];
    }
}
