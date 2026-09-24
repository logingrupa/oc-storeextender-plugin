<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Device;

use Event;
use Logingrupa\StoreExtender\Classes\Helper\DeviceHint;

/**
 * Class DeviceLayoutHandler
 *
 * Points the phone at a leaner layout for one page, at the only seam early
 * enough to matter: Controller::runPage() reads $page->layout at
 * Controller.php:323 and Layout::loadCached() runs at :326, both long after
 * cms.page.beforeDisplay fires at :205.
 *
 * The guard order is what keeps this branch dark. While no page named
 * product2 exists in the theme, the page-name guard returns on every request,
 * so DeviceHint is never consulted and no device header is emitted anywhere
 * on the shop.
 *
 * The listener also fires on the Larajax partial-capture path, which is
 * required rather than incidental: Controller::run() resolves $page
 * identically at :161-212 before the capture fork at :231, so a shade tap on
 * the phone re-renders its fragments under the layout the page loaded with.
 *
 * fireSystemEvent halts on the first non-null return (EventEmitter.php:54),
 * so a future listener on this hook that returns a value silently disables
 * this branch.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Device
 */
class DeviceLayoutHandler
{
    /**
     * Base file name of the page that carries the phone layout. Phase 7's
     * flip is one edit here: 'product2' becomes 'product'.
     */
    const MOBILE_PAGE_NAME = 'product2';

    /** Layout the phone page loads with. This value never changes. */
    const LEAN_LAYOUT_NAME = 'shop-lean';

    /**
     * Listen on the page display seam and hand the phone page its layout.
     * Called once from Plugin::boot(). Every other page slated for the lean
     * layout declares it as a literal INI string in its own page file, so
     * this class carries one page name rather than a map.
     * @return void
     */
    public static function switchLayoutOnPageDisplay(): void
    {
        Event::listen('cms.page.beforeDisplay', function ($obController, $sUrl, $obPage): void {
            // $obPage is legitimately null: a hidden page without a backend
            // user (Controller.php:162), the 404 fallback, which runs after
            // this event (:215), and maintenance mode when no maintenance
            // page is configured, which is this theme's case.
            if ($obPage === null) {
                return;
            }
            // Match on the base name: CmsObject.php:251 strips the ".htm"
            // extension, the raw name at :242 keeps it, so matching the raw
            // name against 'product2' could never be true.
            if ($obPage->getBaseFileName() !== self::MOBILE_PAGE_NAME) {
                return;
            }
            if (!DeviceHint::isMobile()) {
                return;
            }

            // Mutate, hand back nothing: Controller.php:205-212 returns any
            // truthy non-Page value as the whole HTTP response body.
            $obPage->layout = self::LEAN_LAYOUT_NAME;
        });
    }
}
