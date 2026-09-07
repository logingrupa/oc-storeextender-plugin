<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Detection\MobileDetect;

/**
 * Answers one question: is this request a phone.
 *
 * Precedence, highest first:
 *
 *   1. Sec-CH-UA-Mobile '?1'                        -> phone.
 *   2. Sec-CH-UA-Mobile '?0'                        -> desktop, authoritative,
 *      the user agent is not consulted at all.
 *   3. hint absent AND isMobile() AND NOT isTablet() -> phone.
 *   4. everything else                               -> desktop, because
 *      desktop is the page that exists today.
 *
 * Sec-CH-UA-Mobile is Chromium-and-secure-context only. Measured 2026-09-07:
 * absent on http://nc.test for every client, and present as ?1 / ?0 on
 * https://nc.dev.kingdom.lv. iOS never sends it, so leg 3 is the whole iPhone
 * audience's path rather than a fallback.
 *
 * Both inputs are attacker-controlled headers, so this class chooses a LAYOUT
 * and nothing else. It must never gate pricing, stock, session or
 * authorisation: a spoofed hint buys the phone layout of a public page and no
 * data the client could not already fetch.
 */
class DeviceHint
{
    /**
     * Per-request memo of the answer. Safe as a plain static because this
     * project runs PHP-FPM, not Octane, so statics reinitialise per request.
     * @var bool|null
     */
    protected static ?bool $bIsMobile = null;

    /**
     * Per-request flag, same PHP-FPM lifetime as the memo above.
     * @var bool
     */
    protected static bool $bWasConsulted = false;

    /**
     * Pure: no request, no container, no superglobal. The DEVICE-03 truth
     * table drives this method directly.
     *
     * @param string|null $sUserAgent  User-Agent header value, passed in rather
     *                                 than read out of the request so the rule
     *                                 has no hidden inputs
     * @param string|null $sMobileHint Sec-CH-UA-Mobile header value, '?1', '?0'
     *                                 or null when the client sends none
     *
     * @return bool true when the request is a phone
     */
    public static function resolve(?string $sUserAgent, ?string $sMobileHint): bool
    {
        if ($sMobileHint === '?1') {
            return true;
        }
        if ($sMobileHint === '?0') {
            return false; // authoritative: stop, do not consult the user agent
        }

        try {
            // Auto-init off: it copies WAP and Accept keys out of $_SERVER and
            // isMobile() answers on those before it reads the user agent, which
            // is not the request this method was handed
            $obDetect = new MobileDetect(null, ['autoInitOfHttpHeaders' => false]);
            $obDetect->setUserAgent((string) $sUserAgent);

            return $obDetect->isMobile() && !$obDetect->isTablet();
        } catch (\Throwable $obException) {
            return false; // desktop is the page that exists today
        }
    }

    /**
     * Reads the two headers off the current request and memoizes the answer,
     * so one detection runs however many callers ask.
     *
     * @return bool true when the request is a phone
     */
    public static function isMobile(): bool
    {
        self::$bWasConsulted = true;

        if (self::$bIsMobile !== null) {
            return self::$bIsMobile;
        }

        self::$bIsMobile = self::resolve(
            (string) request()->header('User-Agent', ''),
            request()->header('Sec-CH-UA-Mobile')
        );

        return self::$bIsMobile;
    }

    /**
     * Read by the DeviceVaryHeader middleware, which uses it to keep the
     * device headers off every page the branch did not touch.
     *
     * @return bool true when isMobile() ran on this request
     */
    public static function wasConsulted(): bool
    {
        return self::$bWasConsulted;
    }
}
