/**
 * Lazy Tab Control — loads promo block product content via AJAX on tab switch.
 *
 * On connect, automatically loads the first (active) tab.
 * On subsequent tab clicks, loads content only once per tab.
 * Replaces skeleton placeholders with real product cards.
 *
 * Configured via data attributes on the tab-content container:
 *   data-control="lazy-tab"
 *   data-lazy-tab-handler="LazyPromoBlockLoader::onLoadPromoTab"
 *
 * Tab links must have:
 *   data-promo-block-id="{id}"
 *   data-promo-block-code="{code}"
 *
 * @example
 * <div class="tab-content"
 *      data-control="lazy-tab"
 *      data-lazy-tab-handler="LazyPromoBlockLoader::onLoadPromoTab">
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
    }

    /**
     * Bind tab click listener and auto-load the first active tab.
     */
    connect() {
        this.listen('click', '[data-toggle="tab"]', this.onTabClick);
        this.loadActiveTab();
    }

    /**
     * Load the currently active tab's content on initial page render.
     */
    loadActiveTab() {
        /** @type {HTMLAnchorElement|null} */
        const activeTabLink = document.querySelector('#nav-tab2 .nav-link.active');
        if (!activeTabLink) {
            return;
        }

        const iPromoBlockId = parseInt(activeTabLink.dataset.promoBlockId, 10);
        const sPromoBlockCode = activeTabLink.dataset.promoBlockCode || '';

        this.loadTabContent(iPromoBlockId, sPromoBlockCode);
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
