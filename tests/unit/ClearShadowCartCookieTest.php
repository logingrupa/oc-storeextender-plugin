<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Logingrupa\StoreExtender\Classes\Middleware\ClearShadowCartCookie;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;

/**
 * A broken cart cookie makes CartProcessor mint a fresh cart per request:
 * adds succeed into throwaway carts while every read sees an empty one. The
 * middleware must expire the cookie at every ancestor path of the URL when
 * the raw header proves a shadow (duplicate name) or an unusable value
 * (stripped by decryption, or garbage) - and must not touch healthy
 * responses.
 *
 * Crypt is stubbed via subclasses: the facade needs the app container,
 * which plain unit tests do not boot.
 */
class ClearShadowCartCookieTest extends TestCase
{
    /**
     * @param ClearShadowCartCookie $obMiddleware
     * @param string                $sUri
     * @param string                $sCookieHeader raw Cookie header
     * @param string|null           $sBagValue     value PHP would surface (null = stripped/absent)
     * @return Response
     */
    protected function runMiddleware(
        ClearShadowCartCookie $obMiddleware,
        string $sUri,
        string $sCookieHeader,
        ?string $sBagValue
    ): Response {
        $obRequest = Request::create($sUri);
        $obRequest->headers->set('cookie', $sCookieHeader);
        if ($sBagValue !== null) {
            $obRequest->cookies->set(CartProcessor::COOKIE_NAME, $sBagValue);
        }

        return $obMiddleware->handle($obRequest, function () {
            return new Response('ok');
        });
    }

    /**
     * @param Response $obResponse
     * @return array<string> paths of expired cart cookies on the response
     */
    protected function getExpiredCookiePathList(Response $obResponse): array
    {
        $arPathList = [];
        foreach ($obResponse->headers->getCookies() as $obCookie) {
            if ($obCookie->getName() === CartProcessor::COOKIE_NAME && $obCookie->getExpiresTime() <= time()) {
                $arPathList[] = $obCookie->getPath();
            }
        }

        return $arPathList;
    }

    public function testNoCookieHeaderLeavesResponseUntouched()
    {
        $obResponse = $this->runMiddleware(new ClearShadowCartCookie(), '/lv', '', null);

        $this->assertSame([], $this->getExpiredCookiePathList($obResponse));
    }

    public function testHealthyNumericCookieLeavesResponseUntouched()
    {
        $obResponse = $this->runMiddleware(
            new ClearShadowCartCookie(),
            '/lv/p/gellaka-uvled-gel-polish-12m/6405',
            CartProcessor::COOKIE_NAME.'=12345; other=1',
            '12345'
        );

        $this->assertSame([], $this->getExpiredCookiePathList($obResponse));
    }

    public function testHealthyEncryptedCookieLeavesResponseUntouched()
    {
        $obResponse = $this->runMiddleware(
            new DecryptableCartCookieStub(),
            '/lv/p/gellaka-uvled-gel-polish-12m/6405',
            CartProcessor::COOKIE_NAME.'=eyJpdiI6encrypted; other=1',
            'eyJpdiI6encrypted'
        );

        $this->assertSame([], $this->getExpiredCookiePathList($obResponse));
    }

    public function testStrippedCookieExpiresEveryAncestorPath()
    {
        // the browser sent the cookie but decryption stripped it before PHP:
        // the bag is empty while the raw header still names it
        $obResponse = $this->runMiddleware(
            new ClearShadowCartCookie(),
            '/lv/p/gellaka-uvled-gel-polish-12m/6405',
            CartProcessor::COOKIE_NAME.'=stale-old-app-key-value',
            null
        );

        $this->assertSame(
            ['/lv', '/lv/p', '/lv/p/gellaka-uvled-gel-polish-12m', '/lv/p/gellaka-uvled-gel-polish-12m/6405'],
            $this->getExpiredCookiePathList($obResponse)
        );
    }

    public function testUndecryptableCookieExpiresEveryAncestorPath()
    {
        $obResponse = $this->runMiddleware(
            new UndecryptableCartCookieStub(),
            '/lv/p/slug',
            CartProcessor::COOKIE_NAME.'=stale-garbage-value',
            'stale-garbage-value'
        );

        $this->assertSame(['/lv', '/lv/p', '/lv/p/slug'], $this->getExpiredCookiePathList($obResponse));
    }

    public function testDuplicateCartCookieExpiresEveryAncestorPath()
    {
        $obResponse = $this->runMiddleware(
            new ClearShadowCartCookie(),
            '/lv',
            CartProcessor::COOKIE_NAME.'=999; '.CartProcessor::COOKIE_NAME.'=12345',
            '999'
        );

        $this->assertSame(['/lv'], $this->getExpiredCookiePathList($obResponse));
    }

    public function testBrokenCookieOnRootUrlExpiresNothing()
    {
        // path=/ holds the healthy copy CartProcessor re-sets itself; the
        // root URL has no ancestor directory a shadow could hide under
        $obResponse = $this->runMiddleware(
            new ClearShadowCartCookie(),
            '/',
            CartProcessor::COOKIE_NAME.'=a; '.CartProcessor::COOKIE_NAME.'=b',
            'a'
        );

        $this->assertSame([], $this->getExpiredCookiePathList($obResponse));
    }
}

/**
 * Crypt stub: the value decrypts to a cart id.
 */
class DecryptableCartCookieStub extends ClearShadowCartCookie
{
    protected function decryptsToCartId(string $sCookieValue): bool
    {
        return true;
    }
}

/**
 * Crypt stub: the value does not decrypt.
 */
class UndecryptableCartCookieStub extends ClearShadowCartCookie
{
    protected function decryptsToCartId(string $sCookieValue): bool
    {
        return false;
    }
}
