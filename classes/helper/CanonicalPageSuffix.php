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
 * Not for product pages. 'page' means nothing on /p/slug, so those keep
 * naming the clean product URL and never call this.
 */
class CanonicalPageSuffix
{
    const QUERY_FIELD = 'page';

    /** First page that may name itself; page 1 is the bare URL */
    const FIRST_SELF_CANONICAL_PAGE = 2;

    /**
     * Twig: canonical_page_suffix() or canonical_page_suffix(bHasRows)
     *
     * @param bool $bHasRows false when the listing rendered no row, which
     *                       means this page is past the last real one
     */
    public static function get(bool $bHasRows = true): string
    {
        if (!$bHasRows) {
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
