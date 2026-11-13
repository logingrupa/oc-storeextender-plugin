<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * Fill both sibling caches the hreflang block reads, ahead of traffic.
 *
 * This is the ONLY code path in the plugin that calls another shop. Both
 * readers - the 1C id map and the sitemap url set - are cache reads inside a
 * request, because a miss used to fetch a sibling sitemap inline: 0.9 s when
 * both answered, a measured 10 s for a visitor on 2026-11-12 when they did not.
 *
 * The walk, the counting and the exit code live here; each helper owns its own
 * fetch, its validation and its cache contract. A failure is LOUD - a warning
 * in the log, a line on stdout and a non-zero exit - because a silently
 * stopped warm now costs every shop its cross-domain alternates until the next
 * successful run, not one render.
 *
 * The same run primes this shop's OWN published body under the current
 * catalogue stamp, so neither a visitor after a deploy nor a sibling's fetch
 * pays the cold build.
 *
 *   php artisan storeextender:warm-sibling-maps
 *   php artisan storeextender:warm-sibling-maps --domain=https://nailscosmetics.lv --force
 *   php artisan storeextender:warm-sibling-maps --status
 */
class WarmSiblingMaps extends Command
{
    /** Past this age a surface reads as stale, which is how a stopped scheduler surfaces */
    const STATUS_THRESHOLD_MINUTES = 60;

    /** Which helper fills which surface */
    const SURFACE_LIST = [
        SiblingShopMap::SURFACE_ID_MAP => [SiblingShopMap::class, 'warm'],
        SiblingShopMap::SURFACE_SITEMAP => [SiblingShopSitemap::class, 'warm'],
    ];

    /** @var string */
    protected $signature = 'storeextender:warm-sibling-maps
        {--domain= : Warm only this sibling origin, e.g. https://nailscosmetics.lv}
        {--force : Fetch even while the freshness lease is still valid}
        {--status : Report the age of the last success per sibling per surface and fetch nothing}';

    /** @var string */
    protected $description = 'Fetch the sibling shops id maps and sitemap url sets the hreflang block reads';

    public function handle(): int
    {
        $arDomainList = $this->domainList();
        if ($arDomainList === []) {
            $this->error('No sibling domain to work on. --domain must name one of: ' . implode(', ', SiblingShopMap::CROSS_DOMAIN_LIST));

            return self::FAILURE;
        }

        if ($this->option('status')) {
            return $this->reportStatus($arDomainList);
        }

        $iFailureCount = $this->warmDomainList($arDomainList);

        $arBody = ShopIdMap::publishedBody();
        $this->line('own published body: ' . count($arBody['map']) . ' entries under the current catalogue stamp');

        if ($iFailureCount > 0) {
            $this->error($iFailureCount . ' surface(s) failed, no lease written for them, the next tick retries');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param string[] $arDomainList
     * @return int how many surfaces failed
     */
    protected function warmDomainList(array $arDomainList): int
    {
        $bForce = (bool) $this->option('force');
        $iFailureCount = 0;

        foreach ($arDomainList as $sDomain) {
            foreach (self::SURFACE_LIST as $sSurface => $arCallable) {
                if (!$bForce && SiblingShopMap::leaseIsFresh($sSurface, $sDomain)) {
                    $this->line($sDomain . ' ' . $sSurface . ': lease still fresh, skipped');

                    continue;
                }

                $iCount = call_user_func($arCallable, $sDomain);
                if ($iCount === null) {
                    $iFailureCount++;
                    Log::warning('WarmSiblingMaps: ' . $sSurface . ' of ' . $sDomain . ' could not be warmed');
                    $this->error($sDomain . ' ' . $sSurface . ': FAILED');

                    continue;
                }

                $this->info($sDomain . ' ' . $sSurface . ': ' . $iCount . ' entries');
            }
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
            foreach (array_keys(self::SURFACE_LIST) as $sSurface) {
                $obLastSuccess = SiblingShopMap::lastSuccessAt($sSurface, $sDomain);

                if (empty($obLastSuccess)) {
                    $bStale = true;
                    $this->error($sDomain . ' ' . $sSurface . ': never warmed on this box');

                    continue;
                }

                $iAgeMinutes = (int) $obLastSuccess->diffInMinutes(now());
                $sLine = $sDomain . ' ' . $sSurface . ': last success ' . $iAgeMinutes . ' min ago (' . $obLastSuccess->toIso8601String() . ')';

                if ($iAgeMinutes > self::STATUS_THRESHOLD_MINUTES) {
                    $bStale = true;
                    $this->error($sLine . ' - STALE');

                    continue;
                }

                $this->info($sLine);
            }
        }

        if ($bStale) {
            $this->error('A surface is older than ' . self::STATUS_THRESHOLD_MINUTES . ' minutes: check that the schedule is running, then --force');

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
        foreach (SiblingShopMap::CROSS_DOMAIN_LIST as $sDomain) {
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
