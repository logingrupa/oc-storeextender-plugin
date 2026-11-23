<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Middleware;

use Closure;
use Config;
use Illuminate\Http\Request;
use Site;
use Symfony\Component\HttpFoundation\RedirectResponse;
use System\Models\SiteDefinition;

/**
 * Fix HTTP semantics on October's bare-URL browser-language redirect.
 *
 * When a prefixed shop (.lv, .lt) receives an unprefixed URL (/ or /p/slug) with
 * cms.redirect_policy = 'detect', CmsController::redirectWithoutPrefix() chooses a target
 * site from HTTP_ACCEPT_LANGUAGE and returns a 301 Permanent Redirect with no Vary header.
 *
 * A permanent redirect whose destination depends on Accept-Language causes three bugs:
 *  - browsers cache the 301 permanently, pinning the visitor to one language for that URL;
 *  - shared caches / CDNs without Vary: Accept-Language can serve one locale's redirect to
 *    everyone else;
 *  - Google's multi-regional guidance specifies 302 + Vary: Accept-Language for locale
 *    negotiation, reserving 301 for deterministic permanent moves.
 *
 * Only October's fallback-prefix redirect (active site is prefixed, matched as a fallback
 * without a prefix, policy is 'detect', the site group has more than one enabled site, and
 * the Location path matches a group sibling's attachRoutePrefix() of the request path) is
 * rewritten. Deterministic 301s - such as LegacyUrlRedirectHandler on /lv/old-category, a
 * single-language prefixed shop, or a fallback-matched path like /lvx that fell through to
 * Controller::run() and redirected elsewhere - stay untouched as 301.
 */
class LocaleDetectRedirectHeaders
{
    /**
     * @param \Illuminate\Http\Request $obRequest
     * @param \Closure                 $obNext
     * @return mixed
     */
    public function handle(Request $obRequest, Closure $obNext)
    {
        $obResponse = $obNext($obRequest);

        if ($this->isLocaleDetectedRedirect($obRequest, $obResponse)) {
            $obResponse->setStatusCode(302);
            // false = append, so an existing Vary header (e.g. Accept-Encoding) survives
            $obResponse->setVary('Accept-Language', false);
            if (!$obResponse->headers->hasCacheControlDirective('no-store')) {
                $obResponse->headers->set('Cache-Control', 'private, no-cache');
            }
        }

        return $obResponse;
    }

    /**
     * True only when this response is October's Accept-Language fallback redirect across
     * multiple enabled sites in the same group.
     *
     * @param \Illuminate\Http\Request $obRequest
     * @param mixed                    $obResponse
     * @return bool
     */
    protected function isLocaleDetectedRedirect(Request $obRequest, $obResponse): bool
    {
        if (!$obResponse instanceof RedirectResponse || $obResponse->getStatusCode() !== 301) {
            return false;
        }

        if (Config::get('cms.redirect_policy', 'detect') !== 'detect') {
            return false;
        }

        $obSite = Site::getActiveSite();
        if (!$obSite instanceof SiteDefinition || !$obSite->is_prefixed || !$obSite->isFallbackMatch) {
            return false;
        }

        $obGroupSites = Site::listEnabled()->inGroup($obSite->group_id);
        if ($obGroupSites->count() <= 1) {
            return false;
        }

        return $this->locationMatchesPrefixAttachment($obRequest, $obResponse, $obGroupSites);
    }

    /**
     * Confirm Location is the request path with one of the group's site prefixes attached.
     * Guards against October's raw-substr removeRoutePrefix() quirk (e.g. /lvx falling
     * through to Controller::run('x') and issuing a real 301 to a different path).
     *
     * @param \Illuminate\Http\Request                         $obRequest
     * @param \Symfony\Component\HttpFoundation\RedirectResponse $obResponse
     * @param iterable<SiteDefinition>                         $obGroupSites
     * @return bool
     */
    protected function locationMatchesPrefixAttachment(Request $obRequest, RedirectResponse $obResponse, $obGroupSites): bool
    {
        $sLocation = (string) $obResponse->headers->get('Location', '');
        $sLocationPath = trim((string) parse_url($sLocation, PHP_URL_PATH), '/');
        $sOriginalPath = ltrim($obRequest->getPathInfo(), '/');
        if ($sOriginalPath === '') {
            $sOriginalPath = '/';
        }

        foreach ($obGroupSites as $obCandidateSite) {
            if (trim($obCandidateSite->attachRoutePrefix($sOriginalPath), '/') === $sLocationPath) {
                return true;
            }
        }

        return false;
    }
}
