<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;

/**
 * Class ColorFamilyMatcher
 *
 * Answers "which color families does this search query name?" from the
 * synced ColorFamilyMeta rows. Terms per family: every localized display
 * name, every per-locale synonym and the current PropertyValue base value
 * for the family slug (covers admin-edited names). Both query and terms are
 * normalized with mb_strtolower + Str::ascii, so Latvian diacritics and
 * Cyrillic transliterate onto one alphabet ('sarkanā' meets 'sarkans',
 * 'красный' meets 'krasnyj') and a term matches only as a whole word inside
 * the query.
 *
 * Runs on every search keystroke server-side, so the term index is memoized
 * per request AND kept in Laravel Cache keyed by the meta table's
 * max(updated_at) + row count. Missing or empty table = no matches
 * (fail-safe inactive), never an error.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorFamilyMatcher
{
    const QUERY_MIN_LENGTH = 3;

    const CACHE_TTL_SECONDS = 3600;
    const CACHE_KEY_PREFIX = 'storeextender.color_family_matcher.index.';

    const LOCALE_DEFAULT = 'lv';

    /** @var array|null per-request memo of the built term index */
    protected static $arIndexMemo = null;

    /**
     * Match the query against the family term index.
     *
     * @param string $sQuery
     * @param string $sLocale
     * @return array ['<valueSlug>' => ['name' => string, 'hex' => string|null]]
     */
    public function match(string $sQuery, string $sLocale = self::LOCALE_DEFAULT): array
    {
        $arIndex = $this->getIndex();
        if (empty($arIndex)) {
            return [];
        }

        return static::resolveEntries(static::matchAgainstIndex($sQuery, $arIndex), $sLocale);
    }

    /**
     * Every synced family, resolved for one locale - the matcher-less
     * counterpart of match() behind the search sheet's all-family pill row.
     * Same fail-safe: no meta rows = empty list.
     *
     * @param string $sLocale
     * @return array ['<valueSlug>' => ['name' => string, 'hex' => string|null]]
     */
    public function families(string $sLocale = self::LOCALE_DEFAULT): array
    {
        return static::resolveEntries($this->getIndex(), $sLocale);
    }

    /**
     * Collapse raw index entries to display data for one locale: requested
     * locale name, else the default locale, else the raw family name.
     *
     * @param array  $arEntryList index entries keyed by value slug
     * @param string $sLocale
     * @return array ['<valueSlug>' => ['name' => string, 'hex' => string|null]]
     */
    public static function resolveEntries(array $arEntryList, string $sLocale): array
    {
        $arResult = [];
        foreach ($arEntryList as $sSlug => $arEntry) {
            $arNames = (array) ($arEntry['names'] ?? []);
            $sName = (string) ($arNames[$sLocale] ?? '');
            if ($sName === '') {
                $sName = (string) ($arNames[self::LOCALE_DEFAULT] ?? '');
            }
            if ($sName === '') {
                $sName = (string) ($arEntry['family'] ?? '');
            }

            $arResult[$sSlug] = [
                'name' => $sName,
                'hex'  => $arEntry['hex'] ?? null,
            ];
        }

        return $arResult;
    }

    /**
     * Normalize a term or query onto one lowercase ASCII alphabet:
     * 'Sarkanā' and 'sarkana' converge, 'красный' becomes 'krasnyj'.
     * Punctuation collapses to spaces so it cannot defeat the whole-word
     * rule.
     *
     * @param string $sTerm
     * @return string
     */
    public static function normalizeTerm(string $sTerm): string
    {
        $sTerm = mb_strtolower($sTerm);
        // Str::ascii renders 'й' as 'i' ('krasnyi'), but a user typing the
        // transliteration writes 'j' ('krasnyj') - map it first so both meet
        $sTerm = str_replace('й', 'j', $sTerm);
        $sTerm = Str::ascii($sTerm);
        $sTerm = preg_replace('/[^a-z0-9]+/', ' ', $sTerm);

        return trim((string) $sTerm);
    }

    /**
     * Pure matching rule against a prebuilt index. A family matches when any
     * of its normalized terms appears as a whole-word sequence inside the
     * normalized query. Queries shorter than QUERY_MIN_LENGTH match nothing.
     *
     * @param string $sQuery
     * @param array  $arIndex ['<valueSlug>' => ['terms' => array, ...]]
     * @return array matched index entries, keyed by slug
     */
    public static function matchAgainstIndex(string $sQuery, array $arIndex): array
    {
        $sQuery = static::normalizeTerm($sQuery);
        if (mb_strlen($sQuery) < self::QUERY_MIN_LENGTH) {
            return [];
        }

        $sPaddedQuery = ' '.$sQuery.' ';
        $arMatchedList = [];
        foreach ($arIndex as $sSlug => $arEntry) {
            foreach ((array) ($arEntry['terms'] ?? []) as $sTerm) {
                if ($sTerm !== '' && strpos($sPaddedQuery, ' '.$sTerm.' ') !== false) {
                    $arMatchedList[$sSlug] = $arEntry;
                    break;
                }
            }
        }

        return $arMatchedList;
    }

    /**
     * The term index, memoized per request and cached against the meta
     * table's content stamp.
     *
     * @return array
     */
    protected function getIndex(): array
    {
        if (static::$arIndexMemo !== null) {
            return static::$arIndexMemo;
        }

        if (!Schema::hasTable('logingrupa_storeextender_color_family_meta')) {
            // pre-migration boot: the matcher is simply inactive
            static::$arIndexMemo = [];

            return static::$arIndexMemo;
        }

        $sStamp = (string) ColorFamilyMeta::query()->max('updated_at').'|'.ColorFamilyMeta::query()->count();
        static::$arIndexMemo = (array) Cache::remember(
            self::CACHE_KEY_PREFIX.md5($sStamp),
            self::CACHE_TTL_SECONDS,
            function () {
                return $this->buildIndex();
            }
        );

        return static::$arIndexMemo;
    }

    /**
     * Build the term index from ColorFamilyMeta + the current PropertyValue
     * base values.
     *
     * @return array ['<valueSlug>' => ['terms' => array, 'names' => array, 'family' => string, 'hex' => string|null]]
     */
    protected function buildIndex(): array
    {
        $obMetaList = ColorFamilyMeta::query()->get();
        if ($obMetaList->isEmpty()) {
            return [];
        }

        $arBaseValueBySlug = $this->getBaseValueBySlug($obMetaList->pluck('value_slug')->all());

        $arIndex = [];
        foreach ($obMetaList as $obMeta) {
            $arRawTermList = [];
            foreach ((array) $obMeta->names as $sName) {
                $arRawTermList[] = $sName;
            }
            foreach ((array) $obMeta->synonyms as $arLocaleSynonymList) {
                foreach ((array) $arLocaleSynonymList as $sSynonym) {
                    $arRawTermList[] = $sSynonym;
                }
            }
            if (isset($arBaseValueBySlug[$obMeta->value_slug])) {
                $arRawTermList[] = $arBaseValueBySlug[$obMeta->value_slug];
            }

            $arTermList = [];
            foreach ($arRawTermList as $sTerm) {
                if (!is_string($sTerm)) {
                    continue;
                }
                $sNormalized = static::normalizeTerm($sTerm);
                if ($sNormalized !== '') {
                    $arTermList[$sNormalized] = true;
                }
            }
            if (empty($arTermList)) {
                continue;
            }

            $arIndex[$obMeta->value_slug] = [
                'terms'  => array_keys($arTermList),
                'names'  => (array) $obMeta->names,
                'family' => $obMeta->family,
                'hex'    => $obMeta->hex,
            ];
        }

        return $arIndex;
    }

    /**
     * Current PropertyValue base value per family slug, so an admin-edited
     * display name keeps matching. Optional term source: skipped silently
     * when PropertiesShopaholic is absent or not migrated.
     *
     * @param array $arSlugList
     * @return array ['<valueSlug>' => string]
     */
    protected function getBaseValueBySlug(array $arSlugList): array
    {
        if (!class_exists(\Lovata\PropertiesShopaholic\Models\PropertyValue::class)) {
            return [];
        }
        if (!Schema::hasTable('lovata_properties_shopaholic_values')) {
            return [];
        }

        try {
            return \Lovata\PropertiesShopaholic\Models\PropertyValue::query()
                ->whereIn('slug', $arSlugList)
                ->pluck('value', 'slug')
                ->all();
        } catch (\Throwable $obException) {
            // boundary fail-safe: a search keystroke must never 500 over an
            // optional term source
            return [];
        }
    }
}
