<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Logingrupa\StoreExtender\Classes\Helper\DeviceHint;

/**
 * Label a device-branched response for shared caches and for crawlers.
 *
 * One URL serves two layouts, so a CDN or proxy must be told the body depends
 * on the client, or it will hand the phone layout to a desktop; Google's
 * dynamic-serving guidance asks for Vary: User-Agent on exactly this shape of
 * page, and the client-hint signal is named beside it. The work happens after
 * $obNext because no response object exists any earlier - the page display
 * event has none, and cms.page.display (Controller.php:259-260) can still
 * hand over a raw string.
 *
 * No page name is matched here. DeviceLayoutHandler's guard order decides
 * whether DeviceHint is ever touched, so the consultation flag IS the page
 * match, and every page the branch never reached keeps the headers it has
 * today. CmsController::extend attaches this to the frontend controller only,
 * so no backend guard is needed.
 */
class DeviceVaryHeader
{
    /**
     * @param \Illuminate\Http\Request $obRequest
     * @param \Closure                 $obNext
     * @return mixed
     */
    public function handle(Request $obRequest, Closure $obNext)
    {
        $obResponse = $obNext($obRequest);

        if ($this->wasConsulted() && $obResponse instanceof Response) {
            // false = append, so a Vary another layer already set survives
            $obResponse->setVary(['Sec-CH-UA-Mobile', 'User-Agent'], false);
            // Symfony computes "no-cache, private" whenever nothing set a
            // directive (ResponseHeaderBag.php:243-251), so this pins a value
            // rather than adding a header. Chrome drops a page from the
            // back/forward cache only when storing it is forbidden outright,
            // which this value deliberately allows: catalog to product to
            // back is the most common gesture on this shop.
            $obResponse->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
        }

        return $obResponse;
    }

    /**
     * Seam the unit test subclasses to drive both branches without booting an
     * application, which is what lets DeviceHint keep its per-request memo
     * with no reset method on a production class.
     * @return bool
     */
    protected function wasConsulted(): bool
    {
        return DeviceHint::wasConsulted();
    }
}
