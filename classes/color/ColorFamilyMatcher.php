<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Logingrupa\StoreExtender\Models\ColorFamilyMeta;

/**
 * Class ColorFamilyMatcher
 *
 * The family term index behind the search sheet's pill row. Terms per
 * family: every localized display name, every per-locale synonym and the
 * current PropertyValue base value for the family slug (covers admin-edited
 * names), each stored both raw lowercase and normalized with mb_strtolower
 * + Str::ascii ('sarkanā' beside 'sarkana', 'красный' beside 'krasnyj').
 * The vocabulary ships to the theme as data-terms, where the client-side
 * pill filter matches it per keystroke - the server no longer matches
 * queries itself.
 *
 * Read on storefront renders, so the term index is memoized per request AND
 * kept in Laravel Cache keyed by the meta table's max(updated_at) + row
 * count. Missing or empty table = no families (fail-safe inactive), never
 * an error.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorFamilyMatcher
{
    /** Mixed into the cache key beside the content stamp: bump whenever
     * buildIndex()'s output shape changes, because the stamp only sees data
     * edits and would serve the pre-deploy index for CACHE_TTL_SECONDS */
    const INDEX_VERSION = 2;

    const CACHE_TTL_SECONDS = 3600;
    const CACHE_KEY_PREFIX = 'storeextender.color_family_matcher.index.';

    const LOCALE_DEFAULT = 'lv';

    /** Locale tried before LOCALE_DEFAULT when the active locale has no
     * name - a locale outside the export (nb-no on the .no store) reads
     * better in English than in Latvian */
    const LOCALE_NAME_FALLBACK = 'en';

    /** @var array|null per-request memo of the built term index */
    protected static $arIndexMemo = null;

    /**
     * Every synced family, resolved for one locale - the source of the
     * search sheet's all-family pill row. Fail-safe: no meta rows = empty
     * list.
     *
     * @param string $sLocale
     * @return array ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array]]
     */
    public function families(string $sLocale = self::LOCALE_DEFAULT): array
    {
        return static::resolveEntries($this->getIndex(), $sLocale);
    }

    /**
     * Collapse raw index entries to display data for one locale: requested
     * locale name, else English, else the default locale, else the raw
     * family name. The full term list rides along for the storefront's
     * client-side pill filter (per-keystroke, no round trip).
     *
     * @param array  $arEntryList index entries keyed by value slug
     * @param string $sLocale
     * @return array ['<valueSlug>' => ['name' => string, 'hex' => string|null, 'terms' => array]]
     */
    public static function resolveEntries(array $arEntryList, string $sLocale): array
    {
        $arResult = [];
        foreach ($arEntryList as $sSlug => $arEntry) {
            $arNames = (array) ($arEntry['names'] ?? []);
            $sName = (string) ($arNames[$sLocale] ?? '');
            if ($sName === '') {
                $sName = (string) ($arNames[self::LOCALE_NAME_FALLBACK] ?? '');
            }
            if ($sName === '') {
                $sName = (string) ($arNames[self::LOCALE_DEFAULT] ?? '');
            }
            if ($sName === '') {
                $sName = (string) ($arEntry['family'] ?? '');
            }

            $arResult[$sSlug] = [
                'name'  => $sName,
                'hex'   => $arEntry['hex'] ?? null,
                'terms' => (array) ($arEntry['terms'] ?? []),
            ];
        }

        return $arResult;
    }

    /**
     * Normalize a term onto one lowercase ASCII alphabet: 'Sarkanā' and
     * 'sarkana' converge, 'красный' becomes 'krasnyj'. Punctuation
     * collapses to spaces so it cannot defeat a whole-word comparison.
     * buildIndex() stores this form beside the raw lowercase one, so a
     * Latin query in the client-side pill filter meets Cyrillic vocabulary.
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
     * The term index, memoized per request and cached against
     * INDEX_VERSION + the meta table's content stamp.
     *
     * @return array
     */
    protected function getIndex(): array
    {
        if (static::$arIndexMemo !== null) {
            return static::$arIndexMemo;
        }

        // one information-schema query per request at most: $arIndexMemo
        // short-circuits every later call in the same process
        if (!Schema::hasTable((new ColorFamilyMeta)->getTable())) {
            // pre-migration boot: the matcher is simply inactive
            static::$arIndexMemo = [];

            return static::$arIndexMemo;
        }

        $sStamp = self::INDEX_VERSION.'|'.ColorFamilyMeta::query()->max('updated_at').'|'.ColorFamilyMeta::query()->count();
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
     * base values. Index order follows sort_order - the families.json export
     * position the sync persists - so pills and chips render in the order
     * the color-lab decided; NULL positions (pre-migration data) and a
     * missing column fall back to id order, the pre-contract behavior.
     *
     * @return array ['<valueSlug>' => ['terms' => array, 'names' => array, 'family' => string, 'hex' => string|null]]
     */
    protected function buildIndex(): array
    {
        $obMetaQuery = ColorFamilyMeta::query();
        if (Schema::hasColumn((new ColorFamilyMeta)->getTable(), 'sort_order')) {
            $obMetaQuery->orderByRaw('sort_order IS NULL')->orderBy('sort_order')->orderBy('id');
        }
        $obMetaList = $obMetaQuery->get();
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
                // the raw lowercase form keeps the original alphabet for the
                // storefront's client-side pill filter (a Cyrillic query must
                // meet 'красный' - JS cannot transliterate); the normalized
                // form lets a Latin query meet the same vocabulary
                $sRawLowercase = mb_strtolower($sTerm);
                if ($sRawLowercase !== '') {
                    $arTermList[$sRawLowercase] = true;
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
