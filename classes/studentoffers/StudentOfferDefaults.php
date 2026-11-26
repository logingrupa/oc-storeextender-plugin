<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\StudentOffers;

use System\Classes\SiteManager;
use Throwable;

/**
 * Default configuration and storefront helpers for /online-student-offers.
 */
class StudentOfferDefaults
{
    public const THEME_FIELD = 'student_offer_sections';

    public const CARD_CLASS_5 = 'col-6 col-md-4 col-sm-6 mb-45 col-lg-is-5';

    public const CARD_CLASS_6 = 'col-6 col-md-2 col-sm-6 mb-45 col-lg-is-2';

    /**
     * Default two-section layout preserving the existing .lv /online-student-offers page.
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(): array
    {
        return [
            [
                'is_active' => true,
                'title' => 'UV LED lampu akcijas piedāvājums',
                'subtitle' => '<p>Izvēlies sev piemērotāko (rekomendējam noskatīties video "<a href="https://onlinemanikirs.learnworlds.com/path-player?courseid=online-manikira-kursi-nagu-kopanas-specilists&amp;unit=5eb3b97c00530150617189edUnit">padomi LED lampas izvēlē</a>")</p>',
                'coupon_group_ids' => [],
                'coupon_code' => '',
                'cards_per_row' => '6',
                'products' => [
                    ['product_id' => '352', 'coupon_code' => '352.352', 'is_hidden' => false],
                    ['product_id' => '353', 'coupon_code' => '353.353', 'is_hidden' => false],
                    ['product_id' => '354', 'coupon_code' => '354.354', 'is_hidden' => false],
                    ['product_id' => '355', 'coupon_code' => '355.355', 'is_hidden' => false],
                    ['product_id' => '42', 'coupon_code' => '42.42', 'is_hidden' => false],
                    ['product_id' => '136', 'coupon_code' => '136.136', 'is_hidden' => false],
                ],
            ],
            [
                'is_active' => true,
                'title' => 'Starta komplekta produktiem -10%',
                'subtitle' => '<p>Ja nepaspēji iegādāties starta komplektu vai vēlies to papildināt, iegādājies tajā esošos produktus par akcijas cenu.</p>',
                'coupon_group_ids' => [],
                'coupon_code' => '021.021',
                'cards_per_row' => '5',
                'products' => array_map(
                    static fn (string $sProductId): array => [
                        'product_id' => $sProductId,
                        'coupon_code' => '',
                        'is_hidden' => false,
                    ],
                    [
                        '228', '212', '233', '270', '324', '204',
                        '257', '261', '258', '262', '265', '46',
                        '50', '321', '38', '201', '346', '345',
                        '150', '223', '160', '181', '169', '323',
                    ]
                ),
            ],
        ];
    }

    public static function cardClassFor(mixed $mCardsPerRow): string
    {
        return (string) $mCardsPerRow === '6' ? self::CARD_CLASS_6 : self::CARD_CLASS_5;
    }

    /**
     * Decodes a ThemeData repeater value (array, JSON string, or empty/null).
     *
     * @return array<int|string, mixed>|null
     */
    public static function decodeRepeaterValue(mixed $mValue): ?array
    {
        if (is_array($mValue)) {
            return $mValue;
        }

        if (is_string($mValue)) {
            $sTrimmed = trim($mValue);
            if ($sTrimmed === '') {
                return [];
            }

            $mDecoded = json_decode($sTrimmed, true);

            return is_array($mDecoded) ? $mDecoded : [];
        }

        return null;
    }

    /**
     * True when the active deployment serves a Latvian site (enabled locale starting with "lv").
     * False on .lt (lt/en/ru) and .no (only nb-no enabled; lv_LV is disabled).
     */
    public static function isLatvianStorefront(): bool
    {
        try {
            foreach (SiteManager::instance()->listEnabled() as $obSite) {
                $sLocale = strtolower((string) ($obSite->locale ?? ''));
                if ($sLocale === 'lv' || str_starts_with($sLocale, 'lv_') || str_starts_with($sLocale, 'lv-')) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
