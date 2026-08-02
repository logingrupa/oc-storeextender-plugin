<?php namespace Logingrupa\StoreExtender\Tests\Unit\Vite;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Logingrupa\StoreExtender\Classes\Helper\ViteAssetHelper;

/**
 * Covers the pure static rendering methods of ViteAssetHelper. Manifest
 * fixtures mirror the real Vite output at assets/build/.vite/manifest.json.
 */
class ViteAssetHelperTest extends TestCase
{
    const BUILD_BASE_URL = '/themes/logingrupa-naisstore/assets/build';

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function getManifestFixture(): array
    {
        return [
            'src/entries/core.js' => [
                'file' => 'assets/core-CsgKC65g.js',
                'name' => 'core',
                'src' => 'src/entries/core.js',
                'isEntry' => true,
                'css' => ['assets/core-x1XGuNl0.css'],
            ],
            'src/entries/product.js' => [
                'file' => 'assets/product-B8h6LW-X.js',
                'name' => 'product',
                'src' => 'src/entries/product.js',
                'isEntry' => true,
                'imports' => ['_shared-Ab12Cd34.js'],
                'css' => ['assets/product-Ef56Gh78.css'],
            ],
            '_shared-Ab12Cd34.js' => [
                'file' => 'assets/shared-Ab12Cd34.js',
                'css' => ['assets/shared-Ij90Kl12.css'],
            ],
        ];
    }

    public function testBuildEntryHtmlRendersModuleScriptTag()
    {
        $sHtml = ViteAssetHelper::buildEntryHtml($this->getManifestFixture(), 'src/entries/core.js', self::BUILD_BASE_URL);

        $this->assertStringContainsString(
            '<script type="module" src="'.self::BUILD_BASE_URL.'/assets/core-CsgKC65g.js"></script>',
            $sHtml
        );
    }

    public function testBuildEntryHtmlRendersStylesheetLink()
    {
        $sHtml = ViteAssetHelper::buildEntryHtml($this->getManifestFixture(), 'src/entries/core.js', self::BUILD_BASE_URL);

        $this->assertStringContainsString(
            '<link rel="stylesheet" href="'.self::BUILD_BASE_URL.'/assets/core-x1XGuNl0.css">',
            $sHtml
        );
    }

    public function testBuildEntryHtmlRendersImportedChunkAsModulePreloadWithItsCss()
    {
        $sHtml = ViteAssetHelper::buildEntryHtml($this->getManifestFixture(), 'src/entries/product.js', self::BUILD_BASE_URL);

        $this->assertStringContainsString(
            '<link rel="modulepreload" href="'.self::BUILD_BASE_URL.'/assets/shared-Ab12Cd34.js">',
            $sHtml
        );
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="'.self::BUILD_BASE_URL.'/assets/shared-Ij90Kl12.css">',
            $sHtml
        );
        $this->assertStringContainsString(
            '<link rel="stylesheet" href="'.self::BUILD_BASE_URL.'/assets/product-Ef56Gh78.css">',
            $sHtml
        );
    }

    public function testBuildEntryHtmlStylesheetsComeBeforeTheModuleScript()
    {
        $sHtml = ViteAssetHelper::buildEntryHtml($this->getManifestFixture(), 'src/entries/product.js', self::BUILD_BASE_URL);

        $iStylesheetPosition = strpos($sHtml, 'rel="stylesheet"');
        $iScriptPosition = strpos($sHtml, '<script type="module"');
        $this->assertNotFalse($iStylesheetPosition);
        $this->assertNotFalse($iScriptPosition);
        $this->assertLessThan($iScriptPosition, $iStylesheetPosition);
    }

    public function testBuildEntryHtmlDeduplicatesSharedCssAcrossChunks()
    {
        $arManifest = $this->getManifestFixture();
        $arManifest['src/entries/product.js']['css'] = ['assets/shared-Ij90Kl12.css'];

        $sHtml = ViteAssetHelper::buildEntryHtml($arManifest, 'src/entries/product.js', self::BUILD_BASE_URL);

        $this->assertSame(1, substr_count($sHtml, 'assets/shared-Ij90Kl12.css'));
    }

    public function testBuildEntryHtmlThrowsForMissingEntry()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('src/entries/checkout.js');

        ViteAssetHelper::buildEntryHtml($this->getManifestFixture(), 'src/entries/checkout.js', self::BUILD_BASE_URL);
    }

    public function testBuildEntryHtmlThrowsForEmptyManifest()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest is empty');

        ViteAssetHelper::buildEntryHtml([], 'src/entries/core.js', self::BUILD_BASE_URL);
    }

    public function testBuildDevServerHtmlRendersViteClientAndEntryModule()
    {
        $sHtml = ViteAssetHelper::buildDevServerHtml('http://localhost:5173', 'src/entries/core.js');

        $this->assertStringContainsString(
            '<script type="module" src="http://localhost:5173/@vite/client"></script>',
            $sHtml
        );
        $this->assertStringContainsString(
            '<script type="module" src="http://localhost:5173/src/entries/core.js"></script>',
            $sHtml
        );
    }

    public function testBuildDevServerHtmlThrowsForEmptyDevServerUrl()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hot file exists but is empty');

        ViteAssetHelper::buildDevServerHtml('', 'src/entries/core.js');
    }
}
