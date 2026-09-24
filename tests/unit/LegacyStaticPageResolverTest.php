<?php

require_once __DIR__ . '/../../classes/helper/LegacyStaticPageResolver.php';

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\LegacyStaticPageResolver;

/**
 * A static page asked for by another locale's URL resolves to its URL in the
 * requesting locale, and only then: a path no page owns, or the page's own
 * URL in that locale, resolves to nothing so the 404 stands.
 */
class LegacyStaticPageResolverTest extends TestCase
{
    /** @var LegacyStaticPageResolver */
    protected $obResolver;

    /** @var array<int, array<string, string>> */
    protected $arPageUrlMaps;

    public function setUp(): void
    {
        $this->obResolver = new LegacyStaticPageResolver();
        $this->arPageUrlMaps = [
            ['base' => '/palidziba/kontakti', 'ru' => '/pomosh/kontakty', 'en' => '/help/contacts', 'nb-no' => '/hjelp/kontakt'],
            ['base' => '/palidziba', 'ru' => '/pomosh', 'en' => '/help', 'nb-no' => '/hjelp'],
            ['base' => '/vakances'],
        ];
    }

    public function testBaseUrlAskedInAnotherLocaleResolvesToThatLocaleUrl(): void
    {
        $this->assertSame('/pomosh/kontakty', $this->obResolver->resolve('/palidziba/kontakti', 'ru', $this->arPageUrlMaps));
        $this->assertSame('/hjelp/kontakt', $this->obResolver->resolve('/palidziba/kontakti', 'nb-no', $this->arPageUrlMaps));
        $this->assertSame('/help', $this->obResolver->resolve('/pomosh', 'en', $this->arPageUrlMaps));
    }

    public function testOwnUrlResolvesToNothing(): void
    {
        $this->assertNull($this->obResolver->resolve('/pomosh/kontakty', 'ru', $this->arPageUrlMaps));
        $this->assertNull($this->obResolver->resolve('/palidziba/kontakti', 'lv', $this->arPageUrlMaps));
    }

    public function testLocaleWithoutItsOwnUrlFallsBackToBase(): void
    {
        $this->assertSame('/palidziba/kontakti', $this->obResolver->resolve('/help/contacts', 'lv', $this->arPageUrlMaps));
        $this->assertNull($this->obResolver->resolve('/vakances', 'ru', $this->arPageUrlMaps));
    }

    public function testUnknownPathResolvesToNothing(): void
    {
        $this->assertNull($this->obResolver->resolve('/p/some-product', 'ru', $this->arPageUrlMaps));
        $this->assertNull($this->obResolver->resolve('/palidziba/kontakti/extra', 'ru', $this->arPageUrlMaps));
    }

    public function testPathWithoutLeadingSlashIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->obResolver->resolve('palidziba/kontakti', 'ru', $this->arPageUrlMaps);
    }

    public function testStripPrefix(): void
    {
        $this->assertSame('/palidziba/kontakti', LegacyStaticPageResolver::stripPrefix('ru/palidziba/kontakti', '/ru'));
        $this->assertSame('/palidziba/kontakti', LegacyStaticPageResolver::stripPrefix('/palidziba/kontakti/', ''));
        $this->assertSame('/', LegacyStaticPageResolver::stripPrefix('/ru', 'ru'));
        $this->assertSame('/rus/x', LegacyStaticPageResolver::stripPrefix('/rus/x', '/ru'));
        $this->assertSame('/palidziba', LegacyStaticPageResolver::stripPrefix('/palidziba', '/'));
    }

    public function testNormalizeUrl(): void
    {
        $this->assertSame('/help/contacts', LegacyStaticPageResolver::normalizeUrl('help/contacts'));
        $this->assertSame('/help/contacts', LegacyStaticPageResolver::normalizeUrl('/help/contacts/'));
        $this->assertSame('/', LegacyStaticPageResolver::normalizeUrl(''));
    }
}
