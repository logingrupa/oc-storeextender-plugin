<?php namespace Logingrupa\StoreExtender\Classes\Color;

/**
 * Class ColorGradient
 *
 * The family gradient the color-lab families.json sends:
 * ['type' => 'linear'|'radial'|'conic', 'angle' => int 0-359,
 *  'stops' => 2 to 8 strict #rrggbb]. Linear draws at the angle, conic
 * starts its sweep there, radial ignores it. Anything else is no gradient,
 * so the flat hex draws. Only strict hex stops, an int and fixed words reach
 * the CSS, so it is safe inside an inline style.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorGradient
{
    const TYPE_LINEAR = 'linear';
    const TYPE_RADIAL = 'radial';
    const TYPE_CONIC = 'conic';
    const MIN_STOP_COUNT = 2;
    const MAX_STOP_COUNT = 8;
    const MAX_ANGLE = 359;

    /**
     * @param mixed $mGradient
     * @return array|null ['type' => string, 'angle' => int, 'stops' => string[]], null for anything else
     */
    public static function normalize($mGradient): ?array
    {
        if (!is_array($mGradient)) {
            return null;
        }

        $mType = $mGradient['type'] ?? null;
        $mAngle = $mGradient['angle'] ?? null;
        $mStops = $mGradient['stops'] ?? null;

        if (!in_array($mType, [self::TYPE_LINEAR, self::TYPE_RADIAL, self::TYPE_CONIC], true)
            || !is_int($mAngle) || $mAngle < 0 || $mAngle > self::MAX_ANGLE
            || !static::isStopList($mStops)
        ) {
            return null;
        }

        return ['type' => $mType, 'angle' => $mAngle, 'stops' => $mStops];
    }

    /**
     * @param mixed $mGradient
     * @return string|null a CSS background, null when the value is no gradient
     */
    public static function toCss($mGradient): ?string
    {
        $arGradient = static::normalize($mGradient);
        if ($arGradient === null) {
            return null;
        }

        $sStops = implode(', ', $arGradient['stops']);
        if ($arGradient['type'] === self::TYPE_RADIAL) {
            return 'radial-gradient(circle, '.$sStops.')';
        }
        if ($arGradient['type'] === self::TYPE_CONIC) {
            return 'conic-gradient(from '.$arGradient['angle'].'deg, '.$sStops.')';
        }

        return 'linear-gradient('.$arGradient['angle'].'deg, '.$sStops.')';
    }

    /**
     * @param mixed $mStops
     * @return bool
     */
    protected static function isStopList($mStops): bool
    {
        if (!is_array($mStops) || !array_is_list($mStops)
            || count($mStops) < self::MIN_STOP_COUNT || count($mStops) > self::MAX_STOP_COUNT
        ) {
            return false;
        }

        foreach ($mStops as $mStop) {
            if (!is_string($mStop) || preg_match('/^#[0-9A-Fa-f]{6}$/', $mStop) !== 1) {
                return false;
            }
        }

        return true;
    }
}
