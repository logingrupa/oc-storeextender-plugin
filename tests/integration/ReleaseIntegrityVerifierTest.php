<?php

declare(strict_types=1);

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Cms\Classes\ComponentManager;
use Cms\Classes\Theme;
use Logingrupa\StoreExtender\Classes\Deploy\ReleaseIntegrityVerifier;
use Logingrupa\StoreExtender\Classes\Twig\OptionalTwigFunctionFallback;
use October\Rain\Filesystem\Filesystem;
use October\Rain\Halcyon\Datasource\FileDatasource;
use System\Classes\MarkupManager;
use System\Classes\PluginManager;

class ReleaseIntegrityVerifierTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    private string $sFixtureDir = '';

    public function setUp(): void
    {
        parent::setUp();

        OptionalTwigFunctionFallback::resetRequestState();
        $this->sFixtureDir = sys_get_temp_dir().'/storeextender-release-verify-'.bin2hex(random_bytes(6));
        $this->buildHealthyFixture($this->sFixtureDir);
    }

    public function tearDown(): void
    {
        $this->removeDirectory($this->sFixtureDir);
        OptionalTwigFunctionFallback::resetRequestState();

        parent::tearDown();
    }

    public function testHealthyFixturePassesAllChecksAndRespectsIntentionallyDisabledPlugins(): void
    {
        $arResult = $this->makeVerifier()->verify();

        $this->assertTrue($arResult['ok'], implode("\n", $arResult['errors']));
        $this->assertSame(3, $arResult['stats']['plugins']);
        $this->assertSame(6, $arResult['stats']['templates']);
        $this->assertSame(2, $arResult['stats']['assets']);
    }

    public function testFailsOnLockVersusInstalledDrift(): void
    {
        $sInstalledPath = $this->sFixtureDir.'/vendor/composer/installed.json';
        $arInstalled = json_decode((string) file_get_contents($sInstalledPath), true, 512, JSON_THROW_ON_ERROR);
        $arInstalled['packages'][0]['source']['reference'] = '0000000000000000000000000000000000000000';
        file_put_contents($sInstalledPath, json_encode($arInstalled, JSON_PRETTY_PRINT));

        $arResult = $this->makeVerifier()->verify();

        $this->assertFalse($arResult['ok']);
        $this->assertStringContainsString('reference drift', implode("\n", $arResult['errors']));
    }

    public function testFailsWhenLockedPluginPhpIsMissingOnDisk(): void
    {
        unlink($this->sFixtureDir.'/plugins/logingrupa/googleanalytics/Plugin.php');

        $arResult = $this->makeVerifier()->verify();

        $this->assertFalse($arResult['ok']);
        $this->assertStringContainsString('missing a readable Plugin.php', implode("\n", $arResult['errors']));
    }

    public function testFailsWhenRequiredAnalyticsPluginIsDisabled(): void
    {
        $arResult = $this->makeVerifier(
            arDisabledPlugins: ['Logingrupa.GoogleAnalytics'],
        )->verify();

        $this->assertFalse($arResult['ok']);
        $this->assertStringContainsString('Required plugin Logingrupa.GoogleAnalytics is missing or disabled.', implode("\n", $arResult['errors']));
    }

    public function testStrictModeCatchesMissingGaEventEvenWithFallbackRegistered(): void
    {
        $obMarkup = new MarkupManager;
        $obMarkup->registerFunctions([
            'gaListBlock' => [self::class, 'stubGaListBlock', false],
        ]);

        // Replace MarkupManager singleton briefly so System\Twig\Extension loads the stubbed set without gaEvent.
        $obOriginalMarkup = $this->app->make('system.markup');
        $this->app->instance('system.markup', $obMarkup);

        try {
            $arResult = $this->makeVerifier(obMarkupManager: $obMarkup)->verify();
        } finally {
            $this->app->instance('system.markup', $obOriginalMarkup);
        }

        $this->assertFalse($arResult['ok']);
        $sErrors = implode("\n", $arResult['errors']);
        $this->assertStringContainsString('Required Twig function gaEvent() is not registered', $sErrors);
        $this->assertStringContainsString('Unknown "gaEvent" function', $sErrors);
    }

    public function testFailsOnMalformedIniHeaderWhileAllowingEmptyIniPhpPartials(): void
    {
        file_put_contents(
            $this->sFixtureDir.'/themes/test-theme/partials/broken.htm',
            "[unclosed\ntitle = \"broken\n==\n<div>broken</div>\n"
        );

        $arResult = $this->makeVerifier()->verify();

        $this->assertFalse($arResult['ok']);
        $sErrors = implode("\n", $arResult['errors']);
        $this->assertStringContainsString('Malformed INI header in template: partials/broken.htm', $sErrors);
        $this->assertStringNotContainsString('partials/empty-ini-php.htm', $sErrors);
    }

    public function testFailsWhenMirroredPublicViteManifestOrReferencedAssetIsMissing(): void
    {
        unlink($this->sFixtureDir.'/public/themes/test-theme/assets/build/assets/core-abc123.css');

        $arMissingAsset = $this->makeVerifier()->verify();
        $this->assertFalse($arMissingAsset['ok']);
        $this->assertStringContainsString('Mirrored Vite asset missing: assets/core-abc123.css', implode("\n", $arMissingAsset['errors']));

        unlink($this->sFixtureDir.'/public/themes/test-theme/assets/build/.vite/manifest.json');

        $arMissingManifest = $this->makeVerifier()->verify();
        $this->assertFalse($arMissingManifest['ok']);
        $this->assertStringContainsString('Missing or unreadable public Vite manifest.json', implode("\n", $arMissingManifest['errors']));
    }

    /**
     * @param  list<string>  $arDisabledPlugins
     */
    private function makeVerifier(array $arDisabledPlugins = ['Lovata.Buddies'], ?MarkupManager $obMarkupManager = null): ReleaseIntegrityVerifier
    {
        $obPluginManager = Mockery::mock(PluginManager::class);
        $obPluginManager->shouldReceive('findMissingDependencies')->andReturn([]);
        $obPluginManager->shouldReceive('hasPlugin')->andReturn(true);
        $obPluginManager->shouldReceive('exists')->andReturnUsing(
            static fn (string $sCode): bool => ! in_array($sCode, $arDisabledPlugins, true)
        );

        $obComponentManager = Mockery::mock(ComponentManager::class);
        $obComponentManager->shouldReceive('hasComponent')->andReturn(true);

        if ($obMarkupManager === null) {
            $obMarkupManager = new MarkupManager;
            $obMarkupManager->registerFunctions([
                'gaEvent' => [self::class, 'stubGaEvent'],
                'gaListBlock' => [self::class, 'stubGaListBlock', false],
            ]);
            $this->app->instance('system.markup', $obMarkupManager);
        }

        $sThemePath = $this->sFixtureDir.'/themes/test-theme';
        $this->app->make('halcyon')->addDatasource(
            'test-theme',
            new FileDatasource($sThemePath, new Filesystem)
        );

        $obTheme = new class($sThemePath) extends Theme
        {
            public function __construct(private readonly string $sCustomPath)
            {
                $this->dirName = 'test-theme';
            }

            public function getPath(?string $dirName = null): string
            {
                return $this->sCustomPath;
            }
        };

        return new ReleaseIntegrityVerifier(
            sBasePath: $this->sFixtureDir,
            obPluginManager: $obPluginManager,
            obComponentManager: $obComponentManager,
            obMarkupManager: $obMarkupManager,
            obTheme: $obTheme,
            bCheckPublicMirror: true,
        );
    }

    public static function stubGaEvent(mixed ...$arArgs): string
    {
        return '';
    }

    public static function stubGaListBlock(mixed ...$arArgs): string
    {
        return '';
    }

    private function buildHealthyFixture(string $sRoot): void
    {
        $arDirs = [
            'vendor/composer',
            'plugins/logingrupa/googleanalytics',
            'plugins/logingrupa/metapixel',
            'plugins/logingrupa/storeextender',
            'themes/test-theme/layouts',
            'themes/test-theme/pages',
            'themes/test-theme/partials',
            'public/themes/test-theme/assets/build/.vite',
            'public/themes/test-theme/assets/build/assets',
        ];

        foreach ($arDirs as $sDir) {
            mkdir($sRoot.'/'.$sDir, 0777, true);
        }

        file_put_contents($sRoot.'/plugins/logingrupa/googleanalytics/Plugin.php', "<?php\nnamespace Logingrupa\\GoogleAnalytics;\n");
        file_put_contents($sRoot.'/plugins/logingrupa/metapixel/Plugin.php', "<?php\nnamespace Logingrupa\\Metapixel;\n");
        file_put_contents($sRoot.'/plugins/logingrupa/storeextender/Plugin.php', "<?php namespace Logingrupa\\StoreExtender;\n");

        $arPackages = [
            [
                'name' => 'logingrupa/oc-googleanalytics-plugin',
                'version' => 'v1.0.4',
                'type' => 'october-plugin',
                'source' => ['type' => 'git', 'reference' => 'eff9746345e34f974b5f0618d60a2ed4435fae18'],
                'install-path' => '../../plugins/logingrupa/googleanalytics',
            ],
            [
                'name' => 'logingrupa/metapixel-plugin',
                'version' => 'v2.0.5',
                'type' => 'october-plugin',
                'source' => ['type' => 'git', 'reference' => '6d6474d111111111111111111111111111111111'],
                'install-path' => '../../plugins/logingrupa/metapixel',
            ],
            [
                'name' => 'logingrupa/oc-storeextender-plugin',
                'version' => 'dev-master',
                'type' => 'october-plugin',
                'source' => ['type' => 'git', 'reference' => 'c81445e9c3d9de338ff61bfe9e1fe8c4cdc87f5e'],
                'install-path' => '../../plugins/logingrupa/storeextender',
            ],
        ];

        file_put_contents($sRoot.'/composer.lock', json_encode(['packages' => $arPackages], JSON_PRETTY_PRINT));
        file_put_contents($sRoot.'/vendor/composer/installed.json', json_encode(['packages' => $arPackages], JSON_PRETTY_PRINT));

        file_put_contents($sRoot.'/themes/test-theme/layouts/shop.htm', "description = \"Shop\"\n==\n<html>{% page %}</html>\n");
        file_put_contents($sRoot.'/themes/test-theme/layouts/shop-lean.htm', "description = \"Shop Lean\"\n==\n<html>{% page %}</html>\n");
        file_put_contents($sRoot.'/themes/test-theme/pages/checkout.htm', "url = \"/checkout\"\n==\n{{ gaEvent('begin_checkout', 'cart') }}\n");
        file_put_contents($sRoot.'/themes/test-theme/pages/product.htm', "url = \"/p/:slug\"\n==\n{{ gaEvent('view_item', [{offer: 1}]) }}\n");
        file_put_contents($sRoot.'/themes/test-theme/partials/grid.htm', "{{ gaListBlock('view_item_list', [{offer: 1}], {item_list_id: 'cat'}) }}\n");
        file_put_contents($sRoot.'/themes/test-theme/partials/empty-ini-php.htm', "==\nfunction onStart() {}\n==\n<div>ok</div>\n");

        file_put_contents($sRoot.'/public/index.php', "<?php\n");
        file_put_contents($sRoot.'/public/themes/test-theme/assets/build/assets/core-abc123.js', "console.log('ok');\n");
        file_put_contents($sRoot.'/public/themes/test-theme/assets/build/assets/core-abc123.css', "body{}\n");
        file_put_contents(
            $sRoot.'/public/themes/test-theme/assets/build/.vite/manifest.json',
            json_encode([
                'src/entries/core.js' => [
                    'file' => 'assets/core-abc123.js',
                    'css' => ['assets/core-abc123.css'],
                ],
            ], JSON_PRETTY_PRINT)
        );
    }

    private function removeDirectory(string $sDir): void
    {
        if ($sDir === '' || ! is_dir($sDir)) {
            return;
        }

        $obIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($obIterator as $obEntry) {
            if ($obEntry->isDir()) {
                rmdir($obEntry->getPathname());
            } else {
                unlink($obEntry->getPathname());
            }
        }

        rmdir($sDir);
    }
}
