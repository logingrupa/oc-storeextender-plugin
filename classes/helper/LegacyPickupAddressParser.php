<?php

namespace Logingrupa\StoreExtender\Classes\Helper;

/**
 * Splits legacy pickup-point and store-pickup strings stored in UserAddress.address2
 * into structured address1 / city / postcode / country columns while leaving address2
 * intact for 1C and pickup-point preselection.
 */
class LegacyPickupAddressParser
{
    /**
     * Known own store pickup rules (matched case-insensitively on cleaned address2).
     *
     * @var list<array{needle: string, address1: string, city: string, postcode: string, country: string}>
     */
    private const KNOWN_STORE_LIST = [
        [
            'needle' => 'ernesta birznieka-upīša iela 20a',
            'address1' => 'Ernesta Birznieka-Upīša iela 20A',
            'city' => 'Rīga',
            'postcode' => 'LV-1050',
            'country' => 'LV',
        ],
        [
            'needle' => 'graudi iela 45',
            'address1' => 'Graudi iela 45',
            'city' => 'Liepāja',
            'postcode' => 'LV-3401',
            'country' => 'LV',
        ],
        [
            'needle' => 'graudu iela 45',
            'address1' => 'Graudu iela 45',
            'city' => 'Liepāja',
            'postcode' => 'LV-3401',
            'country' => 'LV',
        ],
        [
            'needle' => 'lielā iela 15, jelgav',
            'address1' => 'Lielā iela 15',
            'city' => 'Jelgava',
            'postcode' => 'LV-3001',
            'country' => 'LV',
        ],
        [
            'needle' => 'rīgas iela 32, valmier',
            'address1' => 'Rīgas iela 32',
            'city' => 'Valmiera',
            'postcode' => 'LV-4201',
            'country' => 'LV',
        ],
        [
            'needle' => 'rīgas iela 29, valmier',
            'address1' => 'Rīgas iela 29',
            'city' => 'Valmiera',
            'postcode' => 'LV-4201',
            'country' => 'LV',
        ],
        [
            'needle' => 'meža ielā 7, valmier',
            'address1' => 'Meža ielā 7',
            'city' => 'Valmiera',
            'postcode' => 'LV-4201',
            'country' => 'LV',
        ],
        [
            'needle' => 'atbrīvošanas aleja 103-3',
            'address1' => 'Atbrīvošanas aleja 103-3',
            'city' => 'Rēzekne',
            'postcode' => 'LV-4601',
            'country' => 'LV',
        ],
        [
            'needle' => 'draudzības aleja 9',
            'address1' => 'Draudzības aleja 9',
            'city' => 'Jēkabpils',
            'postcode' => 'LV-5201',
            'country' => 'LV',
        ],
        [
            'needle' => 'saules iela 19, daugavpil',
            'address1' => 'Saules iela 19',
            'city' => 'Daugavpils',
            'postcode' => 'LV-5401',
            'country' => 'LV',
        ],
        [
            'needle' => 'šv. gertrūdos g. 6',
            'address1' => 'Šv. Gertrūdos g. 6',
            'city' => 'Kaunas',
            'postcode' => 'LT-44260',
            'country' => 'LT',
        ],
        [
            'needle' => 'gjeddeveien 18',
            'address1' => 'Gjeddeveien 18',
            'city' => 'Moss',
            'postcode' => '1528',
            'country' => 'NO',
        ],
        [
            'needle' => 'unterortstraße 48',
            'address1' => 'Unterortstraße 48',
            'city' => 'Eschborn',
            'postcode' => '65760',
            'country' => 'DE',
        ],
    ];

    /**
     * Parse structured address components from a raw or cleaned address2 string.
     *
     * @return array{address1: string, city: string, postcode: string, country: string}
     */
    public static function parse(?string $sRawAddress2): array
    {
        $sClean = UserAddressHelper::cleanValue($sRawAddress2);
        if ($sClean === '') {
            return self::emptyResult();
        }

        // 1. Known store pickup locations (LV / LT / NO / DE).
        $sLower = mb_strtolower($sClean, 'UTF-8');
        foreach (self::KNOWN_STORE_LIST as $arStore) {
            if (str_contains($sLower, $arStore['needle'])) {
                return [
                    'address1' => $arStore['address1'],
                    'city' => $arStore['city'],
                    'postcode' => $arStore['postcode'],
                    'country' => $arStore['country'],
                ];
            }
        }

        // 2. "Street , CITY, LV3201, (Paku Skapis ...)" or "Street, City, LT-44260, (...)"
        if (preg_match('/^([^,(]+?)\s*,\s*([^,(]+?)\s*,\s*(LV|LT|EE)[\s-]*(\d{4,5})(?:\s*,\s*.*)?$/iu', $sClean, $arMatch)) {
            $sCountry = strtoupper($arMatch[3]);

            return [
                'address1' => self::cleanSegment($arMatch[1]),
                'city' => self::normalizeCity($arMatch[2]),
                'postcode' => $sCountry.'-'.$arMatch[4],
                'country' => $sCountry,
            ];
        }

        // 3. "Street, City, 1528, (Name)" or "Street, 1528 City - ..."
        if (preg_match('/^([^,(]+?)\s*,\s*(\d{4,5})\s+([^,(-]+?)(?:\s*(?:-|,\s*\().*)?$/u', $sClean, $arMatch)) {
            $sZip = $arMatch[2];
            $sCountry = self::inferCountry($sClean, $sZip);

            return [
                'address1' => self::cleanSegment($arMatch[1]),
                'city' => self::normalizeCity($arMatch[3]),
                'postcode' => $sZip,
                'country' => $sCountry,
            ];
        }

        // 4. Legacy duplicated DPD format: "City, Street (Paku Skapis / Paku Bode / PS ...), Street (...)"
        if (preg_match('/^([^,(]+?)\s*,\s*([^(,]+?)\s*\(([^()]+(?:\([^()]*\)[^()]*)*)\)(?:\s*,\s*.+)?$/u', $sClean, $arMatch)) {
            $sLockerLabel = $arMatch[3];
            if (self::looksLikePickupLabel($sLockerLabel)) {
                return [
                    'address1' => self::cleanSegment($arMatch[2]),
                    'city' => self::normalizeCity($arMatch[1]),
                    'postcode' => '',
                    'country' => self::inferCountry($sClean),
                ];
            }
        }

        // 5. New Omniva format without postcode: "Street, City, (Locker Name)"
        if (preg_match('/^([^,(]+?)\s*,\s*([^,(]+?)\s*,\s*\((.+)\)\s*$/u', $sClean, $arMatch)) {
            return [
                'address1' => self::cleanSegment($arMatch[1]),
                'city' => self::normalizeCity($arMatch[2]),
                'postcode' => '',
                'country' => self::inferCountry($sClean),
            ];
        }

        // 6. Bare locker name with no street/comma (e.g. "Madonas Maxima XX pakomāts"):
        // infer country only and avoid inventing street/city data.
        $sCountry = self::inferCountry($sClean);
        if ($sCountry !== '') {
            return [
                'address1' => '',
                'city' => '',
                'postcode' => '',
                'country' => $sCountry,
            ];
        }

        return self::emptyResult();
    }

    /**
     * @return array{address1: string, city: string, postcode: string, country: string}
     */
    private static function emptyResult(): array
    {
        return [
            'address1' => '',
            'city' => '',
            'postcode' => '',
            'country' => '',
        ];
    }

    private static function cleanSegment(string $sValue): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sValue));
    }

    private static function normalizeCity(string $sCity): string
    {
        $sCity = self::cleanSegment($sCity);
        if ($sCity === '') {
            return '';
        }

        // Convert ALL-CAPS legacy feed cities like "TALSI" or "RĪGA" to title case.
        if ($sCity === mb_strtoupper($sCity, 'UTF-8') && $sCity !== mb_strtolower($sCity, 'UTF-8')) {
            return mb_convert_case(mb_strtolower($sCity, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }

        return $sCity;
    }

    private static function looksLikePickupLabel(string $sLabel): bool
    {
        return (bool) preg_match(
            '/\b(?:paku\s+skapis|paku\s+bode|ps\s+|pakom[aā]ts|pa[sš]tomat|omniva|dpd|postnord|circle\s+k|vir[sš]i|rimi|maxima|mego|elvi|citro|top)\b/iu',
            $sLabel
        );
    }

    private static function inferCountry(string $sText, string $sZip = ''): string
    {
        if (preg_match('/\b(?:paku\s+skapis|paku\s+bode|pakom[aā]ts|bezmaksas|iela\b|iel[aā]\b|prospekts\b|bulv[aā]ris\b|aleja\b|gatve\b|krastmala\b)\b/iu', $sText)) {
            return 'LV';
        }
        if (preg_match('/\b(?:pa[sš]tomat|nemokamai|g\.\s*\d)/iu', $sText)) {
            return 'LT';
        }
        if (preg_match('/\b(?:postnord|gratis|veien\b|gata\b|gate\b)\b/iu', $sText)) {
            return 'NO';
        }
        if ($sZip !== '' && strlen($sZip) === 4 && preg_match('/\b(?:moss|oslo|bergen|trondheim|stavanger)\b/iu', $sText)) {
            return 'NO';
        }

        return '';
    }
}
