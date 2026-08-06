<?php namespace Logingrupa\Storeextender\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\Cache;
use Kharanenka\Helper\CCache;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\Shopaholic\Classes\Collection\OfferCollection;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Models\Offer;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Classes\Color\OfferColorGrouper;

/**
 * Class OfferSheet
 * @package Logingrupa\Storeextender\Components
 *
 * AJAX handler surface for the slide-over offer/shade sheet (home redesign
 * pages + product page). Attach to a page, render the
 * 'home-redesign/shared/sheet' partial, and put [data-hr-sheet="onShowOffers"]
 * triggers in the markup - hr-ui.js does the rest. Handlers resolve
 * unprefixed through the component, so pages need no PHP of their own.
 *
 * Row HTML is cart-agnostic and cached per product+site+locale+currency+price
 * type (see getRowListHtml); cart marks ride along as arCartOfferIdList and
 * are applied client-side.
 *
 * @method array|null onShowOffers()
 * @method array|null onShowOffersPage()
 * @method array|null onGetOfferImages()
 */
class OfferSheet extends ComponentBase
{
    const CACHE_TAG_SHEET = 'hr-sheet';
    const CACHE_KEY_EPOCH = 'hr.sheet.epoch';
    const CACHE_TTL_MINUTES = 10;

    public function componentDetails()
    {
        return [
            'name'        => 'Offer sheet',
            'description' => 'Slide-over shade picker: paginated cached rows, color family grouping, cart marks',
        ];
    }

    const MODE_NAVIGATE = 'navigate';
    const MODE_SELECT = 'select';

    public function defineProperties()
    {
        return [
            'page_size' => [
                'title'             => 'Rows per page',
                'default'           => 40,
                'validationPattern' => '^[0-9]+$',
            ],
            'mode' => [
                'title'       => 'Row tap behavior',
                'description' => 'navigate: CTA opens the product page (home pages). select: selection drives the current product page state.',
                'default'     => self::MODE_NAVIGATE,
            ],
        ];
    }

    public function getMode(): string
    {
        return $this->property('mode') === self::MODE_SELECT ? self::MODE_SELECT : self::MODE_NAVIGATE;
    }

    /**
     * Expose the localStorage epoch token to the page. cache:clear wipes the
     * key, the next request mints a new value, hr-ui.js drops its store on
     * mismatch (XML import workflow).
     */
    public function onRun()
    {
        $sEpoch = (string) Cache::rememberForever(self::CACHE_KEY_EPOCH, function () {
            return (string) microtime(true);
        });
        // hr-ui.js keys its localStorage store by this token and purges on
        // mismatch. Locale + currency ride along, otherwise a cached sheet
        // body from another locale/currency survives the switch.
        $this->page['sHrCacheEpoch'] = implode('.', [
            $sEpoch,
            app()->getLocale(),
            (string) CurrencyHelper::instance()->getActiveCurrencyCode(),
        ]);
        $this->page['sHrSheetMode'] = $this->getMode();
    }

    public function getPageSize(): int
    {
        $iPageSize = (int) $this->property('page_size');

        return $iPageSize > 0 ? $iPageSize : 40;
    }

    /**
     * Sheet page 1: title + body (chips, first rows, sentinel) + footer
     * @return array|null
     */
    public function onShowOffers()
    {
        $obProductItem = $this->getProductItemFromRequest();
        if ($obProductItem === null) {
            return null;
        }

        $iTotalCount = $obProductItem->offer->count();
        $iNextOffset = min($this->getPageSize(), $iTotalCount);

        return [
            '#hr-sheet-title' => e($obProductItem->name),
            '#hr-sheet-content' => $this->controller->renderPartial('home-redesign/shared/offers-sheet', [
                'obProduct' => $obProductItem,
                'sRowListHtml' => $this->getRowListHtml($obProductItem, 0),
                'iTotalCount' => $iTotalCount,
                'iNextOffset' => $iNextOffset,
                'bHasMore' => $iNextOffset < $iTotalCount,
                'arFamilyChipList' => $this->getFamilyChipList($obProductItem),
            ]),
            // trimmed: select mode renders no CTA and only a whitespace-free
            // footer matches the :empty rule that collapses it
            '#hr-sheet-footer' => trim((string) $this->controller->renderPartial('home-redesign/shared/offers-sheet-footer', [
                'obProduct' => $obProductItem,
                'bSelectMode' => $this->getMode() === self::MODE_SELECT,
            ])),
            'arCartOfferIdList' => $this->getCartOfferIdList(),
        ];
    }

    /**
     * Sheet pages 2..N appended below the sentinel
     * @return array|null
     */
    public function onShowOffersPage()
    {
        $iPageSize = $this->getPageSize();
        $iOffset = (int) input('offset');
        // page 1 is served by onShowOffers; offset must align to page bounds
        if ($iOffset < $iPageSize || $iOffset % $iPageSize !== 0) {
            return null;
        }
        $obProductItem = $this->getProductItemFromRequest();
        if ($obProductItem === null) {
            return null;
        }

        $iTotalCount = $obProductItem->offer->count();
        if ($iOffset >= $iTotalCount) {
            return ['sRowListHtml' => '', 'iNextOffset' => $iTotalCount, 'bHasMore' => false];
        }
        $iNextOffset = min($iOffset + $iPageSize, $iTotalCount);

        return [
            'sRowListHtml' => $this->getRowListHtml($obProductItem, $iOffset),
            'iNextOffset' => $iNextOffset,
            'bHasMore' => $iNextOffset < $iTotalCount,
            'arCartOfferIdList' => $this->getCartOfferIdList(),
        ];
    }

    /**
     * Image list of one offer for the sticky preview slider (select mode):
     * preview image first, then attached gallery images
     * @return array|null
     */
    public function onGetOfferImages()
    {
        $iOfferID = (int) input('offer_id');
        if ($iOfferID < 1) {
            return null;
        }
        $obOfferItem = OfferItem::make($iOfferID);
        if ($obOfferItem->isEmpty()) {
            return null;
        }

        $arImageList = [];
        $obPreviewImage = $obOfferItem->preview_image;
        if (!empty($obPreviewImage)) {
            $arImageList[] = ['sSrc' => (string) $obPreviewImage->path];
        }
        $obImageList = $obOfferItem->images;
        if (!empty($obImageList)) {
            foreach ($obImageList as $obImage) {
                $arImageList[] = ['sSrc' => (string) $obImage->path];
            }
        }

        return [
            'iOfferId' => $iOfferID,
            'sName' => (string) $obOfferItem->name,
            'arImageList' => $arImageList,
        ];
    }

    /**
     * Offer ids currently in the cart - applied client-side so cached row
     * HTML stays shared between visitors
     * @return array
     */
    public function getCartOfferIdList(): array
    {
        $arCartOfferIdList = [];
        foreach (CartProcessor::instance()->get() as $obCartPositionItem) {
            if ($obCartPositionItem->item_type != Offer::class) {
                continue;
            }
            $arCartOfferIdList[] = (int) $obCartPositionItem->item_id;
        }

        return $arCartOfferIdList;
    }

    /**
     * Full offer list of the product in color-family order (local color table
     * via ColorMapRepository - no remote API at request time)
     *
     * @return array [['iOfferId' => int, 'sFamily' => string|null, 'sHex' => string|null, 'bGroupStart' => bool]]
     */
    public function getOrderedRowList(ProductItem $obProductItem): array
    {
        $arIdList = $obProductItem->offer->getIDList();
        $arColorMap = (new ColorMapRepository())->getColorMap();
        if (empty($arColorMap)) {
            // fail-safe: no color data yet (sync never ran) -> ungrouped order
            return array_map(function ($iOfferId) {
                return ['iOfferId' => (int) $iOfferId, 'sFamily' => null, 'sHex' => null, 'bGroupStart' => false];
            }, $arIdList);
        }

        $arGroupList = (new OfferColorGrouper())->group(OfferCollection::make($arIdList), $arColorMap);
        $arRowList = [];
        foreach ($arGroupList as $arGroup) {
            $bGroupStart = $arGroup['sFamily'] !== null;
            foreach ($arGroup['arOfferList'] as $obOffer) {
                $arRowList[] = [
                    'iOfferId' => (int) $obOffer->id,
                    'sFamily' => $arGroup['sFamily'],
                    'sHex' => $arGroup['sHex'],
                    'bGroupStart' => $bGroupStart,
                ];
                $bGroupStart = false;
            }
        }

        return $arRowList;
    }

    /**
     * Chip per family in group order: label + representative swatch + count
     * @return array [['sFamily' => string, 'sHex' => string|null, 'iCount' => int]]
     */
    public function getFamilyChipList(ProductItem $obProductItem): array
    {
        $arChipList = [];
        foreach ($this->getOrderedRowList($obProductItem) as $arRow) {
            if ($arRow['sFamily'] === null) {
                continue;
            }
            if (!isset($arChipList[$arRow['sFamily']])) {
                $arChipList[$arRow['sFamily']] = ['sFamily' => $arRow['sFamily'], 'sHex' => $arRow['sHex'], 'iCount' => 0];
            }
            $arChipList[$arRow['sFamily']]['iCount'] += 1;
        }

        return array_values($arChipList);
    }

    /**
     * One cached page of rendered row HTML. Cart-agnostic on purpose - see
     * class docblock. Color data version keys the grouping.
     */
    public function getRowListHtml(ProductItem $obProductItem, int $iOffset): string
    {
        if ($iOffset >= $obProductItem->offer->count()) {
            return '';
        }

        $sColorVersion = (new ColorMapRepository())->getVersion();
        $obActiveSite = \Site::getActiveSite();
        $sCacheKey = implode('.', [
            'hr.sheet',
            $obProductItem->id,
            $iOffset,
            $obActiveSite ? $obActiveSite->code : 'default',
            app()->getLocale(),
            (string) CurrencyHelper::instance()->getActiveCurrencyCode(),
            (string) (PriceTypeHelper::instance()->getActivePriceTypeCode() ?: 'base'),
            $sColorVersion !== '' ? $sColorVersion : 'plain',
        ]);
        $arCacheTagList = [self::CACHE_TAG_SHEET];
        $sRowListHtml = CCache::get($arCacheTagList, $sCacheKey);
        if (!empty($sRowListHtml)) {
            return $sRowListHtml;
        }

        $arPageRowList = array_slice($this->getOrderedRowList($obProductItem), $iOffset, $this->getPageSize());
        if (empty($arPageRowList)) {
            return '';
        }
        foreach ($arPageRowList as $iIndex => $arRow) {
            $arPageRowList[$iIndex]['obOffer'] = OfferItem::make($arRow['iOfferId']);
        }

        $sRowListHtml = $this->controller->renderPartial('home-redesign/shared/offers-sheet-rows', [
            'obProduct' => $obProductItem,
            'arRowList' => $arPageRowList,
        ]);
        CCache::put($arCacheTagList, $sCacheKey, $sRowListHtml, self::CACHE_TTL_MINUTES);

        return $sRowListHtml;
    }

    /**
     * Data for the inline swatch row on the product page in color-family
     * order. Family filter active: ALL of that family's shades inline.
     * No filter: a window of N shades centered on the selected offer
     * (5 before, 6 after). Products with few shades show everything inline
     * and get no sheet trigger.
     *
     * Conflict rule for shared URLs: an EXPLICIT offer segment wins over the
     * family query param - the filter snaps to the offer's family.
     *
     * @return array {arOfferItemList: OfferItem[], iTotalCount: int, bUseSheet: bool, arFamilyChipList: array, sActiveFamily: string}
     */
    public function getInlineSwatchData(
        ProductItem $obProductItem,
        bool $bHideOutOfStock = false,
        int $iInlineLimit = 12,
        string $sFamilyFilter = '',
        int $iSelectedOfferId = 0,
        bool $bOfferIsExplicit = false
    ): array {
        $arVisibleList = $this->getVisibleSwatchList($obProductItem, $bHideOutOfStock);
        $iTotalCount = count($arVisibleList);
        $bUseSheet = $iTotalCount > $iInlineLimit;

        $sActiveFamily = $bUseSheet
            ? $this->resolveActiveFamily($arVisibleList, $sFamilyFilter, $bOfferIsExplicit ? $iSelectedOfferId : 0)
            : '';
        $arOfferItemList = $sActiveFamily !== ''
            ? $this->getFamilyOfferList($arVisibleList, $sActiveFamily)
            : $this->getWindowedOfferList($arVisibleList, $iSelectedOfferId, $iInlineLimit);

        return [
            'arOfferItemList' => $arOfferItemList,
            'iTotalCount' => $iTotalCount,
            'bUseSheet' => $bUseSheet,
            'arFamilyChipList' => $bUseSheet ? $this->getFamilyChipList($obProductItem) : [],
            'sActiveFamily' => $sActiveFamily,
        ];
    }

    /**
     * Sellable swatch list in color-family order
     * @return array [['obOffer' => OfferItem, 'sFamily' => string|null]]
     */
    protected function getVisibleSwatchList(ProductItem $obProductItem, bool $bHideOutOfStock): array
    {
        $arVisibleList = [];
        foreach ($this->getOrderedRowList($obProductItem) as $arRow) {
            $obOfferItem = OfferItem::make($arRow['iOfferId']);
            if ($obOfferItem->isEmpty()) {
                continue;
            }
            if ($bHideOutOfStock && (int) $obOfferItem->quantity === 0) {
                continue;
            }
            $arVisibleList[] = ['obOffer' => $obOfferItem, 'sFamily' => $arRow['sFamily']];
        }

        return $arVisibleList;
    }

    /**
     * Family filter for the inline strip. Explicit offer id wins over the
     * requested family; an unknown family falls back to no filter.
     */
    protected function resolveActiveFamily(array $arVisibleList, string $sFamilyFilter, int $iExplicitOfferId): string
    {
        if ($iExplicitOfferId > 0 && $sFamilyFilter !== '') {
            foreach ($arVisibleList as $arEntry) {
                if ((int) $arEntry['obOffer']->id === $iExplicitOfferId) {
                    return (string) $arEntry['sFamily'];
                }
            }
        }
        if ($sFamilyFilter === '') {
            return '';
        }
        foreach ($arVisibleList as $arEntry) {
            if ($arEntry['sFamily'] === $sFamilyFilter) {
                return $sFamilyFilter;
            }
        }

        return '';
    }

    /**
     * Every visible shade of one family
     * @return \Lovata\Shopaholic\Classes\Item\OfferItem[]
     */
    protected function getFamilyOfferList(array $arVisibleList, string $sFamily): array
    {
        $arOfferItemList = [];
        foreach ($arVisibleList as $arEntry) {
            if ($arEntry['sFamily'] === $sFamily) {
                $arOfferItemList[] = $arEntry['obOffer'];
            }
        }

        return $arOfferItemList;
    }

    /**
     * Window of N shades around the selected offer (5 before, 6 after),
     * clamped to the list bounds; first N when nothing is selected
     * @return \Lovata\Shopaholic\Classes\Item\OfferItem[]
     */
    protected function getWindowedOfferList(array $arVisibleList, int $iSelectedOfferId, int $iLimit): array
    {
        $iSelectedIndex = 0;
        foreach ($arVisibleList as $iIndex => $arEntry) {
            if ((int) $arEntry['obOffer']->id === $iSelectedOfferId) {
                $iSelectedIndex = $iIndex;
                break;
            }
        }

        $iStart = max(0, min($iSelectedIndex - 5, count($arVisibleList) - $iLimit));
        $arWindowList = array_slice($arVisibleList, $iStart, $iLimit);

        return array_map(function (array $arEntry) {
            return $arEntry['obOffer'];
        }, $arWindowList);
    }

    /**
     * Resolve the requested product item, null on bad input or unknown id
     */
    protected function getProductItemFromRequest(): ?ProductItem
    {
        $iProductID = (int) input('product_id');
        if ($iProductID < 1) {
            return null;
        }
        $obProductItem = ProductItem::make($iProductID);
        if ($obProductItem->isEmpty()) {
            return null;
        }

        return $obProductItem;
    }
}
