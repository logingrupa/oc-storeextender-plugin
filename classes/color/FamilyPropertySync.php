<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lovata\Shopaholic\Models\Offer;
use Lovata\PropertiesShopaholic\Models\Property;
use Lovata\PropertiesShopaholic\Models\PropertyValue;
use Lovata\PropertiesShopaholic\Models\PropertyValueLink;
use Logingrupa\StoreExtender\Classes\Helper\ColorFamilyHelper;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;
use Logingrupa\StoreExtender\Classes\Event\Property\ColorFamilySlugHandler;

/**
 * Class FamilyPropertySync
 *
 * Property-side half of `storeextender:sync-offer-colors`: projects the
 * color-lab family taxonomy (families.json) onto the Shopaholic "Color
 * Family" select property. Owns, in order: the ColorFamilyMeta companion
 * rows (written FIRST, so ColorFamilySlugHandler can pin the stable family
 * slug during the value saves that follow), one PropertyValue per family,
 * and one PropertyValueLink per mapped offer.
 *
 * All link writes go through the MODEL, never the query builder -
 * PropertyValueLinkModelHandler invalidates FilterByPropertyStore caches on
 * model events. value_id may change in place (afterSave clears on it);
 * element_id must NEVER be rewritten in place (its cache key would go
 * stale), links are created/deleted instead.
 *
 * Fail-safe boundary: an empty family map returns zero stats and touches
 * nothing, and an offer whose family is unknown to the taxonomy keeps
 * whatever link it already has - a partial upstream payload must never
 * delete data.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class FamilyPropertySync
{
    const PROPERTY_CODE = 'color_family';
    const PROPERTY_NAME = 'Color Family';

    const OFFER_CHUNK_SIZE = 500;

    const LOCALE_DEFAULT = 'lv';
    const TRANSLATED_LOCALE_LIST = ['en', 'ru'];

    /**
     * Sync meta rows, the property, its values and the offer links from the
     * family taxonomy + the locally synced offer color map.
     *
     * @param array $arFamilyMap ['<slug>' => ['name' => string, 'names' => array, 'synonyms' => array, 'hex' => string]]
     * @param array $arOfferColorMap ['<offerUuid>' => ['family' => string, ...]]
     * @return array ['values' => int, 'links_created' => int, 'links_updated' => int, 'links_deleted' => int, 'meta' => int]
     */
    public function sync(array $arFamilyMap, array $arOfferColorMap): array
    {
        $arStats = [
            'values'        => 0,
            'links_created' => 0,
            'links_updated' => 0,
            'links_deleted' => 0,
            'meta'          => 0,
        ];

        // Fail-safe: an empty taxonomy must never delete values, links or meta
        if (empty($arFamilyMap)) {
            return $arStats;
        }

        $arStats['meta'] = $this->syncMetaList($arFamilyMap);
        ColorFamilyMatcher::forgetIndex();
        $obProperty = $this->upsertProperty();
        ColorFamilyHelper::forgetPropertyId();
        $arValueIdByFamily = $this->upsertValueList($arFamilyMap, $obProperty, $arStats);
        $this->syncLinkList($obProperty, $arValueIdByFamily, $arOfferColorMap, $arStats);

        return $arStats;
    }

    /**
     * Upsert one ColorFamilyMeta row per family (keyed by the stable slug),
     * delete rows whose slug left the taxonomy, then reset the slug
     * handler's memo so the value saves that follow see the fresh names.
     *
     * The family map's key order IS the families.json export order - the
     * pill/chip order contract - and is persisted as sort_order on every
     * upsert, because the by-slug upserts keep row ids stable and id order
     * would otherwise freeze the first sync's order forever. Skipped while
     * the column is not migrated yet (fail-safe: readers fall back to id
     * order).
     *
     * @param array $arFamilyMap
     * @return int
     */
    protected function syncMetaList(array $arFamilyMap): int
    {
        $bHasSortOrder = Schema::hasColumn((new ColorFamilyMeta)->getTable(), 'sort_order');

        $iMetaCount = 0;
        $arSlugList = [];
        foreach ($arFamilyMap as $sSlug => $arFamily) {
            $sSlug = (string) $sSlug;
            $sFamilyName = is_array($arFamily) && isset($arFamily['name']) ? (string) $arFamily['name'] : '';
            if ($sSlug === '' || $sFamilyName === '') {
                continue;
            }

            $obMeta = ColorFamilyMeta::query()->where('value_slug', $sSlug)->first();
            if (empty($obMeta)) {
                $obMeta = new ColorFamilyMeta(['value_slug' => $sSlug]);
            }
            $obMeta->family = mb_substr($sFamilyName, 0, 64);
            $obMeta->hex = $this->normalizeHex($arFamily['hex'] ?? null);
            $obMeta->names = (array) ($arFamily['names'] ?? []);
            $obMeta->synonyms = (array) ($arFamily['synonyms'] ?? []);
            if ($bHasSortOrder) {
                $obMeta->sort_order = $iMetaCount;
            }
            $obMeta->save();

            $arSlugList[] = $sSlug;
            $iMetaCount++;
        }

        ColorFamilyMeta::query()->whereNotIn('value_slug', $arSlugList)->delete();

        ColorFamilySlugHandler::resetMemo();

        return $iMetaCount;
    }

    /**
     * The "Color Family" select property, created on first sync.
     * external_id stays NULL by design: exempt from import deactivation and
     * invisible to 1C property matching.
     *
     * @return Property
     */
    protected function upsertProperty(): Property
    {
        $obProperty = Property::query()->where('code', self::PROPERTY_CODE)->first();
        if (!empty($obProperty)) {
            return $obProperty;
        }

        return Property::create([
            'name'   => self::PROPERTY_NAME,
            'code'   => self::PROPERTY_CODE,
            'type'   => Property::TYPE_SELECT,
            'active' => true,
        ]);
    }

    /**
     * Upsert one PropertyValue per family. The base value is the Latvian
     * display name (default locale); ColorFamilySlugHandler pins the slug to
     * the stable family slug during save. PropertyValue rows are GLOBAL
     * (shared across properties, found by value string), so reuse by value
     * is correct.
     *
     * @param array    $arFamilyMap
     * @param Property $obProperty
     * @param array    $arStats
     * @return array ['<familyName>' => <valueId>]
     */
    protected function upsertValueList(array $arFamilyMap, Property $obProperty, array &$arStats): array
    {
        $arValueIdByFamily = [];
        foreach ($arFamilyMap as $arFamily) {
            $sFamilyName = is_array($arFamily) && isset($arFamily['name']) ? (string) $arFamily['name'] : '';
            if ($sFamilyName === '') {
                continue;
            }

            $arNames = is_array($arFamily) ? (array) ($arFamily['names'] ?? []) : [];
            $sValue = (string) ($arNames[self::LOCALE_DEFAULT] ?? '');
            if ($sValue === '') {
                $sValue = (string) ($arNames['en'] ?? '');
            }
            if ($sValue === '') {
                $sValue = $sFamilyName;
            }

            $obValue = PropertyValue::getByValue($sValue)->first();
            if (empty($obValue)) {
                $obValue = new PropertyValue();
                $obValue->value = $sValue;
            }
            $obValue->save();

            $this->applyValueTranslationList($obValue, $arNames, $sValue);
            $this->attachValueToProperty($obProperty, (int) $obValue->id);

            $arValueIdByFamily[$sFamilyName] = (int) $obValue->id;
            $arStats['values']++;
        }

        return $arValueIdByFamily;
    }

    /**
     * Store the en/ru display names as RainLab translations of the value,
     * when present and different from the base value. Skipped silently when
     * the TranslatableModel behavior is absent (hermetic test environments
     * without RainLab.Translate).
     *
     * @param PropertyValue $obValue
     * @param array         $arNames
     * @param string        $sBaseValue
     * @return void
     */
    protected function applyValueTranslationList(PropertyValue $obValue, array $arNames, string $sBaseValue): void
    {
        if (!$obValue->methodExists('setAttributeTranslated')) {
            return;
        }
        if (!\Illuminate\Support\Facades\Schema::hasTable('rainlab_translate_attributes')) {
            // behavior attached but RainLab.Translate not migrated (hermetic
            // tests): saving a translation would crash on the missing table
            return;
        }

        $bTranslationSet = false;
        foreach (self::TRANSLATED_LOCALE_LIST as $sLocale) {
            $sName = (string) ($arNames[$sLocale] ?? '');
            if ($sName === '' || $sName === $sBaseValue) {
                continue;
            }
            $obValue->setAttributeTranslated('value', $sName, $sLocale);
            $bTranslationSet = true;
        }

        if ($bTranslationSet) {
            $obValue->save();
        }
    }

    /**
     * Attach the value to the property's variant list, mirroring what
     * CommonPropertyHelper::processPropertyValue() does for TYPE_SELECT.
     * Without this row PropertyValueLinkModelHandler::removeValueObject()
     * garbage-collects the family value the moment its last offer link goes.
     *
     * @param Property $obProperty
     * @param int      $iValueId
     * @return void
     */
    protected function attachValueToProperty(Property $obProperty, int $iValueId): void
    {
        $bAttached = DB::table('lovata_properties_shopaholic_variant_link')
            ->where('property_id', $obProperty->id)
            ->where('value_id', $iValueId)
            ->exists();
        if ($bAttached) {
            return;
        }

        $obProperty->property_value()->attach($iValueId);
    }

    /**
     * Bring the offer links to the desired state: one link per mapped offer.
     * Creates missing links, updates value_id/product_id in place, deletes
     * links whose offer left the map. An offer whose family is unknown to
     * the taxonomy contributes nothing AND keeps its existing link
     * (fail-safe against a partial upstream payload).
     *
     * @param Property $obProperty
     * @param array    $arValueIdByFamily
     * @param array    $arOfferColorMap
     * @param array    $arStats
     * @return void
     */
    protected function syncLinkList(Property $obProperty, array $arValueIdByFamily, array $arOfferColorMap, array &$arStats): void
    {
        $arDesiredByOfferId = [];
        $arFrozenOfferIdList = [];
        foreach (array_chunk(array_keys($arOfferColorMap), self::OFFER_CHUNK_SIZE) as $arUuidChunk) {
            $obOfferList = Offer::query()->whereIn('external_id', $arUuidChunk)->get(['id', 'product_id', 'external_id']);
            foreach ($obOfferList as $obOffer) {
                $arColor = $arOfferColorMap[$obOffer->external_id] ?? null;
                $sFamilyName = is_array($arColor) && isset($arColor['family']) ? (string) $arColor['family'] : '';
                if (!isset($arValueIdByFamily[$sFamilyName])) {
                    // the offer IS mapped, only to a family this payload does
                    // not know - leave its current link untouched
                    $arFrozenOfferIdList[(int) $obOffer->id] = true;
                    continue;
                }

                $arDesiredByOfferId[(int) $obOffer->id] = [
                    'product_id' => (int) $obOffer->product_id,
                    'value_id'   => $arValueIdByFamily[$sFamilyName],
                ];
            }
        }

        $obExistingLinkList = PropertyValueLink::query()
            ->where('property_id', $obProperty->id)
            ->where('element_type', Offer::class)
            ->get();

        $arSatisfiedOfferIdList = [];
        foreach ($obExistingLinkList as $obLink) {
            $iOfferId = (int) $obLink->element_id;
            if (isset($arFrozenOfferIdList[$iOfferId])) {
                continue;
            }

            if (!isset($arDesiredByOfferId[$iOfferId]) || isset($arSatisfiedOfferIdList[$iOfferId])) {
                // offer left the map, or a duplicate link for one already kept
                $obLink->delete();
                $arStats['links_deleted']++;
                continue;
            }

            $arSatisfiedOfferIdList[$iOfferId] = true;
            $arDesired = $arDesiredByOfferId[$iOfferId];
            if ((int) $obLink->value_id === $arDesired['value_id'] && (int) $obLink->product_id === $arDesired['product_id']) {
                continue;
            }

            // update in place - element_id is never rewritten (cache contract)
            $obLink->value_id = $arDesired['value_id'];
            $obLink->product_id = $arDesired['product_id'];
            $obLink->save();
            $arStats['links_updated']++;
        }

        foreach ($arDesiredByOfferId as $iOfferId => $arDesired) {
            if (isset($arSatisfiedOfferIdList[$iOfferId])) {
                continue;
            }

            PropertyValueLink::create([
                'value_id'     => $arDesired['value_id'],
                'property_id'  => $obProperty->id,
                'product_id'   => $arDesired['product_id'],
                'element_id'   => $iOfferId,
                'element_type' => Offer::class,
            ]);
            $arStats['links_created']++;
        }
    }

    /**
     * Strict #RRGGBB or null, same contract as the offer color rows.
     *
     * @param mixed $sHex
     * @return string|null
     */
    protected function normalizeHex($sHex): ?string
    {
        if (is_string($sHex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $sHex) === 1) {
            return $sHex;
        }

        return null;
    }
}
