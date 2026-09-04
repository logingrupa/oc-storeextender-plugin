<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Toolbox\Classes\Item\ElementItem;

/**
 * The schema.org Product block of a product page, built in PHP so that every
 * value is a real JSON value and every optional block is present only when the
 * shop has the data behind it.
 *
 * The Twig version of this block wrote "price": "" for a product with no
 * sellable offer and a one-review five-star aggregateRating for a product with
 * no reviews. Search Console reported the first as a critical Merchant listings
 * error on nailscosmetics.lv; the second is a review-snippet policy violation.
 *
 * Contract:
 *   - offers only when an OfferItem with a positive price was resolved, so a
 *     product nobody can buy carries no Offer instead of a broken one
 *   - aggregateRating and review only from reviews that carry a rating, and the
 *     aggregate is computed from that same list, so the two never disagree
 *   - a numeric offer code with a valid GS1 check digit is a gtin, anything
 *     else non-empty is an mpn
 *   - name follows the same rule as the visible h3: the shade name only when
 *     the visitor chose a shade (OfferRenderContext::bOfferSelected)
 *
 * Twig, registered in Plugin::registerMarkupTags() as product_json_ld.
 */
class ProductStructuredData
{
    const IMAGE_LIMIT = 8;
    const PRICE_VALID_INTERVAL = '+1 year';
    const RATING_BEST = 5;
    const RATING_WORST = 1;
    const GTIN_PROPERTY_BY_LENGTH = [8 => 'gtin8', 12 => 'gtin12', 13 => 'gtin13', 14 => 'gtin14'];
    // GS1 prefixes (first three digits of the 13-digit form) reserved for
    // restricted circulation: 020-029, 040-049, 200-299
    const RESTRICTED_GS1_PREFIX_PATTERNS = ['/^02\d$/', '/^04\d$/', '/^2\d\d$/'];
    const BLOCK_BOUNDARY_PATTERN = '#<br\s*/?>|</(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|script|style)\s*>#i';

    /**
     * @param ElementItem    $obProduct    product (or collection) the page describes
     * @param OfferItem|null $obOffer      offer the page resolved, null when none
     * @param bool           $bOfferSelected the visitor chose this shade (URL segment)
     * @param iterable       $obReviewList active reviews of the product
     * @param string         $sPageUrl     canonical URL of the render
     * @param string         $sSellerName  theme company name
     * @return string JSON, safe to inline inside a script element
     */
    public static function render(
        ElementItem $obProduct,
        $obOffer,
        bool $bOfferSelected,
        iterable $obReviewList,
        string $sPageUrl,
        string $sSellerName
    ): string {
        $arData = self::build($obProduct, $obOffer, $bOfferSelected, $obReviewList, $sPageUrl, $sSellerName);

        return json_encode($arData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array schema.org Product
     */
    public static function build(
        ElementItem $obProduct,
        $obOffer,
        bool $bOfferSelected,
        iterable $obReviewList,
        string $sPageUrl,
        string $sSellerName
    ): array {
        self::assertInput($obProduct, $obOffer, $sPageUrl, $sSellerName);

        $arData = self::productData($obProduct, $obOffer, $bOfferSelected, $sSellerName);

        $arRatedReviewList = self::ratedReviewList($obReviewList);
        if (!empty($arRatedReviewList)) {
            $arData['aggregateRating'] = self::aggregateRating($arRatedReviewList);
            $arData['review'] = array_map([self::class, 'review'], $arRatedReviewList);
        }

        $obSellableOffer = self::sellableOffer($obOffer);
        if ($obSellableOffer !== null) {
            $arData['offers'] = self::offer($obSellableOffer, $sPageUrl, $sSellerName);
        }

        return $arData;
    }

    /**
     * The product itself: what it is called, looks like, and is identified by.
     * @param ElementItem    $obProduct
     * @param OfferItem|null $obOffer
     * @param bool           $bOfferSelected
     * @param string         $sSellerName
     * @return array
     */
    protected static function productData(ElementItem $obProduct, $obOffer, bool $bOfferSelected, string $sSellerName): array
    {
        $arData = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            'name'     => self::displayName($obProduct, $obOffer, $bOfferSelected),
        ];

        $arImageList = self::imageList($obProduct, $obOffer);
        if (!empty($arImageList)) {
            $arData['image'] = $arImageList;
        }

        $sDescription = self::description($obProduct);
        if ($sDescription !== '') {
            $arData['description'] = $sDescription;
        }

        $arData['sku'] = self::sku($obProduct, $obOffer);
        $arData += self::identifier($obProduct, $obOffer);
        $arData['brand'] = ['@type' => 'Brand', 'name' => $sSellerName];

        return $arData;
    }

    /**
     * @param ElementItem $obProduct
     * @param mixed       $obOffer
     * @param string      $sPageUrl
     * @param string      $sSellerName
     * @return void
     */
    protected static function assertInput(ElementItem $obProduct, $obOffer, string $sPageUrl, string $sSellerName): void
    {
        if ($obProduct->isEmpty()) {
            throw new InvalidArgumentException('ProductStructuredData: product item is empty');
        }
        if ($obOffer !== null && !$obOffer instanceof OfferItem) {
            throw new InvalidArgumentException(sprintf(
                'ProductStructuredData: obOffer must be an OfferItem or null, %s given',
                is_object($obOffer) ? get_class($obOffer) : gettype($obOffer)
            ));
        }
        if (trim($sPageUrl) === '') {
            throw new InvalidArgumentException('ProductStructuredData: page URL is empty');
        }
        if (trim($sSellerName) === '') {
            throw new InvalidArgumentException('ProductStructuredData: seller name is empty (theme company_name)');
        }
    }

    /**
     * An offer the shop can actually sell: resolved, non-empty, priced above zero.
     * @param OfferItem|null $obOffer
     * @return OfferItem|null
     */
    protected static function sellableOffer($obOffer): ?OfferItem
    {
        if ($obOffer === null || $obOffer->isEmpty()) {
            return null;
        }
        if ((float) $obOffer->price_value <= 0) {
            return null;
        }

        return $obOffer;
    }

    /**
     * @param ElementItem    $obProduct
     * @param OfferItem|null $obOffer
     * @param bool           $bOfferSelected
     * @return string
     */
    protected static function displayName(ElementItem $obProduct, $obOffer, bool $bOfferSelected): string
    {
        $sOfferName = $bOfferSelected && $obOffer !== null ? trim((string) $obOffer->name) : '';
        if ($sOfferName !== '') {
            return $sOfferName;
        }

        $sProductName = trim((string) $obProduct->name);
        if ($sProductName === '') {
            throw new InvalidArgumentException(sprintf('ProductStructuredData: product %s has no name', $obProduct->id));
        }

        return $sProductName;
    }

    /**
     * Offer pictures first, then the product's, unique, capped.
     * @param ElementItem    $obProduct
     * @param OfferItem|null $obOffer
     * @return string[]
     */
    protected static function imageList(ElementItem $obProduct, $obOffer): array
    {
        $arFileList = [];
        if ($obOffer !== null && $obOffer->isNotEmpty()) {
            $arFileList[] = $obOffer->preview_image;
            $arFileList = array_merge($arFileList, self::fileCollectionToArray($obOffer->images));
        }
        $arFileList[] = $obProduct->preview_image;
        $arFileList = array_merge($arFileList, self::fileCollectionToArray($obProduct->images));

        $arPathList = [];
        foreach ($arFileList as $obFile) {
            $sPath = is_object($obFile) ? trim((string) $obFile->path) : '';
            if ($sPath !== '') {
                $arPathList[] = $sPath;
            }
        }

        return array_slice(array_values(array_unique($arPathList)), 0, self::IMAGE_LIMIT);
    }

    /**
     * @param mixed $obFileList October collection, array or null
     * @return array
     */
    protected static function fileCollectionToArray($obFileList): array
    {
        if (is_array($obFileList)) {
            return $obFileList;
        }
        if ($obFileList instanceof \Traversable) {
            return iterator_to_array($obFileList, false);
        }

        return [];
    }

    /**
     * Plain text: block boundaries become spaces, tags stripped, entities
     * decoded, whitespace collapsed. Editors write one paragraph per block
     * with no whitespace between them, so a bare strip_tags glues sentences.
     * @param ElementItem $obProduct
     * @return string
     */
    protected static function description(ElementItem $obProduct): string
    {
        $sSource = (string) $obProduct->description;
        if (trim(strip_tags($sSource)) === '') {
            $sSource = (string) $obProduct->preview_text;
        }

        $sSpaced = (string) preg_replace(self::BLOCK_BOUNDARY_PATTERN, ' ', $sSource);
        $sText = html_entity_decode(strip_tags($sSpaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $sText));
    }

    /**
     * Same shape the visible SKU row prints: SKU-{product}-{offer}.
     * @param ElementItem    $obProduct
     * @param OfferItem|null $obOffer
     * @return string
     */
    protected static function sku(ElementItem $obProduct, $obOffer): string
    {
        if ($obOffer === null || $obOffer->isEmpty()) {
            return 'SKU-' . (int) $obProduct->id;
        }

        return 'SKU-' . (int) $obOffer->product_id . '-' . (int) $obOffer->id;
    }

    /**
     * gtinN for a GS1-valid numeric code, mpn for any other code, nothing for none.
     * @param ElementItem    $obProduct
     * @param OfferItem|null $obOffer
     * @return array
     */
    protected static function identifier(ElementItem $obProduct, $obOffer): array
    {
        $sCode = $obOffer !== null && $obOffer->isNotEmpty() ? trim((string) $obOffer->code) : '';
        if ($sCode === '') {
            $sCode = trim((string) $obProduct->code);
        }
        if ($sCode === '') {
            return [];
        }

        $sGtinProperty = self::GTIN_PROPERTY_BY_LENGTH[strlen($sCode)] ?? null;
        if ($sGtinProperty !== null && self::isValidGs1Code($sCode)) {
            return [$sGtinProperty => $sCode];
        }

        return ['mpn' => $sCode];
    }

    /**
     * A GS1 code Google accepts as a GTIN: known length, valid check digit, and
     * not a restricted-circulation prefix (store-internal codes such as the 1C
     * "2..." range), which Google rejects as invalid GTINs.
     * @param string $sCode
     * @return bool
     */
    public static function isValidGs1Code(string $sCode): bool
    {
        if (!ctype_digit($sCode) || !isset(self::GTIN_PROPERTY_BY_LENGTH[strlen($sCode)])) {
            return false;
        }

        $sPrefix = substr(str_pad($sCode, 14, '0', STR_PAD_LEFT), 1, 3);
        foreach (self::RESTRICTED_GS1_PREFIX_PATTERNS as $sPattern) {
            if (preg_match($sPattern, $sPrefix)) {
                return false;
            }
        }

        $arDigitList = array_map('intval', str_split($sCode));
        $iCheckDigit = array_pop($arDigitList);
        $arDigitList = array_reverse($arDigitList);

        $iSum = 0;
        foreach ($arDigitList as $iIndex => $iDigit) {
            $iSum += $iDigit * ($iIndex % 2 === 0 ? 3 : 1);
        }

        return (10 - ($iSum % 10)) % 10 === $iCheckDigit;
    }

    /**
     * Reviews that carry a rating; a comment-only review cannot be a schema.org Review.
     * @param iterable $obReviewList
     * @return ElementItem[]
     */
    protected static function ratedReviewList(iterable $obReviewList): array
    {
        $arRatedList = [];
        foreach ($obReviewList as $obReview) {
            if (!$obReview instanceof ElementItem || $obReview->isEmpty()) {
                continue;
            }
            $iRating = (int) $obReview->rating;
            if ($iRating < self::RATING_WORST || $iRating > self::RATING_BEST) {
                continue;
            }
            $arRatedList[] = $obReview;
        }

        return $arRatedList;
    }

    /**
     * @param ElementItem[] $arRatedReviewList non-empty
     * @return array
     */
    protected static function aggregateRating(array $arRatedReviewList): array
    {
        $iSum = 0;
        foreach ($arRatedReviewList as $obReview) {
            $iSum += (int) $obReview->rating;
        }
        $iCount = count($arRatedReviewList);

        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => round($iSum / $iCount, 1),
            'reviewCount' => $iCount,
            'bestRating'  => self::RATING_BEST,
            'worstRating' => self::RATING_WORST,
        ];
    }

    /**
     * @param ElementItem $obReview
     * @return array
     */
    protected static function review(ElementItem $obReview): array
    {
        $sAuthor = trim((string) $obReview->name);
        $arReview = [
            '@type'        => 'Review',
            'author'       => ['@type' => 'Person', 'name' => $sAuthor !== '' ? $sAuthor : 'Anonymous'],
            'reviewRating' => [
                '@type'       => 'Rating',
                'ratingValue' => (int) $obReview->rating,
                'bestRating'  => self::RATING_BEST,
                'worstRating' => self::RATING_WORST,
            ],
        ];

        $obCreatedAt = $obReview->created_at;
        if ($obCreatedAt instanceof \DateTimeInterface) {
            $arReview['datePublished'] = $obCreatedAt->format('Y-m-d');
        }

        $sComment = trim((string) $obReview->comment);
        if ($sComment !== '') {
            $arReview['reviewBody'] = $sComment;
        }

        return $arReview;
    }

    /**
     * @param OfferItem $obOffer sellable offer
     * @param string    $sPageUrl
     * @param string    $sSellerName
     * @return array
     */
    protected static function offer(OfferItem $obOffer, string $sPageUrl, string $sSellerName): array
    {
        $sCurrencyCode = trim((string) $obOffer->currency_code);
        if ($sCurrencyCode === '') {
            throw new InvalidArgumentException(sprintf('ProductStructuredData: offer %s has no currency code', $obOffer->id));
        }

        $bInStock = (int) $obOffer->quantity > 0;

        return [
            '@type'           => 'Offer',
            'url'             => $sPageUrl,
            'priceCurrency'   => $sCurrencyCode,
            'price'           => number_format((float) $obOffer->price_value, 2, '.', ''),
            'priceValidUntil' => date('Y-m-d', strtotime(self::PRICE_VALID_INTERVAL)),
            'itemCondition'   => 'https://schema.org/NewCondition',
            'availability'    => $bInStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'seller'          => ['@type' => 'Organization', 'name' => $sSellerName],
        ];
    }
}
