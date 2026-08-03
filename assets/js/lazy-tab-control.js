/**
 * Lazy Tab Control - loads promo block product content via AJAX.
 *
 * Every tab, including the first, ships as a skeleton and fetches its cards
 * over the component's handler. The active tab is requested as soon as the
 * control connects, so its content is on its way immediately rather than
 * waiting for a click or a scroll; the remaining tabs load when opened, once
 * each.
 *
 * Configured via data attributes on the tab container:
 *   data-control="lazy-tab"
 *   data-lazy-tab-handler="LazyPromoBlockLoader::onLoadPromoTab"
 *
 * Tab links must have:
 *   data-promo-block-id="{id}"
 *   data-promo-block-code="{code}"
 *
 * @example
 * <div data-control="lazy-tab"
 *      data-lazy-tab-handler="LazyPromoBlockLoader::onLoadPromoTab">
 */
jax.registerControl('lazy-tab', class extends jax.ControlBase {
    /**
     * Initialize control state.
     */
    init() {
        /** @type {Set<number>} Promo block IDs already requested */
        this.loadedTabIds = new Set();

        /** @type {string} AJAX handler name from component */
        this.handler = this.config.lazyTabHandler;
    }

    /**
     * Bind the tab click listener, then request the tab that is already open.
     */
    connect() {
        this.listen('click', '[data-toggle="tab"]', this.onTabClick);
        this.loadActiveTab();
    }

    /**
     * Request content for the tab rendered as active, without a user gesture.
     */
    loadActiveTab() {
        /** @type {HTMLAnchorElement|null} */
        const activeLink = this.element.querySelector('[data-toggle="tab"].active')
            || this.element.querySelector('[data-toggle="tab"]');
        if (!activeLink) {
            return;
        }

        this.loadTabFromLink(activeLink);
    }

    /**
     * Handle tab link click - reveal the pane, then load it if not already loaded.
     * @param {MouseEvent} event
     */
    onTabClick(event) {
        /** @type {HTMLAnchorElement|null} */
        const tabLink = event.target.closest('[data-toggle="tab"]');
        if (!tabLink) {
            return;
        }

        // href is an in-page anchor; without this the browser jumps to it
        event.preventDefault();

        this.activateTab(tabLink);
        this.loadTabFromLink(tabLink);
    }

    /**
     * Move the active state onto the clicked tab and its pane.
     *
     * data-toggle="tab" is Bootstrap 4 markup that used to be driven by
     * assets/js/bootstrap.min.js on layouts/main.htm. Migrated pages load no
     * Bootstrap JavaScript, so nothing switched the panes and every tab but the
     * first stayed display:none however many times it was clicked. The classes
     * are the ones bootstrap/scss/nav already styles.
     *
     * @param {HTMLAnchorElement} tabLink
     */
    activateTab(tabLink) {
        const sPaneId = (tabLink.getAttribute('href') || '').replace(/^#/, '');
        if (!sPaneId) {
            return;
        }

        this.element.querySelectorAll('[data-toggle="tab"]').forEach((link) => {
            const bIsTarget = link === tabLink;
            link.classList.toggle('active', bIsTarget);
            link.setAttribute('aria-selected', bIsTarget ? 'true' : 'false');
        });

        this.element.querySelectorAll('.tab-pane').forEach((pane) => {
            const bIsTarget = pane.id === sPaneId;
            pane.classList.toggle('active', bIsTarget);
            pane.classList.toggle('show', bIsTarget);
        });
    }

    /**
     * Read a tab link's promo block and fetch it.
     * @param {HTMLAnchorElement} tabLink
     */
    loadTabFromLink(tabLink) {
        const iPromoBlockId = parseInt(tabLink.dataset.promoBlockId, 10);
        if (Number.isNaN(iPromoBlockId)) {
            return;
        }

        this.loadTabContent(iPromoBlockId, tabLink.dataset.promoBlockCode || '');
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
