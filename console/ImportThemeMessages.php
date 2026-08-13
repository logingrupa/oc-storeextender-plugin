<?php namespace Logingrupa\StoreExtender\Console;

use Cms\Classes\Theme;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Logingrupa\StoreExtender\Components\OfferSheet;
use RainLab\Translate\Models\Message;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ImportThemeMessages
 *
 * Import the active theme's configs/lang.yaml into RainLab Translate message
 * data. The yaml (git-versioned, ships with every deploy) is the source of
 * truth: per key the yaml value wins, DB-only keys survive untouched. Runs as
 * a deploy step so every server carries the same translations without manual
 * seeding.
 *
 * @package Logingrupa\StoreExtender\Console
 */
class ImportThemeMessages extends Command
{
    const LANG_FILE_RELATIVE_PATH = '/configs/lang.yaml';

    /** @var string */
    protected $signature = 'storeextender:import-theme-messages';

    /** @var string */
    protected $description = 'Import active theme configs/lang.yaml into RainLab Translate messages';

    public function handle(): int
    {
        $obTheme = Theme::getActiveTheme();
        if ($obTheme === null) {
            $this->error('No active theme resolved.');

            return self::FAILURE;
        }

        $sLangFilePath = $obTheme->getPath().self::LANG_FILE_RELATIVE_PATH;
        if (!is_file($sLangFilePath)) {
            $this->error("Lang file not found: {$sLangFilePath}");

            return self::FAILURE;
        }

        $arLocaleMap = Yaml::parseFile($sLangFilePath);
        if (empty($arLocaleMap) || !is_array($arLocaleMap)) {
            $this->error('Lang file is empty or not a locale map.');

            return self::FAILURE;
        }

        $obMessage = new Message();
        foreach ($arLocaleMap as $sLocale => $arMessageList) {
            if (!is_array($arMessageList) || empty($arMessageList)) {
                $this->warn("{$sLocale}: skipped - no messages");
                continue;
            }
            $obMessage->updateMessages((string) $sLocale, $arMessageList);
            $this->info("{$sLocale}: ".count($arMessageList).' messages imported');
        }

        // Cached sheet rows carry rendered labels: rotate the epoch. Its value
        // is folded into every server cache key AND the client cache token
        // (OfferSheet::getRenderContextKeyParts), so one Cache::forget re-keys
        // the server rows and drops every client store together. Never reach
        // for CCache::clear by tag here - it is a silent no-op on the file
        // cache driver.
        Cache::forget(OfferSheet::CACHE_KEY_EPOCH);
        $this->info('Sheet cache epoch rotated: server rows re-key, clients drop their stores.');

        return self::SUCCESS;
    }
}
