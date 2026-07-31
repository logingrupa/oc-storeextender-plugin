<?php namespace Logingrupa\StoreExtender\Classes\Color;

/**
 * Class OfferColorGrouper
 *
 * Pure mapping ONLY: takes an offer list + color map and returns offers
 * grouped by color family, ordered by hue then lightness inside each group.
 * Offers with no color entry land in a final "Other" group. Groups are
 * ordered by their minimum hue (family name as tiebreak), "Other" last.
 * No HTTP, no cache, no DB - fully deterministic for identical input.
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
     * Group offers by color family
     *
     * sHex per group = hex of the group's first offer after sorting - a
     * deterministic representative swatch for filter chips.
     *
     * @param iterable $obOfferList list of offers exposing id + external_id
     * @param array    $arColorMap  ['<offerUuid>' => ['family' => string, 'hex' => string, 'hue' => float, 'lightness' => float]]
     * @return array [['sFamily' => string|null, 'sHex' => string|null, 'arOfferList' => array]] - sFamily null = ungrouped fallback
     */
    public function group(iterable $obOfferList, array $arColorMap): array
    {
        $arOfferList = [];
        foreach ($obOfferList as $obOffer) {
            $arOfferList[] = $obOffer;
        }

        if (empty($arColorMap)) {
            return [['sFamily' => null, 'sHex' => null, 'arOfferList' => $arOfferList]];
        }

        $arFamilyBucketList = [];
        $arOtherList = [];
        foreach ($arOfferList as $obOffer) {
            $arColor = $this->findColor($obOffer, $arColorMap);
            if ($arColor === null) {
                $arOtherList[] = $obOffer;
                continue;
            }

            $arFamilyBucketList[(string) $arColor['family']][] = [
                'obOffer'    => $obOffer,
                'fHue'       => (float) $arColor['hue'],
                'fLightness' => (float) $arColor['lightness'],
                'sHex'       => $this->sanitizeHex($arColor),
            ];
        }

        $arGroupList = $this->buildGroupList($arFamilyBucketList);
        if (!empty($arOtherList)) {
            $arGroupList[] = ['sFamily' => self::OTHER_FAMILY, 'sHex' => null, 'arOfferList' => $arOtherList];
        }

        return $arGroupList;
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
     * Sort family buckets: groups by min hue asc (family name tiebreak),
     * rows inside a group by hue asc, lightness asc, offer id asc
     *
     * @param array $arFamilyBucketList ['<family>' => [['obOffer' => object, 'fHue' => float, 'fLightness' => float]]]
     * @return array
     */
    protected function buildGroupList(array $arFamilyBucketList): array
    {
        $arSortableList = [];
        foreach ($arFamilyBucketList as $sFamily => $arRowList) {
            usort($arRowList, function (array $arA, array $arB): int {
                return [$arA['fHue'], $arA['fLightness'], (int) $arA['obOffer']->id]
                    <=> [$arB['fHue'], $arB['fLightness'], (int) $arB['obOffer']->id];
            });

            $arSortableList[] = [
                'sFamily'     => $sFamily,
                'fMinHue'     => $arRowList[0]['fHue'],
                'sHex'        => $arRowList[0]['sHex'],
                'arOfferList' => array_column($arRowList, 'obOffer'),
            ];
        }

        usort($arSortableList, function (array $arA, array $arB): int {
            return [$arA['fMinHue'], $arA['sFamily']] <=> [$arB['fMinHue'], $arB['sFamily']];
        });

        return array_map(function (array $arGroup): array {
            return ['sFamily' => $arGroup['sFamily'], 'sHex' => $arGroup['sHex'], 'arOfferList' => $arGroup['arOfferList']];
        }, $arSortableList);
    }
}
