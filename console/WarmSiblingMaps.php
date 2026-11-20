<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopIdMap;
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
     * The two things a sibling publishes, label first for the console lines.
     *
     * The sitemap answers "does that shop serve this path" and still carries
     * the static pages. The id map answers "which path does that shop serve
     * for this 1C row", which is the only question that survives a shop
     * translating its slugs.
     */
    const RESOURCE_LIST = [
        'sitemap' => SiblingShopSitemap::class,
        'id map' => SiblingShopIdMap::class,
    ];

    /**
     * @param string[] $arDomainList
     * @return int how many sibling resources failed
     */
    protected function warmDomainList(array $arDomainList): int
    {
        $bForce = (bool) $this->option('force');
        $iFailureCount = 0;

        // This shop's own path-to-id map, which the hreflang block needs before
        // it can ask a sibling anything. Local read, no network.
        $arOwnMap = SiblingShopIdMap::warmOwn();
        $iOwnCount = 0;
        foreach ($arOwnMap as $arBucket) {
            $iOwnCount += count($arBucket);
        }
        if ($iOwnCount === 0) {
            // Loud but not a failure: an empty catalogue is a data condition,
            // not a fetch that broke, and this command's exit code is about
            // whether the siblings could be refreshed. A shop that really
            // publishes nothing is caught at the other end anyway, because
            // /api/shop-id-map.json answers 503 and the sibling's warm of it
            // reports FAILED.
            Log::warning('WarmSiblingMaps: this shop published no id row, nothing can pair');
            $this->error('own id map: EMPTY, no row carries an external_id');
        } else {
            $this->info('own id map: ' . $iOwnCount . ' rows');
        }

        foreach ($arDomainList as $sDomain) {
            foreach (self::RESOURCE_LIST as $sLabel => $sClass) {
                if (!$bForce && $sClass::leaseIsFresh($sDomain)) {
                    $this->line($sDomain . ' ' . $sLabel . ': lease still fresh, skipped');

                    continue;
                }

                $iCount = $sClass::warm($sDomain);
                if ($iCount === null) {
                    $iFailureCount++;
                    Log::warning('WarmSiblingMaps: the ' . $sLabel . ' of ' . $sDomain . ' could not be warmed');
                    $this->error($sDomain . ' ' . $sLabel . ': FAILED');

                    continue;
                }

                $this->info($sDomain . ' ' . $sLabel . ': ' . $iCount . ' entries');
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
            foreach (self::RESOURCE_LIST as $sLabel => $sClass) {
                $obLastSuccess = $sClass::lastSuccessAt($sDomain);

                if (empty($obLastSuccess)) {
                    $bStale = true;
                    $this->error($sDomain . ' ' . $sLabel . ': never warmed on this box');

                    continue;
                }

                $iAgeMinutes = (int) $obLastSuccess->diffInMinutes(now());
                $sLine = $sDomain . ' ' . $sLabel . ': last success ' . $iAgeMinutes
                    . ' min ago (' . $obLastSuccess->toIso8601String() . ')';

                if ($iAgeMinutes > self::STATUS_THRESHOLD_MINUTES) {
                    $bStale = true;
                    $this->error($sLine . ' - STALE');

                    continue;
                }

                $this->info($sLine);
            }
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
