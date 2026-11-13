<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopSitemap;

/**
 * Drop what this shop remembers about its siblings, both surfaces.
 *
 * Run once per box after another shop renamed its slugs, so this shop picks up
 * the new URLs at once instead of waiting out the lease, and after a deploy.
 * Exact and instant: no fetch, no TTL change. The next
 * storeextender:warm-sibling-maps refills both surfaces.
 *
 * It does NOT touch this shop's own published body. That body's cache key
 * carries the catalogue stamp, so a renamed slug makes the old body
 * unreachable by construction, and the file cache driver cannot enumerate the
 * stamped variants anyway.
 *
 *   php artisan storeextender:forget-sibling-maps
 */
class ForgetSiblingMaps extends Command
{
    /** @var string */
    protected $signature = 'storeextender:forget-sibling-maps';

    /** @var string */
    protected $description = 'Forget the cached sibling shop id maps and sitemap url sets';

    public function handle(): int
    {
        $sOwnOrigin = ShopIdMap::ownOrigin();

        foreach (SiblingShopMap::CROSS_DOMAIN_LIST as $sDomain) {
            if ($sDomain === $sOwnOrigin) {
                continue;
            }

            SiblingShopMap::forget($sDomain);
            SiblingShopSitemap::forget($sDomain);
            SiblingShopMap::forgetLease(SiblingShopMap::SURFACE_ID_MAP, $sDomain);
            SiblingShopMap::forgetLease(SiblingShopMap::SURFACE_SITEMAP, $sDomain);

            $this->info($sDomain . ': id map, sitemap url set and both leases cleared');
        }

        return self::SUCCESS;
    }
}
