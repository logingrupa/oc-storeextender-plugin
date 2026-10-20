<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Random\Randomizer;
use Illuminate\Support\Str;
use RainLab\User\Models\Setting;

/**
 * Class CheckoutPassword
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * One character of every class RainLab can require, at least the policy's minimum length.
 */
final class CheckoutPassword
{
    const LENGTH = 32;

    /**
     * Str::password guarantees a letter, a digit and a symbol; the two seeds add mixed case.
     * @return string
     */
    public static function make(): string
    {
        $iLength = max(self::LENGTH, (int) Setting::instance()->password_min_length);

        $obRandomizer = new Randomizer();
        $sCaseSeed = $obRandomizer->getBytesFromString('abcdefghijklmnopqrstuvwxyz', 1)
            .$obRandomizer->getBytesFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 1);

        return $obRandomizer->shuffleBytes($sCaseSeed.Str::password($iLength - 2));
    }
}
