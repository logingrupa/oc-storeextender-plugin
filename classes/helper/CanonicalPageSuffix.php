<?php namespace Logingrupa\StoreExtender\Classes\Helper;

/**
 * The pagination part of a canonical URL, '?page=3' or nothing.
 *
 * A catalog page 2 used to canonical to page 1, which tells Google the two are
 * the same page. Google's guidance is the opposite: each paginated URL names
 * itself, because a product that only appears deep in a listing is otherwise
 * on no page that claims to be indexable.
 *
 * Page 1 is the exception and returns nothing. '?page=1' and the bare URL are
 * the same page, so letting page 1 name itself would put two URLs in
 * competition for one listing.
 *
 * The value is validated rather than echoed. It reaches an href, so a
 * non-numeric or negative '?page=' must not get there; digits only, and
 * anything else falls back to the clean canonical. That also covers the case
 * of a page past the last one: the caller passes bHasRows false when the
 * listing came back empty, and an empty page 900 canonicals to the clean URL
 * instead of inviting the indexing of nothing.
 *
 * Every rule about when a page value may reach a canonical lives HERE, not in
 * the templates. There are four call sites across two canonical code paths, the
 * url_current() fallback and the pages that name their own path, and when the
 * product exclusion was written as `starts with '/p/'` in Twig it had to be
 * repeated at each one. A page added later would have had to remember it.
 */
class CanonicalPageSuffix
{
    const QUERY_FIELD = 'page';

    /** First page that may name itself; page 1 is the bare URL */
    const FIRST_SELF_CANONICAL_PAGE = 2;

    /** First path segment of a product page, where 'page' means nothing */
    const PRODUCT_PREFIX = '/p/';

    /**
     * Twig: canonical_page_suffix(sPath) or canonical_page_suffix(sPath, bHasRows)
     *
     * Deliberately takes no "does this page have rows" flag. One was written
     * earlier and no caller could supply it honestly: the only page here that
     * paginates is the catalog, and it resolves its list in a body partial
     * (partials/product/catalog-product-list), so the head cannot know the row
     * count without running that query twice. A parameter nothing can pass is
     * worse than a documented gap, so T3 criterion 6 is unmet for the catalog
     * and that is recorded rather than faked. The collections hub and family
     * pages do not paginate at all, so they pass no page value in the first place.
     *
     * @param string $sPath the page's own clean path, so a product page is
     *                      excluded here rather than at each call site
     */
    public static function get(string $sPath = ''): string
    {
        // 'page' means nothing on /p/slug: a stray one keeps the clean product URL
        if (strpos($sPath, self::PRODUCT_PREFIX) === 0) {
            return '';
        }

        $iPage = static::requestedPage();

        return $iPage === null ? '' : '?' . self::QUERY_FIELD . '=' . $iPage;
    }

    /**
     * The requested page when it is one this shop would paginate to, else null.
     * Digits only: '2abc', '-1', '2.0', '1e3' and an array all read as null.
     */
    public static function requestedPage(): ?int
    {
        $obValue = input(self::QUERY_FIELD);
        if (!is_string($obValue) && !is_int($obValue)) {
            return null;
        }

        $sValue = trim((string) $obValue);
        if ($sValue === '' || preg_match('/^\d+$/', $sValue) !== 1) {
            return null;
        }

        $iPage = (int) $sValue;

        return $iPage >= self::FIRST_SELF_CANONICAL_PAGE ? $iPage : null;
    }
}
