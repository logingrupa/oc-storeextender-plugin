<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use App;
use System\Models\SiteDefinition;

/**
 * Banner artwork is uploaded one file per language, the locale in the file
 * name: "NAILS cosmetics_09_SEPT-lv.jpg", "-en.jpg", "-ru.jpg". The theme
 * stores one of them per banner and this swaps the suffix for the locale the
 * visitor is reading, when that file is actually in the media library.
 *
 * The media library is the source of truth on purpose. The theme used to gate
 * the swap on a "Kurās valodās ir iztulkots baneris" checkbox list the editor
 * had to keep in sync by hand, which drifts (and drifted): a banner whose -ru
 * file exists still rendered the Latvian one, and a ticked locale whose file
 * was never uploaded rendered a 404.
 *
 * Twig, registered in Plugin::registerMarkupTags():
 *   {{ obData.image|localized_media(this.locale)|media|resize(1000, 455) }}
 */
class LocalizedMediaHelper
{
    /** Two lowercase letters before the extension: "...-lv.jpg" */
    const LOCALE_SUFFIX_PATTERN = '/^(?<base>.+)-(?<locale>[a-z]{2})(?<extension>\.[a-z0-9]+)$/i';

    /** @var array<string, string> Resolved path per "path|locale", one disk stat each */
    protected static $arResolvedPathList = [];

    /** @var array<int, string>|null Locale codes a file name may end with */
    protected static $arSiteLocaleList = null;

    /**
     * Swap a media path's locale suffix for the active locale.
     * @param string|null $sPath media library path as the theme stored it
     * @param string|null $sLocale target locale, active locale when omitted
     * @return string|null the stored path when there is nothing to swap
     */
    public static function localize($sPath, $sLocale = null)
    {
        if (empty($sPath) || !is_string($sPath)) {
            return $sPath;
        }

        $sLocale = empty($sLocale) ? App::getLocale() : $sLocale;
        if (empty($sLocale) || !is_string($sLocale)) {
            return $sPath;
        }

        $sCacheKey = $sPath.'|'.$sLocale;
        if (isset(self::$arResolvedPathList[$sCacheKey])) {
            return self::$arResolvedPathList[$sCacheKey];
        }

        return self::$arResolvedPathList[$sCacheKey] = static::resolvePath($sPath, $sLocale);
    }

    /**
     * Forget the resolved paths and the site locale list.
     * @return void
     */
    public static function flushCache()
    {
        self::$arResolvedPathList = [];
        self::$arSiteLocaleList = null;
    }

    /**
     * @param string $sPath
     * @param string $sLocale
     * @return string
     */
    protected static function resolvePath($sPath, $sLocale)
    {
        if (!preg_match(self::LOCALE_SUFFIX_PATTERN, $sPath, $arMatchList)) {
            return $sPath;
        }

        $arLocaleList = static::getSiteLocaleList();
        $sSourceLocale = mb_strtolower($arMatchList['locale']);
        $sTargetLocale = mb_strtolower($sLocale);

        // Only a known locale is a locale suffix. "product-xl.jpg" is a name.
        if (!in_array($sSourceLocale, $arLocaleList) || $sSourceLocale == $sTargetLocale) {
            return $sPath;
        }

        $sCandidatePath = $arMatchList['base'].'-'.$sTargetLocale.$arMatchList['extension'];

        return static::mediaFileExists($sCandidatePath) ? $sCandidatePath : $sPath;
    }

    /**
     * @param string $sPath
     * @return bool
     */
    protected static function mediaFileExists($sPath)
    {
        return App::make('media.library')->exists($sPath);
    }

    /**
     * Locales of the enabled sites, which is every language this server can
     * render, hence every suffix its media library can carry.
     * @return array<int, string>
     */
    protected static function getSiteLocaleList()
    {
        if (self::$arSiteLocaleList !== null) {
            return self::$arSiteLocaleList;
        }

        $arLocaleList = SiteDefinition::where('is_enabled', true)
            ->pluck('locale')
            ->filter()
            ->map(function ($sLocale) {
                return mb_strtolower(substr($sLocale, 0, 2));
            })
            ->unique()
            ->values()
            ->all();

        return self::$arSiteLocaleList = $arLocaleList;
    }
}
