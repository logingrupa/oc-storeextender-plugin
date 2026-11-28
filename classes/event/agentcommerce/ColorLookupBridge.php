<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\AgentCommerce;

use Illuminate\Support\Facades\Cache;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;
use Lovata\Shopaholic\Classes\Store\OfferListStore;
use Lovata\Shopaholic\Models\Offer;

/**
 * String-event bridge extending AgentCommerce offer lookup and variant payloads with:
 * - Cached ColorMapRepository index (keyed by ColorMapRepository::getVersion())
 * - Active offer pre-intersection via OfferListStore before hydrating any OfferItem
 * - Batched color_family[] matching, shade_code matching, and deterministic CIELAB CIE76 Delta-E hex search
 * - Non-resizing OfferImageHelper::swatchIfWarm() swatch URL enrichment
 */
class ColorLookupBridge
{
    public const CACHE_TTL_SECONDS = 600;
    public const DEFAULT_MAX_DELTA_E = 35.0;

    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen('agentcommerce.lookup.extendFilters', function ($arCriteria): ?array {
            if (!is_array($arCriteria)) {
                return null;
            }

            return $this->handleLookupFilters($arCriteria);
        });

        $obEvent->listen('agentcommerce.variant.extendPayload', function ($arPayload, $obOffer): array {
            if (!is_array($arPayload) || !$obOffer instanceof Offer) {
                return [];
            }

            return $this->enrichVariantPayload($obOffer);
        });
    }

    /**
     * @param  array<string, mixed>  $arCriteria
     * @return array{handled: bool, offer_ids: list<int>, meta: array<int, array<string, mixed>>}|null
     */
    public function handleLookupFilters(array $arCriteria): ?array
    {
        $arFamilies = $this->normalizeFamilyFilter($arCriteria['color_family'] ?? null);
        $sHex = $this->normalizeHex($arCriteria['hex'] ?? null);
        $sShadeCode = is_string($arCriteria['shade_code'] ?? null) ? strtolower(trim($arCriteria['shade_code'])) : '';

        if ($arFamilies === [] && $sHex === null && $sShadeCode === '') {
            return null;
        }

        $arIndex = $this->getOfferColorIndex();
        $arActiveIdMap = array_flip(array_map('intval', (array) OfferListStore::instance()->active->get()));
        $arTargetLab = $sHex !== null ? self::hexToLab($sHex) : null;
        $fMaxDeltaE = is_numeric($arCriteria['max_color_distance'] ?? null)
            ? max(0.0, (float) $arCriteria['max_color_distance'])
            : self::DEFAULT_MAX_DELTA_E;

        $arScored = [];
        $arMeta = [];

        foreach ($arIndex['by_offer_id'] as $iOfferId => $arEntry) {
            if (!isset($arActiveIdMap[$iOfferId])) {
                continue;
            }

            if ($arFamilies !== [] && array_intersect($arFamilies, $arEntry['families']) === []) {
                continue;
            }

            if ($sShadeCode !== '' && strtolower((string) ($arEntry['shade_code'] ?? '')) !== $sShadeCode) {
                continue;
            }

            $fDistance = 0.0;
            if ($arTargetLab !== null) {
                if ($arEntry['lab'] === null) {
                    continue;
                }
                $fDistance = self::deltaE76($arTargetLab, $arEntry['lab']);
                if ($fDistance > $fMaxDeltaE) {
                    continue;
                }
            }

            $arScored[] = ['offer_id' => $iOfferId, 'distance' => $fDistance];
            $arRowMeta = [
                'color_family' => $arEntry['family'],
                'color_families' => $arEntry['families'],
                'shade_code' => $arEntry['shade_code'],
                'hex' => $arEntry['hex'],
            ];
            if ($arTargetLab !== null) {
                $arRowMeta['color_distance'] = round($fDistance, 2);
            }
            $arMeta[$iOfferId] = $arRowMeta;
        }

        usort($arScored, static function (array $arLeft, array $arRight): int {
            $iCmp = $arLeft['distance'] <=> $arRight['distance'];

            return $iCmp !== 0 ? $iCmp : ($arLeft['offer_id'] <=> $arRight['offer_id']);
        });

        return [
            'handled' => true,
            'offer_ids' => array_map(static fn (array $arRow): int => $arRow['offer_id'], $arScored),
            'meta' => $arMeta,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichVariantPayload(Offer $obOffer): array
    {
        $arExtra = [];
        $sSwatch = OfferImageHelper::swatchIfWarm($obOffer->preview_image);
        if ($sSwatch !== '') {
            $arExtra['swatch_image'] = $sSwatch;
        }

        $arIndex = $this->getOfferColorIndex();
        $iOfferId = (int) $obOffer->getKey();
        if (isset($arIndex['by_offer_id'][$iOfferId])) {
            $arColor = $arIndex['by_offer_id'][$iOfferId];
            $arExtra['color_family'] = $arColor['family'];
            $arExtra['color_families'] = $arColor['families'];
            $arExtra['shade_code'] = $arColor['shade_code'];
            $arExtra['hex'] = $arColor['hex'];
        }

        return $arExtra;
    }

    /**
     * @return array{by_offer_id: array<int, array{family: string, families: list<string>, shade_code: ?string, hex: ?string, lab: ?array{0: float, 1: float, 2: float}}>}
     */
    private function getOfferColorIndex(): array
    {
        $obRepo = new ColorMapRepository();
        $sVersion = $obRepo->getVersion() ?: 'v0';
        $sCacheKey = 'storeextender.agentcommerce.color_index.' . md5($sVersion);

        return (array) Cache::remember($sCacheKey, self::CACHE_TTL_SECONDS, static function () use ($obRepo): array {
            $arColorMap = $obRepo->getColorMap();
            if ($arColorMap === []) {
                return ['by_offer_id' => []];
            }

            $arByOfferId = [];
            $obOffers = Offer::query()->where('active', true)->whereNotNull('external_id')->get(['id', 'external_id']);
            foreach ($obOffers as $obOffer) {
                $sExternalId = (string) $obOffer->external_id;
                $sOfferUuid = str_contains($sExternalId, '#')
                    ? substr($sExternalId, (int) strrpos($sExternalId, '#') + 1)
                    : $sExternalId;

                if (!isset($arColorMap[$sOfferUuid]) || !is_array($arColorMap[$sOfferUuid])) {
                    continue;
                }

                $arEntry = $arColorMap[$sOfferUuid];
                $sHex = is_string($arEntry['hex'] ?? null) ? self::normalizeHexStatic($arEntry['hex']) : null;
                $arFamilies = array_values(array_unique(array_map(
                    static fn (mixed $mFam): string => strtolower(trim((string) $mFam)),
                    is_array($arEntry['families'] ?? null) ? $arEntry['families'] : [(string) ($arEntry['family'] ?? '')]
                )));
                $arFamilies = array_values(array_filter($arFamilies, static fn (string $sFam): bool => $sFam !== ''));

                $arByOfferId[(int) $obOffer->id] = [
                    'family' => strtolower(trim((string) ($arEntry['family'] ?? ($arFamilies[0] ?? '')))),
                    'families' => $arFamilies,
                    'shade_code' => is_string($arEntry['shade_code'] ?? null) ? trim($arEntry['shade_code']) : null,
                    'hex' => $sHex,
                    'lab' => $sHex !== null ? self::hexToLab($sHex) : null,
                ];
            }

            return ['by_offer_id' => $arByOfferId];
        });
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public static function hexToLab(string $sHex): array
    {
        $sClean = ltrim($sHex, '#');
        $fRed = self::srgbToLinear(hexdec(substr($sClean, 0, 2)) / 255.0);
        $fGreen = self::srgbToLinear(hexdec(substr($sClean, 2, 2)) / 255.0);
        $fBlue = self::srgbToLinear(hexdec(substr($sClean, 4, 2)) / 255.0);

        // sRGB to XYZ (D65 reference white)
        $fX = ($fRed * 0.4124564 + $fGreen * 0.3575761 + $fBlue * 0.1804375) / 0.95047;
        $fY = ($fRed * 0.2126729 + $fGreen * 0.7151522 + $fBlue * 0.0721750) / 1.00000;
        $fZ = ($fRed * 0.0193339 + $fGreen * 0.1191920 + $fBlue * 0.9503041) / 1.08883;

        $fFx = self::labF($fX);
        $fFy = self::labF($fY);
        $fFz = self::labF($fZ);

        return [
            116.0 * $fFy - 16.0,
            500.0 * ($fFx - $fFy),
            200.0 * ($fFy - $fFz),
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $arLabA
     * @param  array{0: float, 1: float, 2: float}  $arLabB
     */
    public static function deltaE76(array $arLabA, array $arLabB): float
    {
        $fDl = $arLabA[0] - $arLabB[0];
        $fDa = $arLabA[1] - $arLabB[1];
        $fDb = $arLabA[2] - $arLabB[2];

        return sqrt($fDl * $fDl + $fDa * $fDa + $fDb * $fDb);
    }

    private static function srgbToLinear(float $fChannel): float
    {
        return $fChannel <= 0.04045
            ? $fChannel / 12.92
            : (($fChannel + 0.055) / 1.055) ** 2.4;
    }

    private static function labF(float $fValue): float
    {
        return $fValue > 0.008856
            ? $fValue ** (1.0 / 3.0)
            : (7.787 * $fValue) + (16.0 / 116.0);
    }

    /**
     * @return list<string>
     */
    private function normalizeFamilyFilter(mixed $mRaw): array
    {
        $arList = is_array($mRaw) ? $mRaw : (is_string($mRaw) ? [$mRaw] : []);
        $arClean = [];
        foreach ($arList as $mItem) {
            if (!is_string($mItem)) {
                continue;
            }
            $sClean = strtolower(trim($mItem));
            if ($sClean !== '') {
                $arClean[] = $sClean;
            }
        }

        return array_values(array_unique($arClean));
    }

    private function normalizeHex(mixed $mRaw): ?string
    {
        return is_string($mRaw) ? self::normalizeHexStatic($mRaw) : null;
    }

    private static function normalizeHexStatic(string $sRaw): ?string
    {
        $sTrimmed = strtoupper(trim($sRaw));
        if (preg_match('/\A#?([0-9A-F]{6})\z/', $sTrimmed, $arMatches) !== 1) {
            return null;
        }

        return '#' . $arMatches[1];
    }
}
