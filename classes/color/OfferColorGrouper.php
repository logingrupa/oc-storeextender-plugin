<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Logingrupa\StoreExtender\Classes\Helper\ColorFamilyHelper;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;

/**
 * Class OfferColorGrouper
 *
 * Pure mapping ONLY: takes an offer list + color map and returns offers
 * grouped by family. An offer sits in every family of its families list (its
 * primary color first, then extra colors and collections). Collection groups
 * come first in family order, then color groups ordered by their minimum hue
 * (family name as tiebreak); offers with no color entry land in a final
 * "Other" group. Inside a color group rows run by hue then lightness, inside
 * a collection group by shade code first. No HTTP, no cache, no DB - fully
 * deterministic for identical input.
 *
 * Offers only need public `id` and `external_id` properties (OfferItem or
 * any stub), so the class is reusable on product pages and in unit tests.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class OfferColorGrouper
{
    const OTHER_FAMILY = 'Other';

    /**
     * Group offers by family
     *
     * sHex per group = hex of the group's first offer after sorting (the
     * family hex for a collection when the meta has one) - a deterministic
     * representative swatch for filter chips. sGradient is the drawn gradient
     * of a family that has one, else null.
     *
     * @param iterable $obOfferList list of offers exposing id + external_id
     * @param array    $arColorMap  ['<offerUuid>' => ['family' => string, 'families' => string[], 'shade_code' => string|null, 'hex' => string, 'hue' => float, 'lightness' => float]]
     * @param array    $arFamilyMeta ColorFamilyMatcher::metaByFamilyName() - kind, position, hex and gradient per family name
     * @return array [['sFamily' => string|null, 'sHex' => string|null, 'sGradient' => string|null, 'sKind' => string, 'arOfferList' => array, 'arPrimaryOfferIdList' => int[]]] - sFamily null = ungrouped fallback
     */
    public function group(iterable $obOfferList, array $arColorMap, array $arFamilyMeta = []): array
    {
        $arOfferList = [];
        foreach ($obOfferList as $obOffer) {
            $arOfferList[] = $obOffer;
        }

        if (empty($arColorMap)) {
            return [$this->makeGroup(null, null, null, ColorFamilyMeta::KIND_COLOR, $arOfferList, $arOfferList)];
        }

        $arFamilyBucketList = [];
        $arOtherList = [];
        foreach ($arOfferList as $obOffer) {
            $arColor = $this->findColor($obOffer, $arColorMap);
            if ($arColor === null) {
                $arOtherList[] = $obOffer;
                continue;
            }

            $sPrimary = (string) $arColor['family'];
            foreach ($this->resolveFamilyNameList($arColor) as $sFamily) {
                $arFamilyBucketList[$sFamily][] = [
                    'obOffer'    => $obOffer,
                    'fHue'       => (float) $arColor['hue'],
                    'fLightness' => (float) $arColor['lightness'],
                    'sHex'       => $this->sanitizeHex($arColor),
                    'sShadeCode' => isset($arColor['shade_code']) && is_string($arColor['shade_code']) ? $arColor['shade_code'] : null,
                    'bPrimary'   => $sFamily === $sPrimary,
                ];
            }
        }

        $arGroupList = $this->buildGroupList($arFamilyBucketList, $arFamilyMeta);
        if (!empty($arOtherList)) {
            $arGroupList[] = $this->makeGroup(self::OTHER_FAMILY, null, null, ColorFamilyMeta::KIND_COLOR, $arOtherList, $arOtherList);
        }

        return $arGroupList;
    }

    /**
     * @param string|null $sFamily
     * @param string|null $sHex
     * @param string|null $sGradient
     * @param string      $sKind
     * @param array       $arOfferList
     * @param array       $arPrimaryOfferList the offers of arOfferList whose primary color this group is
     * @return array
     */
    protected function makeGroup(?string $sFamily, ?string $sHex, ?string $sGradient, string $sKind, array $arOfferList, array $arPrimaryOfferList): array
    {
        return [
            'sFamily'              => $sFamily,
            'sHex'                 => $sHex,
            'sGradient'            => $sGradient,
            'sKind'                => $sKind,
            'arOfferList'          => $arOfferList,
            'arPrimaryOfferIdList' => array_map(fn ($obOffer) => (int) $obOffer->id, $arPrimaryOfferList),
        ];
    }

    /**
     * The families of one color entry, primary first, no duplicates. An entry
     * without a usable families list is its primary alone.
     *
     * @param array $arColor
     * @return string[]
     */
    protected function resolveFamilyNameList(array $arColor): array
    {
        $arFamilyNameList = [(string) $arColor['family']];
        foreach (is_array($arColor['families'] ?? null) ? $arColor['families'] : [] as $mFamily) {
            if (is_string($mFamily) && $mFamily !== '' && !in_array($mFamily, $arFamilyNameList, true)) {
                $arFamilyNameList[] = $mFamily;
            }
        }

        return $arFamilyNameList;
    }

    /**
     * Resolve a color entry for an offer, null when absent or malformed
     *
     * @param object $obOffer
     * @param array  $arColorMap
     * @return array|null
     */
    protected function findColor($obOffer, array $arColorMap): ?array
    {
        $sExternalId = (string) $obOffer->external_id;
        if ($sExternalId === '') {
            return null;
        }

        // legacy 1C composite "parentUuid#offerUuid" - API keys are bare offer UUIDs
        $iHashPosition = strrpos($sExternalId, '#');
        $sUuid = $iHashPosition === false ? $sExternalId : substr($sExternalId, $iHashPosition + 1);

        $arColor = isset($arColorMap[$sUuid]) ? $arColorMap[$sUuid] : null;
        if (!is_array($arColor) || empty($arColor['family']) || !is_string($arColor['family'])) {
            return null;
        }
        if (!isset($arColor['hue'], $arColor['lightness']) || !is_numeric($arColor['hue']) || !is_numeric($arColor['lightness'])) {
            return null;
        }

        return $arColor;
    }

    /**
     * Validate hex swatch value - goes into HTML inline style, so only a
     * strict #RRGGBB survives, anything else becomes null
     *
     * @param array $arColor
     * @return string|null
     */
    protected function sanitizeHex(array $arColor): ?string
    {
        if (!isset($arColor['hex']) || !is_string($arColor['hex'])) {
            return null;
        }

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $arColor['hex']) === 1 ? $arColor['hex'] : null;
    }

    /**
     * Sort family buckets: collection groups first by family position, then
     * color groups by min hue asc (family name tiebreak). Rows inside a color
     * group by hue asc, lightness asc, offer id asc; inside a collection by
     * shade code (natural, unnumbered last) before those.
     *
     * @param array $arFamilyBucketList ['<family>' => [['obOffer' => object, 'fHue' => float, 'fLightness' => float, 'sHex' => string|null, 'sShadeCode' => string|null, 'bPrimary' => bool]]]
     * @param array $arFamilyMeta
     * @return array
     */
    protected function buildGroupList(array $arFamilyBucketList, array $arFamilyMeta): array
    {
        $arSortableList = [];
        foreach ($arFamilyBucketList as $sFamily => $arRowList) {
            $sFamily = (string) $sFamily;
            $arMeta = $arFamilyMeta[$sFamily] ?? [];
            $bCollection = ($arMeta['kind'] ?? '') === ColorFamilyMeta::KIND_COLLECTION;

            usort($arRowList, function (array $arA, array $arB) use ($bCollection): int {
                if ($bCollection) {
                    $iShadeOrder = ColorFamilyHelper::compareShadeCodes($arA['sShadeCode'], $arB['sShadeCode']);
                    if ($iShadeOrder !== 0) {
                        return $iShadeOrder;
                    }
                }

                return [$arA['fHue'], $arA['fLightness'], (int) $arA['obOffer']->id]
                    <=> [$arB['fHue'], $arB['fLightness'], (int) $arB['obOffer']->id];
            });

            $arPrimaryRowList = array_filter($arRowList, fn (array $arRow) => $arRow['bPrimary']);
            $arSortableList[] = [
                'bCollection' => $bCollection,
                'iPosition'   => (int) ($arMeta['iPosition'] ?? 0),
                'fMinHue'     => $arRowList[0]['fHue'],
                'sFamily'     => $sFamily,
                'arGroup'     => $this->makeGroup(
                    $sFamily,
                    $bCollection && !empty($arMeta['hex']) ? $arMeta['hex'] : $arRowList[0]['sHex'],
                    ColorFamilyHelper::buildSwatchBackground(null, $arMeta['gradient'] ?? null),
                    $bCollection ? ColorFamilyMeta::KIND_COLLECTION : ColorFamilyMeta::KIND_COLOR,
                    array_column($arRowList, 'obOffer'),
                    array_column($arPrimaryRowList, 'obOffer')
                ),
            ];
        }

        usort($arSortableList, function (array $arA, array $arB): int {
            if ($arA['bCollection'] !== $arB['bCollection']) {
                return $arA['bCollection'] ? -1 : 1;
            }
            if ($arA['bCollection']) {
                return [$arA['iPosition'], $arA['sFamily']] <=> [$arB['iPosition'], $arB['sFamily']];
            }

            return [$arA['fMinHue'], $arA['sFamily']] <=> [$arB['fMinHue'], $arB['sFamily']];
        });

        return array_column($arSortableList, 'arGroup');
    }
}
