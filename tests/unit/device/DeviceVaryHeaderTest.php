<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Tests\Unit\Device;

use PHPUnit\Framework\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Logingrupa\StoreExtender\Classes\Middleware\DeviceVaryHeader;

/**
 * A device-branched response must tell shared caches and crawlers that its
 * body depends on the client: a CDN or proxy that hands the phone layout to a
 * desktop is a cross-user content mix-up. A response the branch never touched
 * must stay byte-identical, so both headers are gated on one flag.
 *
 * DeviceHint is stubbed via subclasses: its per-request memo has no reset by
 * design, and a plain unit test boots no application to read a request from.
 */
class DeviceVaryHeaderTest extends TestCase
{
    /**
     * @var string The pinned value as it reaches the wire. The middleware
     * sets private, max-age=0, must-revalidate; ResponseHeaderBag parses
     * Cache-Control into directives and re-emits them sorted, so equality is
     * asserted against the sorted form. Symfony pins no directive of its own
     * and falls back to no-cache, private, which is why this is an equality
     * assertion rather than a check that a header appeared.
     */
    const PINNED_CACHE_CONTROL = 'max-age=0, must-revalidate, private';

    /**
     * @param DeviceVaryHeader $obMiddleware
     * @param bool             $bSeedExistingVary seed a Vary set by another layer
     * @return Response
     */
    protected function runMiddleware(DeviceVaryHeader $obMiddleware, bool $bSeedExistingVary): Response
    {
        $obRequest = Request::create('/lv/p2/some-slug/6405');

        return $obMiddleware->handle($obRequest, function () use ($bSeedExistingVary) {
            $obResponse = new Response('ok');
            if ($bSeedExistingVary) {
                $obResponse->setVary(['Accept-Encoding']);
            }

            return $obResponse;
        });
    }

    /**
     * Appending leaves several Vary entries in the header bag, so every entry
     * is read and split rather than only the first.
     * @param Response $obResponse
     * @return array<string>
     */
    protected function getVaryValueList(Response $obResponse): array
    {
        $arValueList = [];
        foreach ($obResponse->headers->all('Vary') as $sHeaderValue) {
            foreach (explode(',', $sHeaderValue) as $sValue) {
                $arValueList[] = trim($sValue);
            }
        }

        return $arValueList;
    }

    public function testConsultedResponseCarriesTheDeviceVaryHeader()
    {
        $arValueList = $this->getVaryValueList(
            $this->runMiddleware(new ConsultedDeviceVaryHeaderStub(), false)
        );

        $this->assertContains('Sec-CH-UA-Mobile', $arValueList);
        $this->assertContains('User-Agent', $arValueList);
    }

    public function testConsultedResponseAppendsToAnExistingVary()
    {
        $arValueList = $this->getVaryValueList(
            $this->runMiddleware(new ConsultedDeviceVaryHeaderStub(), true)
        );

        $this->assertContains('Accept-Encoding', $arValueList);
        $this->assertContains('Sec-CH-UA-Mobile', $arValueList);
        $this->assertContains('User-Agent', $arValueList);
    }

    public function testConsultedResponsePinsCacheControl()
    {
        $obResponse = $this->runMiddleware(new ConsultedDeviceVaryHeaderStub(), false);

        $this->assertSame(self::PINNED_CACHE_CONTROL, $obResponse->headers->get('Cache-Control'));
    }

    public function testUnconsultedResponseCarriesNoDeviceHeaders()
    {
        $obResponse = $this->runMiddleware(new UnconsultedDeviceVaryHeaderStub(), false);

        $this->assertSame([], $this->getVaryValueList($obResponse));
        $this->assertNotSame(self::PINNED_CACHE_CONTROL, $obResponse->headers->get('Cache-Control'));
    }

    public function testHandleReturnsTheResponseUnchangedInIdentity()
    {
        $obNextResponse = new Response('ok');

        $obReturned = (new ConsultedDeviceVaryHeaderStub())->handle(
            Request::create('/lv/p2/some-slug/6405'),
            function () use ($obNextResponse) {
                return $obNextResponse;
            }
        );

        $this->assertSame($obNextResponse, $obReturned);
    }
}

/**
 * DeviceHint stub: the device branch ran on this request.
 */
class ConsultedDeviceVaryHeaderStub extends DeviceVaryHeader
{
    protected function wasConsulted(): bool
    {
        return true;
    }
}

/**
 * DeviceHint stub: the device branch never ran on this request.
 */
class UnconsultedDeviceVaryHeaderStub extends DeviceVaryHeader
{
    protected function wasConsulted(): bool
    {
        return false;
    }
}
