<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * Fill the sibling sitemap cache the hreflang block reads, ahead of traffic.
 *
 * This is the ONLY code path in the plugin that calls another shop. The reader
 * is a cache read inside a request, because a miss used to fetch a sibling
 * sitemap inline: 0.9 s when both answered, a measured 10 s for a visitor on
 * 2026-11-12 when they did not.
 *
 * The walk, the counting and the exit code live here; the helper owns its own
 * fetch, its validation and its cache contract. A failure is LOUD - a warning
 * in the log, a line on stdout and a non-zero exit - because a silently
 * stopped warm now costs every shop its cross-domain alternates until the next
 * successful run, not one render.
 *
 *   php artisan storeextender:warm-sibling-maps
 *   php artisan storeextender:warm-sibling-maps --domain=https://nailscosmetics.lv --force
 *   php artisan storeextender:warm-sibling-maps --status
 */
class WarmSiblingMaps extends Command
{
    /** Past this age a sibling reads as stale, which is how a stopped scheduler surfaces */
    const STATUS_THRESHOLD_MINUTES = 60;

    /** @var string */
    protected $signature = 'storeextender:warm-sibling-maps
        {--domain= : Warm only this sibling origin, e.g. https://nailscosmetics.lv}
        {--force : Fetch even while the freshness lease is still valid}
        {--status : Report the age of the last success per sibling and fetch nothing}';

    /** @var string */
    protected $description = 'Fetch the sibling shops sitemap url sets the hreflang block reads';

    public function handle(): int
    {
        $arDomainList = $this->domainList();
        if ($arDomainList === []) {
            $this->error('No sibling domain to work on. --domain must name one of: ' . implode(', ', SiblingShopSitemap::CROSS_DOMAIN_LIST));

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->reportStatus($arDomainList);
        }

        $iFailureCount = $this->warmDomainList($arDomainList);

        if ($iFailureCount > 0) {
            $this->error($iFailureCount . ' sibling(s) failed, no lease written for them, the next tick retries');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param string[] $arDomainList
     * @return int how many siblings failed
     */
    protected function warmDomainList(array $arDomainList): int
    {
        $bForce = (bool) $this->option('force');
        $iFailureCount = 0;

        foreach ($arDomainList as $sDomain) {
            if (!$bForce && SiblingShopSitemap::leaseIsFresh($sDomain)) {
                $this->line($sDomain . ' sitemap: lease still fresh, skipped');

                continue;
            }

            $iCount = SiblingShopSitemap::warm($sDomain);
            if ($iCount === null) {
                $iFailureCount++;
                Log::warning('WarmSiblingMaps: the sitemap of ' . $sDomain . ' could not be warmed');
                $this->error($sDomain . ' sitemap: FAILED');

                continue;
            }

            $this->info($sDomain . ' sitemap: ' . $iCount . ' entries');
        }

        return $iFailureCount;
    }

    /**
     * @param string[] $arDomainList
     */
    protected function reportStatus(array $arDomainList): int
    {
        $bStale = false;

        foreach ($arDomainList as $sDomain) {
            $obLastSuccess = SiblingShopSitemap::lastSuccessAt($sDomain);

            if (empty($obLastSuccess)) {
                $bStale = true;
                $this->error($sDomain . ' sitemap: never warmed on this box');

                continue;
            }

            $iAgeMinutes = (int) $obLastSuccess->diffInMinutes(now());
            $sLine = $sDomain . ' sitemap: last success ' . $iAgeMinutes . ' min ago (' . $obLastSuccess->toIso8601String() . ')';

            if ($iAgeMinutes > self::STATUS_THRESHOLD_MINUTES) {
                $bStale = true;
                $this->error($sLine . ' - STALE');

                continue;
            }

            $this->info($sLine);
        }

        if ($bStale) {
            $this->error('A sibling is older than ' . self::STATUS_THRESHOLD_MINUTES . ' minutes: check that the schedule is running, then --force');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Every sibling of the family, this shop itself left out, matched on scheme
     * plus host against the primary site base_url.
     *
     * @return string[]
     */
    protected function domainList(): array
    {
        $sOwnOrigin = ShopIdMap::ownOrigin();
        $sOnly = rtrim((string) $this->option('domain'), '/');

        $arDomainList = [];
        foreach (SiblingShopSitemap::CROSS_DOMAIN_LIST as $sDomain) {
            if ($sDomain === $sOwnOrigin) {
                continue;
            }
            if ($sOnly !== '' && $sDomain !== $sOnly) {
                continue;
            }

            $arDomainList[] = $sDomain;
        }

        return $arDomainList;
    }
}
