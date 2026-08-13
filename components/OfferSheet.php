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
use Logingrupa\StoreExtender\Classes\Helper\OfferImageHelper;

/**
 * Class OfferSheet
 * @package Logingrupa\Storeextender\Components
 *
 * AJAX handler surface for the slide-over offer/shade sheet (home redesign
 * pages + product page). Attach to a page, render the
 * 'home-redesign/shared/sheet' partial, and put [data-hr-sheet="onShowOffers"]
 * triggers in the markup - themes/logingrupa-naisstore/src/modules/offer-sheet
 * does the rest. Handlers resolve unprefixed through the component, so pages
 * need no PHP of their own.
 *
 * Row HTML is cart-agnostic and cached per product+site+locale+currency+price
 * type (see getRowListHtml); cart marks ride along as arCartOfferIdList and
 * are applied client-side.
 */
class OfferSheet extends ComponentBase
{
    const CACHE_TAG_SHEET = 'hr-sheet';
    const CACHE_KEY_EPOCH = 'hr.sheet.epoch';
    const CACHE_TTL_MINUTES = 10;

    /** Shades the product page shows inline before the sheet takes over */
    const INLINE_LIMIT = 12;

    /** Shades kept before the selected one when the strip is windowed */
    const WINDOW_BEFORE = 5;

    /** Folder a batched fragment partial has to live in */
    const FRAGMENT_PARTIAL_PREFIX = 'product/product-card-detailed/';

    /** Fragments one batched offer may ask for */
    const FRAGMENT_PARTIAL_CEILING = 8;

    /**
     * Ordered row lists already read this request, keyed by cache key.
     *
     * Three call sites ask for the same list on one request and reading it
     * back out of the file cache is not free - a 230-shade list measured
     * 9.5ms per read. The component instance lives exactly one request, which
     * is exactly how long this may be trusted.
     *
     * @var array<string, array>
     */
    protected array $arOrderedRowListMemo = [];

    /**
     * Request-scoped memo of the epoch value: getRenderContextKeyParts() runs
     * on every cache read and the epoch behind it is itself a cache read.
     *
     * @var string|null
     */
    protected ?string $sSheetCacheEpochMemo = null;

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
     * Expose the client cache epoch token to the page.
     *
     * The token is the render context key parts whole - epoch value included,
     * see getRenderContextKeyParts() - so the client store and the server
     * cache can never disagree about what is reusable. The catalog import
     * commands (shopaholic:import_from_xml_s3 / _serverless), the
     * goods-received stock apply and storeextender:import-theme-messages
     * rotate the epoch via Cache::forget(CACHE_KEY_EPOCH) when their data
     * changes; php artisan cache:clear wipes the key as well.
     */
    public function onRun()
    {
        // sheet-cache.js keys its localStorage store by this token and purges
        // on mismatch. EVERY dimension of the server-side cache key rides
        // along (site, locale, currency, price type, color version) - the
        // epoch once carried only locale + currency, so a guest's retail rows
        // survived a wholesale login (same locale, same currency, different
        // price type) and the sheet showed the wrong tier's prices for up to
        // an hour; the reverse leaked wholesale prices to the next guest on a
        // shared machine.
        $this->page['sHrCacheEpoch'] = $this->getClientCacheEpochToken();
        $this->page['sHrSheetMode'] = $this->getMode();
    }

    protected function getPageSize(): int
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
            'sHrCacheEpoch' => $this->getClientCacheEpochToken(),
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
            return [
                'sRowListHtml' => '',
                'iNextOffset' => $iTotalCount,
                'bHasMore' => false,
                'sHrCacheEpoch' => $this->getClientCacheEpochToken(),
            ];
        }
        $iNextOffset = min($iOffset + $iPageSize, $iTotalCount);

        return [
            'sRowListHtml' => $this->getRowListHtml($obProductItem, $iOffset),
            'iNextOffset' => $iNextOffset,
            'bHasMore' => $iNextOffset < $iTotalCount,
            'arCartOfferIdList' => $this->getCartOfferIdList(),
            'sHrCacheEpoch' => $this->getClientCacheEpochToken(),
        ];
    }

    /**
     * Everything the page needs to show any of N shades, in ONE response.
     *
     * The product page used to fetch a shade at a time: twelve visible
     * swatches meant twelve round trips of about 240ms each, and picking a
     * shade in the sheet cost two more (fragments, then pictures). The twelve
     * responses also repeat each other heavily, and gzip finds that
     * redundancy by itself: twelve separate responses measured 17,581 bytes
     * gzipped against 2,185 for one batched response carrying the same
     * markup.
     *
     * Bounded on purpose. The visible strip window is twelve shades and this
     * accepts no more, so a 230-shade product can never turn one idle
     * prefetch into a catalogue dump. A shade the sheet picks from outside
     * that window comes back through here as a batch of one.
     *
     * The inputs are deliberately NOT called product_id and offer_id. A
     * fragment partial falls back to input('offer_id') when no offer was handed
     * to it (that is how the same template serves a page render, a legacy
     * per-shade AJAX render and this one), so a request carrying those names
     * would put a single offer id in front of all twelve renders.
     *
     * @return array|null
     */
    public function onGetOfferBatch()
    {
        $iProductId = (int) input('batch_product_id');
        if ($iProductId < 1) {
            return null;
        }
        $obProductItem = ProductItem::make($iProductId);
        if ($obProductItem->isEmpty()) {
            return null;
        }

        $arOfferIdList = $this->readBatchOfferIdList($obProductItem);
        if (empty($arOfferIdList)) {
            return null;
        }

        return [
            'arOfferList' => $this->renderOfferBatchList(
                $obProductItem,
                array_map(fn ($iOfferId) => OfferItem::make($iOfferId), $arOfferIdList),
                $this->readBatchPartialList()
            ),
            'sHrCacheEpoch' => $this->getClientCacheEpochToken(),
        ];
    }

    /**
     * Everything the page needs to show N shades, rendered.
     *
     * Shared by the two handlers that can produce it: onGetOfferBatch, where the
     * client names the shades, and onGetSwatchStrip, where the shades are the
     * ones the strip it just rendered is showing. One renderer, so a shade
     * cannot arrive describing itself differently depending on which request
     * carried it.
     *
     * NOT cached, and it must not be. Two of these partials carry per-visitor
     * state: the gallery draws the wishlist heart from this visitor's list
     * (obOffer.inWishList()), and the add-to-cart button draws the login and
     * approval gate from this visitor's account. A shared entry would hand one
     * shopper another shopper's wishlist and buy rights.
     *
     * @param OfferItem[] $arOfferItemList
     * @param string[]    $arPartialPathList
     *
     * @return array
     */
    protected function renderOfferBatchList(
        ProductItem $obProductItem,
        array $arOfferItemList,
        array $arPartialPathList
    ): array {
        if (empty($arOfferItemList) || empty($arPartialPathList)) {
            return [];
        }

        // hoisted: the product's offer count is the same for all twelve shades,
        // and a value that cannot vary across a loop has no business inside it
        $iOfferTotalCount = $obProductItem->offer->count();

        $arOfferList = [];
        foreach ($arOfferItemList as $obOfferItem) {
            if ($obOfferItem->isEmpty()) {
                continue;
            }
            $arOfferList[] = [
                'iOfferId' => $obOfferItem->id,
                'arPartialList' => $this->renderOfferPartialList($obProductItem, $obOfferItem, $arPartialPathList),
                'arImageList' => $this->getOfferImageList($obOfferItem),
                'sSwatchHtml' => $this->renderSwatchHtml($obProductItem, $obOfferItem, $iOfferTotalCount),
            ];
        }

        return $arOfferList;
    }

    /**
     * Offer ids of one batch: numeric, unique, bounded, and belonging to the
     * requested product.
     *
     * The product check is not decoration. The ids come from the client, and
     * an id from another product would render that product's price and
     * gallery into this page.
     *
     * @return array
     */
    protected function readBatchOfferIdList(ProductItem $obProductItem): array
    {
        $arRequestedIdList = array_slice(
            array_unique(array_filter(array_map('intval', explode(',', (string) input('batch_offer_ids'))))),
            0,
            self::INLINE_LIMIT
        );

        $arProductOfferIdList = array_map('intval', (array) $obProductItem->offer->getIDList());

        return array_values(array_intersect($arRequestedIdList, $arProductOfferIdList));
    }

    /**
     * Partial paths of one batch.
     *
     * The theme owns which fragments a shade change redraws - the map lives
     * in src/modules/offer-fragments.js and there is no second copy of it
     * here. What this owns is the bound: a path outside the product card
     * folder, or more paths than the card has fragments, is refused. October
     * already lets any page name any partial through X-AJAX-PARTIALS, so this
     * is strictly narrower than the request it replaces.
     *
     * @return array
     */
    protected function readBatchPartialList(): array
    {
        $arPathList = [];
        foreach (explode(',', (string) input('batch_partials')) as $sPath) {
            $sPath = trim($sPath);
            if ($sPath === '' || strpos($sPath, self::FRAGMENT_PARTIAL_PREFIX) !== 0) {
                continue;
            }
            if (preg_match('#^[a-z0-9/_-]+$#', $sPath) !== 1) {
                continue;
            }
            $arPathList[] = $sPath;
        }

        return array_slice(array_unique($arPathList), 0, self::FRAGMENT_PARTIAL_CEILING);
    }

    /**
     * One offer's fragments, in the shape Larajax delivers its own partials,
     * so the client applies both through the same code path.
     *
     * @return array [['sName' => string, 'sHtml' => string]]
     */
    protected function renderOfferPartialList(
        ProductItem $obProductItem,
        OfferItem $obOfferItem,
        array $arPartialPathList
    ): array {
        $arPartialList = [];
        foreach ($arPartialPathList as $sPartialPath) {
            $arPartialList[] = [
                'sName' => $sPartialPath,
                'sHtml' => (string) $this->controller->renderPartial($sPartialPath, [
                    'obProduct' => $obProductItem,
                    // obRenderOffer, not obOffer: this render is PINNED to one
                    // shade and nothing in the request may talk it out of that.
                    // See Classes\Helper\OfferRenderContext - the page passes
                    // obOffer on a normal render too, so only a name nothing
                    // else uses can carry "the caller chose this shade".
                    'obRenderOffer' => $obOfferItem,
                    'obOffer' => $obOfferItem,
                ]),
            ];
        }

        return $arPartialList;
    }

    /**
     * One shade's swatch circle, from the strip's own partial.
     *
     * The page strip shows twelve of up to 230 shades, so a shade picked inside
     * the sheet is usually not on it. It used to be put there by re-rendering
     * the whole strip windowed around the pick - one more round trip, and then
     * twelve fresh shades whose fragments all had to be fetched again, for a
     * strip the visitor had not asked to move.
     *
     * Instead the pick's own swatch rides along with the fragments it already
     * needs, and the client drops it into the first slot. Same partial the strip
     * is built from, so the two cannot drift; the batch is already in the cache
     * by the time a row is tapped, so this costs no request at all.
     *
     * Rendered with no selection, like every other strip render: the ring is
     * applied client-side, which is what keeps the markup free of per-visitor
     * state.
     *
     * The total count is passed in rather than read here, because it is the same
     * for every shade in the batch. The partial only uses it to decide whether
     * SKU-<product> carries an offer suffix.
     */
    protected function renderSwatchHtml(
        ProductItem $obProductItem,
        OfferItem $obOfferItem,
        int $iOfferTotalCount
    ): string {
        return trim((string) $this->controller->renderPartial('product/offer-swatches/offer-swatches-strip', [
            'obProduct' => $obProductItem,
            'arInlineOfferList' => [$obOfferItem],
            'iTotalCount' => $iOfferTotalCount,
            'bUseSheet' => false,
            'iSelectedOfferId' => 0,
        ]));
    }

    /**
     * Pictures of one offer for the sheet's sticky preview slider: preview
     * image first, then the attached gallery.
     *
     * Sized derivatives, not originals - the slot is 240px tall and the
     * originals behind it run to a megabyte each. OfferImageHelper owns the
     * size; storeextender:warm-offer-thumbs generates them ahead of traffic.
     *
     * Deduped by original file name and byte size: the import routinely
     * attaches the same photograph as preview_image AND as the only gallery
     * entry, under two File records with two URLs, so without this the
     * slider showed the picture twice and the client downloaded both.
     *
     * @return array [['sSrc' => string]]
     */
    protected function getOfferImageList(OfferItem $obOfferItem): array
    {
        $arImageList = [];
        $arSeenFileList = [];

        $obPreviewImage = $obOfferItem->preview_image;
        $arCandidateList = !empty($obPreviewImage) ? [$obPreviewImage] : [];
        $obGalleryList = $obOfferItem->images;
        if (!empty($obGalleryList)) {
            foreach ($obGalleryList as $obImage) {
                $arCandidateList[] = $obImage;
            }
        }

        foreach ($arCandidateList as $obImage) {
            $sFileKey = $obImage->file_name.'|'.$obImage->file_size;
            if (isset($arSeenFileList[$sFileKey])) {
                continue;
            }
            $arSeenFileList[$sFileKey] = true;
            $arImageList[] = ['sSrc' => OfferImageHelper::preview($obImage)];
        }

        return $arImageList;
    }

    /**
     * The product page's swatch strip, re-rendered.
     *
     * The page filters its own shade circles instead of opening the sheet, so a
     * chip click rebuilds the strip: every shade of one family, or the head of
     * the list when the filter is cleared. This is the ONLY renderer of that
     * markup - it hands back the same partial the page renders on load, so no
     * client-side copy of it can drift.
     *
     * A shade picked inside the sheet does NOT come through here. It used to:
     * the strip was re-rendered windowed around the pick, which cost a round
     * trip, then twelve fresh shades whose fragments all had to be fetched
     * again, and one cache entry per shade a visitor happened to pick. The pick
     * now brings its own swatch back with its fragments (renderSwatchHtml) and
     * the client puts it in the first slot, so this handler answers per FAMILY
     * only and every visitor shares the same handful of entries.
     *
     * Selection is applied client-side, which keeps the HTML free of
     * per-visitor state and therefore cacheable.
     *
     * with_offers asks for the twelve shades' fragments in the SAME response.
     * Without it a family switch is two round trips that cannot overlap: the
     * strip has to land before anything knows which twelve shades it is showing,
     * and only then can they be fetched. On a phone, where there is no hover to
     * do the first one early, that chain is the whole cost of switching colour.
     * The flag exists because the two callers want different things - a pointer
     * merely resting on a chip wants the cheap cached strip and nothing more,
     * while a click or a tap is a commitment and wants everything at once.
     *
     * @return array|null
     */
    public function onGetSwatchStrip()
    {
        $obProductItem = $this->getProductItemFromRequest();
        if ($obProductItem === null) {
            return null;
        }
        $sFamily = trim((string) input('family'));
        // Display preference only (sold-out shades), mirrored from the
        // rendered strip: the theme owns the setting, the plugin must not
        // reach into theme config to re-derive it
        $bHideSoldOut = (bool) input('hide_oos');

        $arSwatchData = $this->getInlineSwatchData($obProductItem, $bHideSoldOut, $sFamily);
        if ($arSwatchData['sActiveFamily'] !== $sFamily) {
            return null; // fail fast: the product has no such family
        }

        return [
            'sFamily' => $sFamily,
            'sStripHtml' => $this->getSwatchStripHtml($obProductItem, $sFamily, $bHideSoldOut, $arSwatchData),
            // Bounded to the same window the client primes and can HOLD: its
            // offer cache caps at 24 entries, so shipping a 44-shade family
            // whole evicted the first-seeded shades - including the very one
            // the family switch auto-picks - and the "merged" response ended
            // up buying nothing on any family larger than the cache.
            'arOfferList' => (bool) input('with_offers')
                ? $this->renderOfferBatchList(
                    $obProductItem,
                    array_slice($arSwatchData['arOfferItemList'], 0, self::INLINE_LIMIT),
                    $this->readBatchPartialList()
                )
                : [],
            'sHrCacheEpoch' => $this->getClientCacheEpochToken(),
        ];
    }

    /**
     * Rendered swatch strip, cached like the sheet rows. Two shapes, and neither
     * depends on which shade is selected: every shade of one family, or the head
     * of the list. So the key carries no offer id and one entry per family
     * serves every visitor.
     *
     * The swatch data is passed in rather than resolved here. The caller needs
     * it anyway - it is what names the shades whose fragments ride along - and
     * resolving the same thing twice per request to save nothing is exactly the
     * kind of hidden work this component has already been trimmed of once.
     *
     * @param array $arSwatchData as getInlineSwatchData returns it
     */
    protected function getSwatchStripHtml(
        ProductItem $obProductItem,
        string $sFamily,
        bool $bHideSoldOut,
        array $arSwatchData
    ): string {
        $sCacheKey = $this->buildCacheKey([
            'hr.strip',
            $obProductItem->id,
            $sFamily !== '' ? $sFamily : 'head',
            (int) $bHideSoldOut,
        ]);
        $sStripHtml = CCache::get([self::CACHE_TAG_SHEET], $sCacheKey);
        if (!empty($sStripHtml)) {
            return $sStripHtml;
        }

        $sStripHtml = (string) $this->controller->renderPartial('product/offer-swatches/offer-swatches-strip', [
            'obProduct' => $obProductItem,
            'arInlineOfferList' => $arSwatchData['arOfferItemList'],
            'iTotalCount' => $arSwatchData['iTotalCount'],
            'bUseSheet' => $arSwatchData['bUseSheet'],
            'iSelectedOfferId' => 0,
        ]);
        CCache::put([self::CACHE_TAG_SHEET], $sCacheKey, $sStripHtml, self::CACHE_TTL_MINUTES);

        return $sStripHtml;
    }

    /**
     * Offer ids currently in the cart - applied client-side so cached row
     * HTML stays shared between visitors
     * @return array
     */
    protected function getCartOfferIdList(): array
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
     * Cached, because it is the expensive part of every sheet and strip
     * request and it holds nothing per-visitor: grouping a 230-shade product
     * materializes 230 OfferItems at roughly a millisecond each, and three
     * call sites ask for it. The rendered markup beside it already caches
     * under the same tag and the same key builder, so one cache:clear or one
     * colour sync drops both together.
     *
     * @return array [['iOfferId' => int, 'sFamily' => string|null, 'sHex' => string|null, 'bGroupStart' => bool]]
     */
    protected function getOrderedRowList(ProductItem $obProductItem): array
    {
        // The list itself does not vary by locale, currency or price type,
        // but it keys through the same builder as everything else under this
        // tag: one key scheme is worth more than the handful of duplicate
        // entries it mints.
        $sCacheKey = $this->buildCacheKey(['hr.rows', $obProductItem->id]);
        if (isset($this->arOrderedRowListMemo[$sCacheKey])) {
            return $this->arOrderedRowListMemo[$sCacheKey];
        }

        $arRowList = CCache::get([self::CACHE_TAG_SHEET], $sCacheKey);
        if (empty($arRowList)) {
            $arRowList = $this->buildOrderedRowList($obProductItem);
            CCache::put([self::CACHE_TAG_SHEET], $sCacheKey, $arRowList, self::CACHE_TTL_MINUTES);
        }
        $this->arOrderedRowListMemo[$sCacheKey] = $arRowList;

        return $arRowList;
    }

    /**
     * @return array [['iOfferId' => int, 'sFamily' => string|null, 'sHex' => string|null, 'bGroupStart' => bool]]
     */
    protected function buildOrderedRowList(ProductItem $obProductItem): array
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
    protected function getFamilyChipList(ProductItem $obProductItem): array
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
    protected function getRowListHtml(ProductItem $obProductItem, int $iOffset): string
    {
        if ($iOffset >= $obProductItem->offer->count()) {
            return '';
        }

        $sCacheKey = $this->buildCacheKey(['hr.sheet', $obProductItem->id, $iOffset]);
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
     * Only the PAGE render passes a selected offer, and only because a shared
     * /p/<slug>/<offer> link has to open with its shade on the strip. Every
     * later strip render asks for the head (see onGetSwatchStrip): a shade
     * picked in the sheet brings its own swatch back with its fragments, so the
     * strip no longer re-windows itself once per pick.
     *
     * Conflict rule for shared URLs: an EXPLICIT offer segment wins over the
     * family query param - the filter snaps to the offer's family.
     *
     * @return array {arOfferItemList: OfferItem[], iTotalCount: int, bUseSheet: bool, arFamilyChipList: array, sActiveFamily: string}
     */
    public function getInlineSwatchData(
        ProductItem $obProductItem,
        bool $bHideOutOfStock = false,
        string $sFamilyFilter = '',
        int $iSelectedOfferId = 0,
        bool $bOfferIsExplicit = false
    ): array {
        $arVisibleList = $this->getVisibleRowList($obProductItem, $bHideOutOfStock);
        $iTotalCount = count($arVisibleList);
        $bUseSheet = $iTotalCount > self::INLINE_LIMIT;

        $sActiveFamily = $bUseSheet
            ? $this->resolveActiveFamily($arVisibleList, $sFamilyFilter, $bOfferIsExplicit ? $iSelectedOfferId : 0)
            : '';
        $arShownRowList = $sActiveFamily !== ''
            ? $this->getFamilyRowList($arVisibleList, $sActiveFamily)
            : $this->getWindowedRowList($arVisibleList, $iSelectedOfferId);

        return [
            'arOfferItemList' => $this->makeOfferItemList($arShownRowList),
            'iTotalCount' => $iTotalCount,
            'bUseSheet' => $bUseSheet,
            'arFamilyChipList' => $bUseSheet ? $this->getFamilyChipList($obProductItem) : [],
            'sActiveFamily' => $sActiveFamily,
        ];
    }

    /**
     * Sellable swatch rows in color-family order.
     *
     * Rows, not items. The strip shows twelve shades out of up to 230, and
     * windowing needs only ids and families, so the OfferItems are made at
     * the end for the twelve that are actually rendered.
     *
     * The sold-out preference is the exception and stays on the slow path:
     * hiding a shade requires its quantity, quantity lives on the OfferItem,
     * and it moves with every order - caching it under a ten minute TTL beside
     * markup that does not change would sell stock the shop no longer has.
     *
     * @return array [['iOfferId' => int, 'sFamily' => string|null]]
     */
    protected function getVisibleRowList(ProductItem $obProductItem, bool $bHideOutOfStock): array
    {
        $arVisibleList = [];
        foreach ($this->getOrderedRowList($obProductItem) as $arRow) {
            if ($bHideOutOfStock && (int) OfferItem::make($arRow['iOfferId'])->quantity === 0) {
                continue;
            }
            $arVisibleList[] = ['iOfferId' => (int) $arRow['iOfferId'], 'sFamily' => $arRow['sFamily']];
        }

        return $arVisibleList;
    }

    /**
     * Materialize the rows that are actually rendered.
     *
     * An id with no item is dropped rather than rendered blank. It cannot
     * normally happen - the ids come from the product's own active offer list
     * - so a window that comes back one short is a signal, not a state to
     * design around.
     *
     * @return \Lovata\Shopaholic\Classes\Item\OfferItem[]
     */
    protected function makeOfferItemList(array $arRowList): array
    {
        $arOfferItemList = [];
        foreach ($arRowList as $arRow) {
            $obOfferItem = OfferItem::make($arRow['iOfferId']);
            if ($obOfferItem->isEmpty()) {
                continue;
            }
            $arOfferItemList[] = $obOfferItem;
        }

        return $arOfferItemList;
    }

    /**
     * Family filter for the inline strip. Explicit offer id wins over the
     * requested family; an unknown family falls back to no filter.
     */
    protected function resolveActiveFamily(array $arVisibleList, string $sFamilyFilter, int $iExplicitOfferId): string
    {
        if ($iExplicitOfferId > 0 && $sFamilyFilter !== '') {
            foreach ($arVisibleList as $arEntry) {
                if ($arEntry['iOfferId'] === $iExplicitOfferId) {
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
     * @return array [['iOfferId' => int, 'sFamily' => string|null]]
     */
    protected function getFamilyRowList(array $arVisibleList, string $sFamily): array
    {
        $arRowList = [];
        foreach ($arVisibleList as $arEntry) {
            if ($arEntry['sFamily'] === $sFamily) {
                $arRowList[] = $arEntry;
            }
        }

        return $arRowList;
    }

    /**
     * Window of INLINE_LIMIT shades around the selected offer (5 before, the
     * rest after), clamped to the list bounds; the head when nothing is
     * selected
     * @return array [['iOfferId' => int, 'sFamily' => string|null]]
     */
    protected function getWindowedRowList(array $arVisibleList, int $iSelectedOfferId): array
    {
        $iSelectedIndex = 0;
        foreach ($arVisibleList as $iIndex => $arEntry) {
            if ($arEntry['iOfferId'] === $iSelectedOfferId) {
                $iSelectedIndex = $iIndex;
                break;
            }
        }

        $iStart = max(0, min($iSelectedIndex - self::WINDOW_BEFORE, count($arVisibleList) - self::INLINE_LIMIT));

        return array_slice($arVisibleList, $iStart, self::INLINE_LIMIT);
    }

    /**
     * The epoch value, minted once and held until something rotates it.
     *
     * Folded into every render context key part list below, which is what
     * makes Cache::forget(CACHE_KEY_EPOCH) the ONE invalidation for both
     * stores: the next request mints a new value, every server row re-keys
     * under it, and every client drops its stores on token mismatch. Reads
     * the remembered value - it must never re-derive it from the context
     * parts, or minting would recurse.
     */
    protected function getSheetCacheEpoch(): string
    {
        if ($this->sSheetCacheEpochMemo === null) {
            $this->sSheetCacheEpochMemo = (string) Cache::rememberForever(self::CACHE_KEY_EPOCH, function () {
                return (string) microtime(true);
            });
        }

        return $this->sSheetCacheEpochMemo;
    }

    /**
     * Everything that makes the SAME markup render differently for another
     * visitor or another release: the cache epoch, site, locale, currency,
     * price type and the color data version behind the grouping. ONE list,
     * shared by the server cache key and the client cache epoch token, so the
     * two stores can never disagree about what is reusable. A new render
     * dimension goes in this list, nowhere else.
     *
     * @return string[]
     */
    protected function getRenderContextKeyParts(): array
    {
        $sColorVersion = (new ColorMapRepository())->getVersion();
        $obActiveSite = \Site::getActiveSite();

        return [
            $this->getSheetCacheEpoch(),
            $obActiveSite ? $obActiveSite->code : 'default',
            app()->getLocale(),
            (string) CurrencyHelper::instance()->getActiveCurrencyCode(),
            (string) (PriceTypeHelper::instance()->getActivePriceTypeCode() ?: 'base'),
            $sColorVersion !== '' ? $sColorVersion : 'plain',
        ];
    }

    /**
     * The token every client cache keys itself by: the render context parts,
     * whole. Echoed by every AJAX handler on top of the page render, so an
     * open tab learns about an auth, currency or import change from the FIRST
     * response it receives, not only at page load.
     */
    protected function getClientCacheEpochToken(): string
    {
        return implode('.', $this->getRenderContextKeyParts());
    }

    /**
     * Cache key for rendered markup: the caller's own parts, plus the shared
     * render context dimensions above.
     */
    protected function buildCacheKey(array $arPartList): string
    {
        return implode('.', array_merge($arPartList, $this->getRenderContextKeyParts()));
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
