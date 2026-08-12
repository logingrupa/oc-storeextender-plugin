<?php namespace Logingrupa\StoreExtender\Classes\Middleware;

use Closure;
use Crypt;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * Expire broken cart cookies so the cart keeps its identity.
 *
 * The cart cookie is the SOLE identity of a guest's cart. CartProcessor sets
 * it at path=/, but browsers may also hold a same-name cookie at a longer
 * path (an old release, an old APP_KEY era, a locale-prefixed set) - and RFC
 * 6265 orders the longer path FIRST in the Cookie header, so PHP sees the
 * stale value on every request. CartProcessor then silently mints a brand
 * new cart per request: an add succeeds into its own throwaway cart (the
 * client truthfully toasts success) while every following read gets a fresh
 * empty one. The fresh path=/ cookie can never displace the shadow, so the
 * browser never heals on its own.
 *
 * Two signals prove a broken cookie, and both are checked BEFORE the app
 * runs so the heal ships on this very response:
 *  - the raw header carries the cookie name twice (a longer-path shadow
 *    beside the healthy path=/ copy), or
 *  - the raw header carries the cookie but its value is unusable - the
 *    framework's cookie decryption stripped it, or it neither is a cart id
 *    nor decrypts to one.
 * Either way the cookie is expired at every ancestor directory path of the
 * current URL - the only paths a shadow could have been stored under and
 * still be sent for this request. CartProcessor re-sets the healthy path=/
 * copy on the same response, so the next request carries exactly one cookie.
 */
class ClearShadowCartCookie
{
    /** @var int Sane bound: no shop URL nests deeper than this */
    const MAX_PATH_DEPTH = 6;

    /**
     * @param \Illuminate\Http\Request $obRequest
     * @param \Closure                 $obNext
     * @return mixed
     */
    public function handle(Request $obRequest, Closure $obNext)
    {
        // decided before the app runs: CartProcessor overwrites nothing the
        // detection depends on, and the expiries must ride THIS response
        $bBrokenCookie = $this->hasShadowCartCookie($obRequest) || $this->hasUnusableCartCookie($obRequest);

        $obResponse = $obNext($obRequest);

        if ($bBrokenCookie && $obResponse instanceof Response) {
            foreach ($this->getShadowPathList($obRequest) as $sPath) {
                $obResponse->headers->setCookie($this->makeExpiredCookie($sPath));
            }
        }

        return $obResponse;
    }

    /**
     * More than one cart cookie in the raw header = a longer-path shadow
     * exists. $_COOKIE (and Request::cookie) collapse duplicates to the
     * first, so only the raw header can prove it.
     * @param \Illuminate\Http\Request $obRequest
     * @return bool
     */
    protected function hasShadowCartCookie(Request $obRequest): bool
    {
        return substr_count($this->getRawCookieHeader($obRequest), CartProcessor::COOKIE_NAME.'=') > 1;
    }

    /**
     * The browser sent a cart cookie the application cannot use: the cookie
     * middleware already stripped it (failed decryption), or the value that
     * survived is neither a cart id nor decryptable to one. CartProcessor
     * will mint a new cart and re-set path=/, so any longer-path original
     * must die with this response or it shadows the fresh copy forever.
     * @param \Illuminate\Http\Request $obRequest
     * @return bool
     */
    protected function hasUnusableCartCookie(Request $obRequest): bool
    {
        if (strpos($this->getRawCookieHeader($obRequest), CartProcessor::COOKIE_NAME.'=') === false) {
            return false;
        }

        $sCookieValue = (string) $obRequest->cookie(CartProcessor::COOKIE_NAME, '');
        if ($sCookieValue === '') {
            return true; // sent by the browser, stripped by decryption
        }
        if (is_numeric($sCookieValue)) {
            return false; // a plausible cart id; a dead id is healed by the duplicate signal
        }

        return !$this->decryptsToCartId($sCookieValue);
    }

    /**
     * CartProcessor::init() accepts a non-numeric cookie only if it decrypts
     * to a non-empty value; mirror exactly that.
     * @param string $sCookieValue
     * @return bool
     */
    protected function decryptsToCartId(string $sCookieValue): bool
    {
        try {
            return !empty(Crypt::decryptString($sCookieValue));
        } catch (\Exception $obException) {
            return false; // undecryptable: the value can never name a cart
        }
    }

    /**
     * @param \Illuminate\Http\Request $obRequest
     * @return string
     */
    protected function getRawCookieHeader(Request $obRequest): string
    {
        return (string) $obRequest->headers->get('cookie');
    }

    /**
     * Every ancestor directory path of the request URL, shallowest first:
     * a request for /lv/p/slug/123 can only be shadowed by a cookie stored
     * at /lv, /lv/p, /lv/p/slug or the full path (path=/ holds the healthy
     * copy CartProcessor re-sets itself). Cookie deletion must byte-match
     * the stored path, so each candidate is listed.
     * @param \Illuminate\Http\Request $obRequest
     * @return array<string>
     */
    protected function getShadowPathList(Request $obRequest): array
    {
        $arSegmentList = array_slice($obRequest->segments(), 0, self::MAX_PATH_DEPTH);
        $arPathList = [];
        $sPath = '';
        foreach ($arSegmentList as $sSegment) {
            $sPath .= '/'.$sSegment;
            $arPathList[] = $sPath;
        }

        return $arPathList;
    }

    /**
     * @param string $sPath
     * @return \Symfony\Component\HttpFoundation\Cookie
     */
    protected function makeExpiredCookie(string $sPath): Cookie
    {
        return Cookie::create(CartProcessor::COOKIE_NAME)
            ->withValue('')
            ->withExpires(1)
            ->withPath($sPath);
    }
}
