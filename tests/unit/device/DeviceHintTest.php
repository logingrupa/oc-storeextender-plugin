<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Tests\Unit\Device;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\DeviceHint;

/**
 * DEVICE-03: the measured truth table for DeviceHint::resolve().
 *
 * Every expectation below was measured against the installed
 * mobiledetect/mobiledetectlib 4.11.0, not copied from a published table.
 * The user agent strings are literals for the same reason: a shortened or
 * substituted string is a different input and proves nothing.
 *
 * The two hint rows pass '?1' / '?0' as fixture values rather than reading a
 * real header. Sec-CH-UA-Mobile is Chromium-and-secure-context only, so no
 * local origin on http://nc.test can send one (measured 2026-09-07).
 *
 * isMobile() and wasConsulted() are deliberately NOT covered here: they read
 * request() and memoize in a process-wide static with no reset by design, so
 * their lifecycle is asserted in one ordered integration test instead. Do not
 * add a static-poking test to this file.
 */
class DeviceHintTest extends TestCase
{
    const UA_IPHONE_SAFARI = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    const UA_ANDROID_PHONE = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36';
    const UA_FIREFOX_ANDROID = 'Mozilla/5.0 (Android 14; Mobile; rv:131.0) Gecko/131.0 Firefox/131.0';
    const UA_GOOGLEBOT_MOBILE = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    const UA_IPADOS_MACINTOSH = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15';
    const UA_IPAD_MOBILE = 'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    const UA_ANDROID_TABLET = 'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    const UA_X11_LINUX = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    const UA_GOOGLEBOT_DESKTOP = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/141.0.7390.76 Safari/537.36';
    const UA_DESKTOP_CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: bool}>
     *         [user agent, Sec-CH-UA-Mobile, expected phone]
     */
    public static function deviceTruthTableProvider(): array
    {
        return [
            // hint present and authoritative - the UA is not consulted at all
            'hint ?1 beats a desktop UA' => [self::UA_DESKTOP_CHROME, '?1', true],
            'hint ?0 beats a phone UA'   => [self::UA_IPHONE_SAFARI,  '?0', false],
            // hint absent (every iOS client, every Firefox, all of http://nc.test)
            'iPhone Safari'               => [self::UA_IPHONE_SAFARI,     null, true],
            'Android Chrome phone'        => [self::UA_ANDROID_PHONE,     null, true],
            'Firefox Android phone'       => [self::UA_FIREFOX_ANDROID,   null, true],
            'Googlebot Smartphone'        => [self::UA_GOOGLEBOT_MOBILE,  null, true],
            'iPad with desktop UA'        => [self::UA_IPADOS_MACINTOSH,  null, false],
            'iPad with mobile UA'         => [self::UA_IPAD_MOBILE,       null, false],
            'Android tablet'              => [self::UA_ANDROID_TABLET,    null, false],
            'Android tablet desktop mode' => [self::UA_X11_LINUX,         null, false],
            'Googlebot Desktop'           => [self::UA_GOOGLEBOT_DESKTOP, null, false],
            'Desktop Chrome'              => [self::UA_DESKTOP_CHROME,    null, false],
            // never 500
            'empty user agent'   => ['', null, false],
            'missing user agent' => [null, null, false],
            'curl uptime probe'  => ['curl/8.7.1', null, false],
        ];
    }

    /**
     * PHPUnit 12 reads test metadata from attributes only, so the provider is
     * wired with #[DataProvider] rather than a docblock annotation.
     *
     * @param string|null $sUserAgent
     * @param string|null $sMobileHint
     * @param bool        $bExpectedPhone
     */
    #[DataProvider('deviceTruthTableProvider')]
    public function testResolveNamesTheDeviceForEveryMeasuredClient($sUserAgent, $sMobileHint, $bExpectedPhone)
    {
        $this->assertSame($bExpectedPhone, DeviceHint::resolve($sUserAgent, $sMobileHint));
    }

    public function testResolveToleratesAnOversizedUserAgent()
    {
        $this->assertFalse(DeviceHint::resolve(str_repeat('A', 3000), null));
    }

    public function testResolveIgnoresAnUnrecognisedHintValue()
    {
        $this->assertTrue(DeviceHint::resolve(self::UA_IPHONE_SAFARI, '?2'));
    }
}
