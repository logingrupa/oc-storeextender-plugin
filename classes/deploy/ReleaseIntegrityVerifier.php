<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Deploy;

use Cms\Classes\ComponentManager;
use Cms\Classes\Layout;
use Cms\Classes\Page;
use Cms\Classes\Partial;
use Cms\Classes\Theme;
use Cms\Twig\DebugExtension;
use Cms\Twig\Extension as CmsTwigExtension;
use Cms\Twig\Loader as CmsTwigLoader;
use FilesystemIterator;
use JsonException;
use Logingrupa\StoreExtender\Classes\Twig\OptionalTwigFunctionFallback;
use October\Rain\Halcyon\Processors\SectionParser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use System\Classes\MarkupManager;
use System\Classes\PluginManager;
use System\Twig\Extension as SystemTwigExtension;
use System\Twig\SecurityPolicy as TwigSecurityPolicy;
use Throwable;
use Twig\Environment as TwigEnvironment;

/**
 * Verifies a candidate release before symlink activation: Composer plugin parity,
 * required plugin/component/markup registration, strict CMS Twig compilation, and
 * mirrored public Vite artifacts.
 */
class ReleaseIntegrityVerifier
{
    /** @var list<string> */
    public const CORE_REQUIRED_PLUGINS = [
        'Logingrupa.StoreExtender',
        'Logingrupa.Metapixel',
    ];

    /** @var list<string> */
    public const REQUIRED_COMPONENTS = [
        'googleTag',
        'consentBanner',
        'pixelHead',
    ];

    /** @var list<string> */
    public const CRITICAL_TEMPLATES = [
        'layouts/shop.htm',
        'layouts/shop-lean.htm',
        'pages/checkout.htm',
        'pages/product.htm',
    ];

    /** @var array<string, class-string> */
    private const TEMPLATE_TYPES = [
        'layouts' => Layout::class,
        'pages' => Page::class,
        'partials' => Partial::class,
    ];

    public function __construct(
        private readonly ?string $sBasePath = null,
        private readonly ?PluginManager $obPluginManager = null,
        private readonly ?ComponentManager $obComponentManager = null,
        private readonly ?MarkupManager $obMarkupManager = null,
        private readonly ?Theme $obTheme = null,
        private readonly bool $bCheckPublicMirror = true,
    ) {}

    /**
     * @return array{ok: bool, errors: list<string>, stats: array{plugins: int, templates: int, assets: int}}
     */
    public function verify(): array
    {
        $arErrors = [];

        $iPlugins = $this->verifyComposerPlugins($arErrors);
        $this->verifyPluginsComponentsAndMarkup($arErrors);
        $iTemplates = $this->verifyThemeTemplates($arErrors);
        $iAssets = $this->bCheckPublicMirror ? $this->verifyMirroredPublicArtifacts($arErrors) : 0;

        return [
            'ok' => $arErrors === [],
            'errors' => $arErrors,
            'stats' => [
                'plugins' => $iPlugins,
                'templates' => $iTemplates,
                'assets' => $iAssets,
            ],
        ];
    }

    /**
     * @param  list<string>  $arErrors
     */
    private function verifyComposerPlugins(array &$arErrors): int
    {
        $arLock = $this->readJsonFile($this->path('composer.lock'), 'composer.lock', $arErrors);
        $arInstalled = $this->readJsonFile($this->path('vendor/composer/installed.json'), 'vendor/composer/installed.json', $arErrors);

        if ($arLock === null || $arInstalled === null) {
            return 0;
        }

        if (! isset($arLock['packages']) || ! is_array($arLock['packages'])) {
            $arErrors[] = 'composer.lock is missing a valid packages array.';

            return 0;
        }

        if (! isset($arInstalled['packages']) || ! is_array($arInstalled['packages'])) {
            $arErrors[] = 'vendor/composer/installed.json is missing a valid packages array.';

            return 0;
        }

        $arInstalledByName = [];
        foreach ($arInstalled['packages'] as $arPkg) {
            if (is_array($arPkg) && is_string($arPkg['name'] ?? null)) {
                $arInstalledByName[$arPkg['name']] = $arPkg;
            }
        }

        $obPluginManager = $this->pluginManager();
        $iChecked = 0;

        foreach ($arLock['packages'] as $arPkg) {
            if (! is_array($arPkg) || ($arPkg['type'] ?? null) !== 'october-plugin') {
                continue;
            }

            $sName = (string) ($arPkg['name'] ?? '');
            if ($sName === '') {
                $arErrors[] = 'Encountered unnamed october-plugin entry in composer.lock.';

                continue;
            }

            $iChecked++;

            if (! isset($arInstalledByName[$sName])) {
                $arErrors[] = sprintf('Plugin %s is in composer.lock but missing from installed.json.', $sName);

                continue;
            }

            $arInstPkg = $arInstalledByName[$sName];
            $sLockVersion = (string) ($arPkg['version'] ?? '');
            $sInstVersion = (string) ($arInstPkg['version'] ?? '');
            if ($sLockVersion !== $sInstVersion) {
                $arErrors[] = sprintf('Plugin %s version drift: lock=%s, installed=%s.', $sName, $sLockVersion, $sInstVersion);
            }

            $sLockRef = $this->packageReference($arPkg);
            $sInstRef = $this->packageReference($arInstPkg);
            if ($sLockRef !== '' && $sLockRef !== $sInstRef) {
                $arErrors[] = sprintf('Plugin %s reference drift: lock=%s, installed=%s.', $sName, $sLockRef, $sInstRef);
            }

            $sInstallPath = (string) ($arInstPkg['install-path'] ?? '');
            $sPluginDir = $sInstallPath !== '' ? realpath($this->path('vendor/composer/'.$sInstallPath)) : false;
            $sPluginFile = $sPluginDir !== false ? $sPluginDir.DIRECTORY_SEPARATOR.'Plugin.php' : '';

            if ($sPluginFile === '' || ! is_file($sPluginFile) || ! is_readable($sPluginFile)) {
                $arErrors[] = sprintf('Plugin %s is missing a readable Plugin.php at %s.', $sName, $sInstallPath ?: '(empty)');

                continue;
            }

            $sCode = $this->extractPluginCode($sPluginFile);
            if ($sCode === null) {
                $arErrors[] = sprintf('Could not determine October plugin namespace from %s.', $sPluginFile);

                continue;
            }

            if (! $obPluginManager->hasPlugin($sCode)) {
                $arErrors[] = sprintf('Plugin %s (%s) is on disk but not discovered by PluginManager.', $sName, $sCode);
            }
        }

        if ($iChecked === 0) {
            $arErrors[] = 'composer.lock contains zero non-dev october-plugin packages.';
        }

        return $iChecked;
    }

    /**
     * @param  list<string>  $arErrors
     */
    private function verifyPluginsComponentsAndMarkup(array &$arErrors): void
    {
        $obPluginManager = $this->pluginManager();

        $arMissingDeps = $obPluginManager->findMissingDependencies();
        if (is_array($arMissingDeps) && $arMissingDeps !== []) {
            $arErrors[] = 'Missing plugin dependencies: '.implode(', ', $arMissingDeps).'.';
        }

        foreach ($this->requiredPlugins() as $sPluginCode) {
            if (! $obPluginManager->exists($sPluginCode)) {
                $arErrors[] = sprintf('Required plugin %s is missing or disabled.', $sPluginCode);
            }
        }

        $obComponentManager = $this->componentManager();
        foreach (self::REQUIRED_COMPONENTS as $sAlias) {
            if (! $obComponentManager->hasComponent($sAlias)) {
                $arErrors[] = sprintf('Required CMS component [%s] is not registered.', $sAlias);
            }
        }

        $obMarkupManager = $this->markupManager();
        $arFunctions = $obMarkupManager->listFunctions();
        foreach (array_keys(OptionalTwigFunctionFallback::OPTIONAL_FUNCTIONS) as $sFunctionName) {
            if (! array_key_exists($sFunctionName, $arFunctions)) {
                $arErrors[] = sprintf('Required Twig function %s() is not registered in MarkupManager.', $sFunctionName);
            }
        }

        try {
            $obMarkupManager->makeTwigFunctions();
            $obMarkupManager->makeTwigFilters();
        } catch (Throwable $obException) {
            $arErrors[] = 'MarkupManager callable validation failed: '.$obException->getMessage();
        }
    }

    /**
     * @param  list<string>  $arErrors
     */
    private function verifyThemeTemplates(array &$arErrors): int
    {
        $obTheme = $this->theme();
        if (! $obTheme instanceof Theme) {
            $arErrors[] = 'Active CMS theme could not be resolved.';

            return 0;
        }

        $sThemePath = $obTheme->getPath();
        if (! is_dir($sThemePath)) {
            $arErrors[] = sprintf('Active theme directory does not exist: %s.', $sThemePath);

            return 0;
        }

        return OptionalTwigFunctionFallback::withStrictMode(function () use ($obTheme, $sThemePath, &$arErrors): int {
            $obLoader = new CmsTwigLoader;
            $obTwig = new TwigEnvironment($obLoader, [
                'cache' => false,
                'auto_reload' => true,
                'debug' => false,
                'strict_variables' => false,
            ]);

            CmsTwigExtension::addExtensionToTwig($obTwig);
            SystemTwigExtension::addExtensionToTwig($obTwig);
            TwigSecurityPolicy::addExtensionToTwig($obTwig);
            DebugExtension::addExtensionToTwig($obTwig);

            $iCompiled = 0;
            $arSeenTemplates = [];

            foreach (self::TEMPLATE_TYPES as $sSubdir => $sModelClass) {
                $sDir = $sThemePath.DIRECTORY_SEPARATOR.$sSubdir;
                if (! is_dir($sDir)) {
                    $arErrors[] = sprintf('Active theme is missing %s/ directory.', $sSubdir);

                    continue;
                }

                $obIterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($obIterator as $obFile) {
                    if (! $obFile->isFile() || $obFile->getExtension() !== 'htm') {
                        continue;
                    }

                    $sFilePath = $obFile->getPathname();
                    $sRelative = ltrim(str_replace('\\', '/', substr($sFilePath, strlen($sDir))), '/');
                    $sTemplateKey = $sSubdir.'/'.$sRelative;
                    $arSeenTemplates[$sTemplateKey] = true;

                    $sRaw = file_get_contents($sFilePath);
                    if ($sRaw === false) {
                        $arErrors[] = sprintf('Unreadable template file: %s.', $sTemplateKey);

                        continue;
                    }

                    $arParsed = SectionParser::parse($sRaw);
                    $arSettings = is_array($arParsed['settings'] ?? null) ? $arParsed['settings'] : [];
                    if ($this->hasMalformedIniHeader($arSettings)) {
                        $arErrors[] = sprintf('Malformed INI header in template: %s.', $sTemplateKey);

                        continue;
                    }

                    $obCmsObject = $sModelClass::load($obTheme, $sRelative);
                    if (! $obCmsObject) {
                        $arErrors[] = sprintf('CMS Halcyon loader returned null for template: %s.', $sTemplateKey);

                        continue;
                    }

                    $obLoader->setObject($obCmsObject);

                    try {
                        $obSource = $obLoader->getSourceContext($obCmsObject->getFilePath());
                        $obTwig->compileSource($obSource);
                        $iCompiled++;
                    } catch (Throwable $obException) {
                        $arErrors[] = sprintf('Twig compile error in %s: %s', $sTemplateKey, $obException->getMessage());
                    }
                }
            }

            if ($iCompiled === 0) {
                $arErrors[] = 'Zero CMS theme templates were compiled.';
            }

            foreach (self::CRITICAL_TEMPLATES as $sCritical) {
                if (! isset($arSeenTemplates[$sCritical])) {
                    $arErrors[] = sprintf('Critical theme template is missing: %s.', $sCritical);
                }
            }

            return $iCompiled;
        });
    }

    /**
     * @param  list<string>  $arErrors
     */
    private function verifyMirroredPublicArtifacts(array &$arErrors): int
    {
        $sPublicIndex = $this->path('public/index.php');
        if (! is_file($sPublicIndex) || ! is_readable($sPublicIndex)) {
            $arErrors[] = 'Mirrored public/index.php is missing or unreadable.';
        }

        $obTheme = $this->theme();
        if (! $obTheme instanceof Theme) {
            return 0;
        }

        $sThemeDir = $obTheme->getDirName();
        $sBuildDir = $this->path('public/themes/'.$sThemeDir.'/assets/build');
        $sManifestPath = $sBuildDir.'/.vite/manifest.json';

        $arManifest = $this->readJsonFile($sManifestPath, 'public Vite manifest.json', $arErrors);
        if ($arManifest === null) {
            return 0;
        }

        if ($arManifest === []) {
            $arErrors[] = 'Mirrored public Vite manifest.json is empty.';

            return 0;
        }

        $arAssetPaths = [];
        foreach ($arManifest as $arEntry) {
            if (! is_array($arEntry)) {
                continue;
            }
            if (is_string($arEntry['file'] ?? null) && $arEntry['file'] !== '') {
                $arAssetPaths[$arEntry['file']] = true;
            }
            foreach ((array) ($arEntry['css'] ?? []) as $sCssFile) {
                if (is_string($sCssFile) && $sCssFile !== '') {
                    $arAssetPaths[$sCssFile] = true;
                }
            }
        }

        if ($arAssetPaths === []) {
            $arErrors[] = 'Mirrored public Vite manifest.json references zero asset files.';

            return 0;
        }

        $iVerified = 0;
        foreach (array_keys($arAssetPaths) as $sAssetRel) {
            $sAssetFile = $sBuildDir.'/'.ltrim($sAssetRel, '/');
            if (! is_file($sAssetFile) || ! is_readable($sAssetFile)) {
                $arErrors[] = sprintf('Mirrored Vite asset missing: %s.', $sAssetRel);
            } else {
                $iVerified++;
            }
        }

        return $iVerified;
    }

    /**
     * SectionParser puts ERROR_INI => '' on valid PHP partials starting with "=="
     * (empty INI header). Only non-empty unparseable INI content is a header error.
     *
     * @param  array<string, mixed>  $arSettings
     */
    private function hasMalformedIniHeader(array $arSettings): bool
    {
        if (! array_key_exists(SectionParser::ERROR_INI, $arSettings)) {
            return false;
        }

        $sHeader = (string) $arSettings[SectionParser::ERROR_INI];
        $sWithoutHints = (string) preg_replace('/^\s*(?:##|;.*)?$/m', '', $sHeader);

        return trim($sWithoutHints) !== '';
    }

    /**
     * @return list<string>
     */
    private function requiredPlugins(): array
    {
        $arPlugins = self::CORE_REQUIRED_PLUGINS;
        foreach (OptionalTwigFunctionFallback::OPTIONAL_FUNCTIONS as $arConfig) {
            $arPlugins[] = $arConfig['plugin'];
        }

        return array_values(array_unique($arPlugins));
    }

    /**
     * @param  array<string, mixed>  $arPackage
     */
    private function packageReference(array $arPackage): string
    {
        return (string) ($arPackage['source']['reference'] ?? $arPackage['dist']['reference'] ?? '');
    }

    private function extractPluginCode(string $sPluginFile): ?string
    {
        $sSource = file_get_contents($sPluginFile);
        if ($sSource === false) {
            return null;
        }

        if (preg_match('/\bnamespace\s+([A-Za-z0-9_]+)\\\\([A-Za-z0-9_]+)\s*;/', $sSource, $arMatch) !== 1) {
            return null;
        }

        return $arMatch[1].'.'.$arMatch[2];
    }

    /**
     * @param  list<string>  $arErrors
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $sPath, string $sLabel, array &$arErrors): ?array
    {
        if (! is_file($sPath) || ! is_readable($sPath)) {
            $arErrors[] = sprintf('Missing or unreadable %s (%s).', $sLabel, $sPath);

            return null;
        }

        $sRaw = file_get_contents($sPath);
        if ($sRaw === false) {
            $arErrors[] = sprintf('Failed reading %s.', $sLabel);

            return null;
        }

        try {
            $mDecoded = json_decode($sRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $obException) {
            $arErrors[] = sprintf('Invalid JSON in %s: %s', $sLabel, $obException->getMessage());

            return null;
        }

        if (! is_array($mDecoded)) {
            $arErrors[] = sprintf('%s did not decode to a JSON object/array.', $sLabel);

            return null;
        }

        return $mDecoded;
    }

    private function path(string $sRelative): string
    {
        $sBase = rtrim($this->sBasePath ?? base_path(), '/\\');

        return $sBase.DIRECTORY_SEPARATOR.ltrim($sRelative, '/\\');
    }

    private function pluginManager(): PluginManager
    {
        return $this->obPluginManager ?? PluginManager::instance();
    }

    private function componentManager(): ComponentManager
    {
        return $this->obComponentManager ?? ComponentManager::instance();
    }

    private function markupManager(): MarkupManager
    {
        return $this->obMarkupManager ?? MarkupManager::instance();
    }

    private function theme(): ?Theme
    {
        return $this->obTheme ?? Theme::getActiveTheme();
    }
}
