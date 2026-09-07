<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cms\Classes\Theme;
use RuntimeException;

/**
 * Renders the HTML tags for a Vite entry built into the active theme.
 *
 * Twig usage (registered as the vite_entry() function in Plugin.php):
 *   {{ vite_entry('core')|raw }}
 *
 * Production: reads assets/build/.vite/manifest.json from the theme and
 * emits the hashed module script, its stylesheets and modulepreload links.
 * Development: when the Vite dev server writes assets/build/hot, tags are
 * pointed at the dev server instead (HMR).
 *
 * Fail-fast by design: a missing manifest or unknown entry throws instead
 * of silently rendering a page without its JavaScript.
 */
class ViteAssetHelper
{
    const BUILD_DIRECTORY = 'assets/build';
    const MANIFEST_RELATIVE_PATH = 'assets/build/.vite/manifest.json';
    const HOT_FILE_RELATIVE_PATH = 'assets/build/hot';
    const ENTRY_KEY_PREFIX = 'src/entries/';
    const ENTRY_KEY_SUFFIX = '.js';
    const STYLE_KEY_PREFIX = 'src/css/';
    /**
     * Vite 8.2 keeps the SOURCE extension in the manifest key, so a .scss
     * entry stays keyed .scss (probe build 2026-09-07).
     */
    const STYLE_KEY_SUFFIX = '.scss';

    /**
     * Per-request manifest memo, keyed by absolute manifest path.
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected static array $arManifestCache = [];

    /**
     * Render script/style tags for one Vite entry of the active theme.
     */
    public static function renderEntry(string $sEntryName): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $sEntryName)) {
            throw new RuntimeException(
                sprintf('vite_entry: invalid entry name "%s" - expected a lowercase slug like "core"', $sEntryName)
            );
        }

        $obTheme = Theme::getActiveTheme();
        if ($obTheme === null) {
            throw new RuntimeException('vite_entry: no active CMS theme resolved');
        }

        $sEntryKey = self::ENTRY_KEY_PREFIX.$sEntryName.self::ENTRY_KEY_SUFFIX;
        $sThemeDirectoryPath = $obTheme->getPath();

        $sHotFilePath = $sThemeDirectoryPath.'/'.self::HOT_FILE_RELATIVE_PATH;
        if (is_file($sHotFilePath)) {
            $sDevServerUrl = rtrim(trim((string) file_get_contents($sHotFilePath)), '/');

            return self::buildDevServerHtml($sDevServerUrl, $sEntryKey);
        }

        $arManifest = self::loadManifest($sThemeDirectoryPath.'/'.self::MANIFEST_RELATIVE_PATH);
        $sBuildBaseUrl = '/themes/'.$obTheme->getDirName().'/'.self::BUILD_DIRECTORY;

        return self::buildEntryHtml($arManifest, $sEntryKey, $sBuildBaseUrl);
    }

    /**
     * Render the stylesheet link for one CSS-only Vite entry of the active theme.
     *
     * Twig usage (registered as the vite_style() function in Plugin.php):
     *   {{ vite_style('chrome')|raw }}
     *
     * The entry is a stylesheet listed directly in the Vite input map, so its
     * manifest record carries no "imports" and no "css" array: the "file" is
     * the sheet itself and there is no chunk graph to walk.
     *
     * Dev mode is honest here: while the Vite dev server hot file exists, a
     * CSS-only entry is still served as a JS module that injects a <style>,
     * so this emits script tags rather than a link. That is a dev-server
     * property, and the reason the asset budget gate reads a built manifest.
     *
     * @param  string $sEntryName Lowercase slug, e.g. "chrome"
     * @return string             One <link rel="stylesheet"> tag in production
     */
    public static function renderStyle(string $sEntryName): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $sEntryName)) {
            throw new RuntimeException(
                sprintf('vite_style: invalid entry name "%s" - expected a lowercase slug like "chrome"', $sEntryName)
            );
        }

        $obTheme = Theme::getActiveTheme();
        if ($obTheme === null) {
            throw new RuntimeException('vite_style: no active CMS theme resolved');
        }

        $sEntryKey = self::STYLE_KEY_PREFIX.$sEntryName.self::STYLE_KEY_SUFFIX;
        $sThemeDirectoryPath = $obTheme->getPath();

        $sHotFilePath = $sThemeDirectoryPath.'/'.self::HOT_FILE_RELATIVE_PATH;
        if (is_file($sHotFilePath)) {
            $sDevServerUrl = rtrim(trim((string) file_get_contents($sHotFilePath)), '/');

            return self::buildDevServerHtml($sDevServerUrl, $sEntryKey);
        }

        $arManifest = self::loadManifest($sThemeDirectoryPath.'/'.self::MANIFEST_RELATIVE_PATH);
        $sBuildBaseUrl = '/themes/'.$obTheme->getDirName().'/'.self::BUILD_DIRECTORY;

        return self::buildStyleHtml($arManifest, $sEntryKey, $sBuildBaseUrl);
    }

    /**
     * Dev-mode tags: the Vite client plus the raw entry module.
     */
    public static function buildDevServerHtml(string $sDevServerUrl, string $sEntryKey): string
    {
        if ($sDevServerUrl === '') {
            throw new RuntimeException('vite_entry: hot file exists but is empty - restart the Vite dev server');
        }

        return '<script type="module" src="'.e($sDevServerUrl.'/@vite/client').'"></script>'."\n"
            .'<script type="module" src="'.e($sDevServerUrl.'/'.$sEntryKey).'"></script>';
    }

    /**
     * Production tags from a decoded manifest: stylesheets first, then
     * modulepreload hints for shared chunks, then the entry module.
     *
     * @param array<string, array<string, mixed>> $arManifest
     */
    public static function buildEntryHtml(array $arManifest, string $sEntryKey, string $sBuildBaseUrl): string
    {
        if (empty($arManifest)) {
            throw new RuntimeException('vite_entry: manifest is empty - run "pnpm build" in the theme');
        }

        if (!isset($arManifest[$sEntryKey])) {
            throw new RuntimeException(
                sprintf('vite_entry: entry "%s" not found in manifest (known: %s)', $sEntryKey, implode(', ', array_keys($arManifest)))
            );
        }

        $sBuildBaseUrl = rtrim($sBuildBaseUrl, '/');
        $arChunkKeyList = self::collectChunkKeyList($arManifest, $sEntryKey);

        $arStylesheetUrlList = [];
        $arModulePreloadUrlList = [];
        foreach ($arChunkKeyList as $sChunkKey) {
            $arChunk = $arManifest[$sChunkKey];
            foreach ((array) ($arChunk['css'] ?? []) as $sCssFile) {
                $arStylesheetUrlList[$sBuildBaseUrl.'/'.$sCssFile] = true;
            }
            if ($sChunkKey !== $sEntryKey && !empty($arChunk['file'])) {
                $arModulePreloadUrlList[$sBuildBaseUrl.'/'.$arChunk['file']] = true;
            }
        }

        $arHtmlLineList = [];
        foreach (array_keys($arStylesheetUrlList) as $sStylesheetUrl) {
            $arHtmlLineList[] = '<link rel="stylesheet" href="'.e($sStylesheetUrl).'">';
        }
        foreach (array_keys($arModulePreloadUrlList) as $sModulePreloadUrl) {
            $arHtmlLineList[] = '<link rel="modulepreload" href="'.e($sModulePreloadUrl).'">';
        }
        $arHtmlLineList[] = '<script type="module" src="'.e($sBuildBaseUrl.'/'.$arManifest[$sEntryKey]['file']).'"></script>';

        return implode("\n", $arHtmlLineList);
    }

    /**
     * Production tag for a CSS-only entry: the manifest "file" is the
     * stylesheet, emitted as a single link.
     *
     * Throws when the resolved file is not a stylesheet, so an entry that
     * later grows a JS chunk fails at the call site instead of being linked
     * as a sheet it is not.
     *
     * @param  array<string, array<string, mixed>> $arManifest
     * @param  string                              $sEntryKey      Manifest key, e.g. "src/css/chrome.scss"
     * @param  string                              $sBuildBaseUrl  URL prefix of the build directory
     * @return string                                              One <link rel="stylesheet"> tag
     */
    public static function buildStyleHtml(array $arManifest, string $sEntryKey, string $sBuildBaseUrl): string
    {
        if (empty($arManifest)) {
            throw new RuntimeException('vite_style: manifest is empty - run "pnpm build" in the theme');
        }

        if (!isset($arManifest[$sEntryKey])) {
            throw new RuntimeException(
                sprintf('vite_style: entry "%s" not found in manifest (known: %s)', $sEntryKey, implode(', ', array_keys($arManifest)))
            );
        }

        $sBuildBaseUrl = rtrim($sBuildBaseUrl, '/');
        $sFile = (string) $arManifest[$sEntryKey]['file'];

        if (!str_ends_with($sFile, '.css')) {
            throw new RuntimeException(
                sprintf('vite_style: entry "%s" resolves to "%s", which is not a stylesheet - use vite_entry() for JavaScript entries', $sEntryKey, $sFile)
            );
        }

        return '<link rel="stylesheet" href="'.e($sBuildBaseUrl.'/'.$sFile).'">';
    }

    /**
     * The entry chunk plus every statically imported chunk, breadth-first
     * with a visited set (import graphs may share chunks; loop is bounded
     * by the manifest size).
     *
     * @param array<string, array<string, mixed>> $arManifest
     * @return array<int, string>
     */
    protected static function collectChunkKeyList(array $arManifest, string $sEntryKey): array
    {
        $arVisitedKeyList = [];
        $arQueueKeyList = [$sEntryKey];
        $iManifestChunkCount = count($arManifest);

        while (!empty($arQueueKeyList)) {
            $sCurrentKey = array_shift($arQueueKeyList);
            if (isset($arVisitedKeyList[$sCurrentKey]) || !isset($arManifest[$sCurrentKey])) {
                continue;
            }
            $arVisitedKeyList[$sCurrentKey] = true;

            if (count($arVisitedKeyList) > $iManifestChunkCount) {
                throw new RuntimeException('vite_entry: manifest import graph exceeded manifest size - corrupt manifest');
            }

            foreach ((array) ($arManifest[$sCurrentKey]['imports'] ?? []) as $sImportedKey) {
                $arQueueKeyList[] = $sImportedKey;
            }
        }

        return array_keys($arVisitedKeyList);
    }

    /**
     * Read + decode the manifest once per request.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function loadManifest(string $sManifestPath): array
    {
        if (isset(self::$arManifestCache[$sManifestPath])) {
            return self::$arManifestCache[$sManifestPath];
        }

        if (!is_file($sManifestPath)) {
            throw new RuntimeException(
                sprintf('vite_entry: manifest not found at "%s" - run "pnpm build" in the theme', $sManifestPath)
            );
        }

        $arManifest = json_decode((string) file_get_contents($sManifestPath), true);
        if (!is_array($arManifest)) {
            throw new RuntimeException(sprintf('vite_entry: manifest at "%s" is not valid JSON', $sManifestPath));
        }

        self::$arManifestCache[$sManifestPath] = $arManifest;

        return $arManifest;
    }
}
