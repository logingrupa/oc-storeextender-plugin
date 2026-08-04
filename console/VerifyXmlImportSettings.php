<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Class VerifyXmlImportSettings
 *
 * Assert the 1C XML import settings are sane on every site of this
 * deployment, and exit non-zero when they are not.
 *
 * This exists because the settings silently drifted for months. system_settings
 * is deliberately excluded from every data migration, since it holds payment
 * gateway credentials, so config changed on one box never travels to another
 * and nothing anywhere notices. On 2026-08-04 the category import on v2 was
 * found running with category_path_to_list = "//Группа", which matches a
 * Группа node at ANY depth: all 27 nodes in the feed were walked as though
 * they were top level, instead of the 5 real roots the correct path selects.
 * The category field map was also missing "children", which is what recurses
 * into the tree, so the hierarchy could not be rebuilt from the feed at all.
 *
 * There were two duplicate rows as well. SettingModel reads with ->first()
 * and no ORDER BY, so where a site has more than one row, which one wins is
 * undefined - site 2 held the CORRECT category path in a row that never won.
 *
 * Everything checked here is a structural invariant of the feed format, not a
 * preference: a descendant-or-self path cannot address the group list, and an
 * import that cannot see "children" cannot build a tree.
 *
 * @package Logingrupa\StoreExtender\Console
 */
class VerifyXmlImportSettings extends Command
{
    const SETTINGS_ITEM = 'lovata_shopaholic_xml_import_settings';

    /** Category fields without which the tree and its codes cannot be built. */
    const REQUIRED_CATEGORY_FIELD_LIST = ['external_id', 'name', 'children', 'code'];

    /** Product fields the storefront renders that only the feed supplies. */
    const REQUIRED_PRODUCT_FIELD_LIST = [
        'external_id',
        'name',
        'preview_text',
        'warning',
        'ingredients',
        'manufacturer',
    ];

    /** @var string */
    protected $signature = 'storeextender:verify-xml-import-settings';

    /** @var string */
    protected $description = 'Assert the 1C XML import settings are complete and unambiguous on every site';

    public function handle(): int
    {
        $arRowList = DB::table('system_settings')
            ->where('item', self::SETTINGS_ITEM)
            ->orderBy('id')
            ->get();

        if ($arRowList->isEmpty()) {
            $this->error('No ' . self::SETTINGS_ITEM . ' row exists at all.');

            return self::FAILURE;
        }

        $arProblemList = array_merge(
            $this->findDuplicateSiteProblemList($arRowList),
            $this->findRowProblemList($arRowList)
        );

        if (!empty($arProblemList)) {
            foreach ($arProblemList as $sProblem) {
                $this->error($sProblem);
            }
            $this->newLine();
            $this->error(count($arProblemList) . ' problem(s) found.');

            return self::FAILURE;
        }

        $this->info('XML import settings are complete on all ' . $arRowList->count() . ' site row(s).');

        return self::SUCCESS;
    }

    /**
     * More than one row for a site means the effective value is whatever the
     * database happened to return first.
     *
     * @param \Illuminate\Support\Collection $arRowList
     * @return string[]
     */
    protected function findDuplicateSiteProblemList($arRowList): array
    {
        $arProblemList = [];
        $arRowIdListPerSite = [];

        foreach ($arRowList as $obRow) {
            $arRowIdListPerSite[(int) $obRow->site_id][] = (int) $obRow->id;
        }

        foreach ($arRowIdListPerSite as $iSiteId => $arRowIdList) {
            if (count($arRowIdList) > 1) {
                $arProblemList[] = sprintf(
                    'site %d has %d settings rows (ids %s); SettingModel reads one of them'
                        . ' with no ORDER BY, so the effective value is undefined',
                    $iSiteId,
                    count($arRowIdList),
                    implode(', ', $arRowIdList)
                );
            }
        }

        return $arProblemList;
    }

    /**
     * @param \Illuminate\Support\Collection $arRowList
     * @return string[]
     */
    protected function findRowProblemList($arRowList): array
    {
        $arProblemList = [];

        foreach ($arRowList as $obRow) {
            $arValue = json_decode($obRow->value, true);
            if (!is_array($arValue)) {
                $arProblemList[] = "row {$obRow->id} does not hold a JSON object";
                continue;
            }

            $sPrefix = "site {$obRow->site_id} (row {$obRow->id}): ";
            $sCategoryPath = (string) ($arValue['category_path_to_list'] ?? '');

            if ($sCategoryPath === '') {
                $arProblemList[] = $sPrefix . 'category_path_to_list is empty';
            } elseif (str_starts_with($sCategoryPath, '//')) {
                // A descendant-or-self path matches nested groups as well as
                // the real roots, which flattens the tree
                $arProblemList[] = $sPrefix . 'category_path_to_list is the wildcard "'
                    . $sCategoryPath . '"; it must address the group list directly';
            }

            $arProblemList = array_merge(
                $arProblemList,
                $this->findMissingFieldProblemList(
                    $sPrefix,
                    'category',
                    $arValue['category'] ?? [],
                    self::REQUIRED_CATEGORY_FIELD_LIST
                ),
                $this->findMissingFieldProblemList(
                    $sPrefix,
                    'product',
                    $arValue['product'] ?? [],
                    self::REQUIRED_PRODUCT_FIELD_LIST
                )
            );
        }

        return $arProblemList;
    }

    /**
     * @param string $sPrefix
     * @param string $sMapName
     * @param array $arFieldMap
     * @param string[] $arRequiredFieldList
     * @return string[]
     */
    protected function findMissingFieldProblemList(
        string $sPrefix,
        string $sMapName,
        array $arFieldMap,
        array $arRequiredFieldList
    ): array {
        $arPresentFieldList = [];
        foreach ($arFieldMap as $arFieldData) {
            if (is_array($arFieldData) && !empty($arFieldData['field'])) {
                $arPresentFieldList[] = $arFieldData['field'];
            }
        }

        $arMissingFieldList = array_diff($arRequiredFieldList, $arPresentFieldList);
        if (empty($arMissingFieldList)) {
            return [];
        }

        return [
            $sPrefix . $sMapName . ' field map is missing: ' . implode(', ', $arMissingFieldList),
        ];
    }
}
