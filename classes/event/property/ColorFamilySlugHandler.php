<?php namespace Logingrupa\StoreExtender\Classes\Event\Property;

use Event;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;

/**
 * Class ColorFamilySlugHandler
 *
 * Pins the PropertyValue slug of every Color Family value to the STABLE
 * family slug from the color-lab taxonomy (families.json), instead of the
 * default slug derived from the Latvian display name. URLs
 * (?color=<slug>) and the FilterByPropertyStore cache key on the value
 * slug, so a display-name rename must never move it.
 *
 * PropertyValue::beforeValidate() regenerates the slug from the value on
 * EVERY save; the only supported override is the haltable
 * shopaholic.property.generate_value event this handler answers. The value
 * string is the only context the event carries, so the mapping is by exact
 * display name against the synced ColorFamilyMeta names.
 *
 * @package Logingrupa\StoreExtender\Classes\Event\Property
 */
class ColorFamilySlugHandler
{
    /**
     * PropertyValue::EVENT_GET_SLUG_VALUE - string literal so the handler
     * registers even when PropertiesShopaholic is absent (it never fires).
     */
    const EVENT_GENERATE_VALUE_SLUG = 'shopaholic.property.generate_value';

    /** @var array|null memoized lowercased name => slug map, see getSlugByNameMap() */
    protected static $arSlugByNameMap = null;

    /**
     * Forget the memoized name map. FamilyPropertySync calls this right after
     * rewriting ColorFamilyMeta, so the value saves that follow in the same
     * process resolve slugs against the fresh taxonomy, not the memo.
     * @return void
     */
    public static function resetMemo(): void
    {
        static::$arSlugByNameMap = null;
    }

    /**
     * Subscribe to the slug generation hook.
     */
    public function subscribe()
    {
        Event::listen(self::EVENT_GENERATE_VALUE_SLUG, function ($sValue, $sSlug) {
            return $this->resolveFamilySlug((string) $sValue);
        });
    }

    /**
     * The stable family slug for a value that IS a family display name in
     * any locale, or null to abstain (default slug generation continues).
     * @param string $sValue
     * @return string|null
     */
    public function resolveFamilySlug(string $sValue): ?string
    {
        if ($sValue === '') {
            return null;
        }

        $arSlugByName = $this->getSlugByNameMap();

        return $arSlugByName[mb_strtolower($sValue)] ?? null;
    }

    /**
     * Map of lowercased localized family display name => stable value slug,
     * from the synced companion table. Memoized per request - the slug hook
     * fires once per value save, but a sync run saves every family.
     * @return array
     */
    protected function getSlugByNameMap(): array
    {
        if (static::$arSlugByNameMap !== null) {
            return static::$arSlugByNameMap;
        }

        $arMap = [];
        if (!\Schema::hasTable('logingrupa_storeextender_color_family_meta')) {
            // pre-migration boot: an unrelated PropertyValue save (1C import)
            // must never crash on the missing companion table
            static::$arSlugByNameMap = $arMap;

            return $arMap;
        }
        foreach (ColorFamilyMeta::query()->get() as $obMeta) {
            foreach ((array) $obMeta->names as $sName) {
                if (is_string($sName) && $sName !== '') {
                    $arMap[mb_strtolower($sName)] = $obMeta->value_slug;
                }
            }
        }
        static::$arSlugByNameMap = $arMap;

        return $arMap;
    }
}
