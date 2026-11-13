<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * Drop what this shop remembers about its siblings' URLs.
 *
 * Run once per box after another shop renamed its slugs, so this shop picks up
 * the new URLs at once instead of waiting out the lease, and after a deploy.
 * Exact and instant: no fetch, no TTL change. The next
 * storeextender:warm-sibling-maps refills the url set.
 *
 *   php artisan storeextender:forget-sibling-maps
 */
class ForgetSiblingMaps extends Command
{
    /** @var string */
    protected $signature = 'storeextender:forget-sibling-maps';

    /** @var string */
    protected $description = 'Forget the cached sibling shop sitemap url sets';

    public function handle(): int
    {
        $sOwnOrigin = ShopIdMap::ownOrigin();

        foreach (SiblingShopSitemap::CROSS_DOMAIN_LIST as $sDomain) {
            if ($sDomain === $sOwnOrigin) {
                continue;
            }

            SiblingShopSitemap::forget($sDomain);
            SiblingShopSitemap::forgetLease($sDomain);

            $this->info($sDomain . ': sitemap url set and lease cleared');
        }

        return self::SUCCESS;
    }
}
