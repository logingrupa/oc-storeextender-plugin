<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Random\Randomizer;
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

    const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

    const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    const DIGITS = '0123456789';

    /** ASCII \p{S} and \p{P} characters, both satisfy Password::symbols() */
    const SYMBOLS = '~!#$%^&*()-_.,<>?/\\{}[]|:;';

    /**
     * A seed from each class, the rest from their union, shuffled, all from one randomizer.
     * @param Randomizer $obRandomizer
     * @return string
     */
    public static function make(Randomizer $obRandomizer = new Randomizer()): string
    {
        $iLength = max(self::LENGTH, (int) Setting::instance()->password_min_length);

        $sSeed = $obRandomizer->getBytesFromString(self::LOWERCASE, 1)
            .$obRandomizer->getBytesFromString(self::UPPERCASE, 1)
            .$obRandomizer->getBytesFromString(self::DIGITS, 1)
            .$obRandomizer->getBytesFromString(self::SYMBOLS, 1);

        $sPool = self::LOWERCASE.self::UPPERCASE.self::DIGITS.self::SYMBOLS;
        $sFill = $obRandomizer->getBytesFromString($sPool, $iLength - strlen($sSeed));

        return $obRandomizer->shuffleBytes($sSeed.$sFill);
    }
}
