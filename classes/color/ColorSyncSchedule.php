<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Class ColorSyncSchedule
 *
 * Daily time of storeextender:sync-offer-colors. The shops share one nailolab
 * host that sleeps between requests, so each shop sets its own minute in
 * services.color_lab.sync_at (COLOR_LAB_SYNC_AT) and the host wakes for one
 * request at a time.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorSyncSchedule
{
    const DEFAULT_TIME = '07:00';
    const TIME_PATTERN = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * The configured HH:MM, or the default. A malformed value falls back with a
     * warning, because a broken cron expression stops every scheduled command.
     * @return string
     */
    public static function time(): string
    {
        $mConfiguredTime = Config::get('services.color_lab.sync_at');
        if ($mConfiguredTime === null || $mConfiguredTime === '') {
            return self::DEFAULT_TIME;
        }
        if (self::isValidTime($mConfiguredTime)) {
            return $mConfiguredTime;
        }

        Log::warning('storeextender: services.color_lab.sync_at must be HH:MM, the colour sync keeps '.self::DEFAULT_TIME, [
            'sync_at' => $mConfiguredTime,
        ]);

        return self::DEFAULT_TIME;
    }

    /**
     * @param mixed $mTime
     * @return bool
     */
    public static function isValidTime($mTime): bool
    {
        return is_string($mTime) && preg_match(self::TIME_PATTERN, $mTime) === 1;
    }
}
