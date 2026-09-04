<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Log;
use Lovata\Shopaholic\Models\Category;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Classes\Store\CategoryListStore;

/**
 * Sorts category siblings by the `code` column (1C Наименование with A/B/C/1/2/3
 * order prefixes). Categories without a code keep their position after coded ones.
 * Run after every category import so the tree order always follows 1C prefixes.
 */
class CategorySorter
{
    /**
     * Sort all sibling groups by code (natural, case-insensitive).
     * @return int number of move operations performed
     */
    public static function sort(): int
    {
        $arAll = Category::select(['id', 'parent_id', 'code', 'name', 'nest_left'])
            ->orderBy('nest_left')
            ->get();

        $arByParent = [];
        foreach ($arAll as $obCategory) {
            $arByParent[$obCategory->parent_id ?? 0][] = $obCategory;
        }

        $iMoves = 0;
        $arTouchedParents = [];

        foreach ($arByParent as $iParentID => $arSiblings) {
            if (count($arSiblings) < 2) {
                continue;
            }

            $arCurrentIDs = array_map(fn($ob) => $ob->id, $arSiblings);

            // Desired order: coded first (natural sort by code), uncoded keep
            // their current relative order at the end.
            $arSorted = $arSiblings;
            usort($arSorted, function ($a, $b) {
                $bAHasCode = trim((string) $a->code) !== '';
                $bBHasCode = trim((string) $b->code) !== '';
                if ($bAHasCode !== $bBHasCode) {
                    return $bAHasCode ? -1 : 1;
                }
                if (!$bAHasCode) {
                    return $a->nest_left <=> $b->nest_left;
                }
                $iResult = strnatcasecmp(trim($a->code), trim($b->code));

                return $iResult !== 0 ? $iResult : ($a->nest_left <=> $b->nest_left);
            });

            $arSortedIDs = array_map(fn($ob) => $ob->id, $arSorted);
            if ($arSortedIDs === $arCurrentIDs) {
                continue;
            }

            $obPrevious = null;
            foreach ($arSorted as $obSibling) {
                // Fresh model each move - nested-set values shift after every move
                $obModel = Category::find($obSibling->id);
                if (empty($obModel)) {
                    continue;
                }

                try {
                    if ($obPrevious === null) {
                        $obLeftmost = Category::where('parent_id', $iParentID === 0 ? null : $iParentID)
                            ->when($iParentID === 0, fn($q) => $q->whereNull('parent_id'))
                            ->orderBy('nest_left')
                            ->first();
                        if (!empty($obLeftmost) && $obLeftmost->id !== $obModel->id) {
                            $obModel->moveBefore($obLeftmost);
                            $iMoves++;
                        }
                    } else {
                        $obTarget = Category::find($obPrevious->id);
                        if (!empty($obTarget)) {
                            $obModel->moveAfter($obTarget);
                            $iMoves++;
                        }
                    }
                } catch (\Exception $obException) {
                    Log::warning('CategorySorter: move failed for category #'
                        .$obModel->id.': '.$obException->getMessage());
                }

                $obPrevious = $obModel;
            }

            $arTouchedParents[] = $iParentID;
        }

        if (!empty($arTouchedParents)) {
            self::clearCategoryCache($arTouchedParents);
        }

        return $iMoves;
    }

    /**
     * Clear Lovata item/list caches so the frontend picks up the new order.
     * @param array $arParentIDList
     */
    protected static function clearCategoryCache(array $arParentIDList): void
    {
        try {
            foreach (array_unique($arParentIDList) as $iParentID) {
                if ($iParentID !== 0) {
                    CategoryItem::clearCache($iParentID);
                }
            }

            // Children id-lists live inside each item's cache; clear every
            // category item to be safe (cheap - list is small) + top level list
            foreach (Category::pluck('id') as $iCategoryID) {
                CategoryItem::clearCache($iCategoryID);
            }

            CategoryListStore::instance()->top_level->clear();
        } catch (\Exception $obException) {
            Log::warning('CategorySorter: cache clear failed: '.$obException->getMessage());
        }
    }
}
