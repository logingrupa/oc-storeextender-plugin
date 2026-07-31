/**
 * Lazy Tab Control - loads promo block product content via AJAX on tab switch.
 *
 * The first (active) tab is server-rendered by the component; its promo block
 * ID arrives via data-lazy-tab-preloaded-id so it is never re-fetched.
 * On tab clicks, content is loaded via AJAX once per tab.
 * Replaces skeleton placeholders with real product cards.
 *
 * Configured via data attributes on the tab-content container:
 *   data-control="lazy-tab"
 *   data-lazy-tab-handler="LazyPromoBlockLoader::onLoadPromoTab"
 *   data-lazy-tab-preloaded-id="{id}"
 *
 * Tab links must have:
 *   data-promo-block-id="{id}"
 *   data-promo-block-code="{code}"
 *
 * @example
 * <div class="tab-content"
 *      data-control="lazy-tab"
 *      data-lazy-tab-handler="LazyPromoBlockLoader::onLoadPromoTab"
 *      data-lazy-tab-preloaded-id="15">
 */
jax.registerControl('lazy-tab', class extends jax.ControlBase {
    /**
     * Initialize control state.
     */
    init() {
        /** @type {Set<number>} Track which promo block IDs have been loaded */
        this.loadedTabIds = new Set();

        /** @type {string} AJAX handler name from component */
        this.handler = this.config.lazyTabHandler;

        const iPreloadedId = parseInt(this.config.lazyTabPreloadedId, 10);
        if (!Number.isNaN(iPreloadedId)) {
            this.loadedTabIds.add(iPreloadedId);
        }
    }

    /**
     * Bind tab click listener. First tab needs no fetch: it is server-rendered.
     */
    connect() {
        this.listen('click', '[data-toggle="tab"]', this.onTabClick);
    }

    /**
     * Handle tab link click — load content if not already loaded.
     * @param {MouseEvent} event
     */
    onTabClick(event) {
        /** @type {HTMLAnchorElement|null} */
        const tabLink = event.target.closest('[data-toggle="tab"]');
        if (!tabLink) {
            return;
        }

        const iPromoBlockId = parseInt(tabLink.dataset.promoBlockId, 10);
        const sPromoBlockCode = tabLink.dataset.promoBlockCode || '';

        if (this.loadedTabIds.has(iPromoBlockId)) {
            return;
        }

        this.loadTabContent(iPromoBlockId, sPromoBlockCode);
    }

    /**
     * Fetch product cards for a promo block tab via AJAX.
     * @param {number} iPromoBlockId
     * @param {string} sPromoBlockCode
     */
    loadTabContent(iPromoBlockId, sPromoBlockCode) {
        if (this.loadedTabIds.has(iPromoBlockId)) {
            return;
        }

        this.loadedTabIds.add(iPromoBlockId);

        jax.ajax(this.handler, {
            data: {
                promo_block_id: iPromoBlockId,
                promo_block_code: sPromoBlockCode,
            },
        }).catch(() => {
            this.loadedTabIds.delete(iPromoBlockId);
        });
    }
});
