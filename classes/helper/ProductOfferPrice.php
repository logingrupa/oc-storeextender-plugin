<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;
use Lovata\Shopaholic\Classes\Item\OfferItem;

/**
 * The price a shopper can pay for an offer, in the form crawlers read.
 *
 * An offer counts when it is resolved, not empty and priced above zero. The
 * amount has two decimals with a point, the currency is the offer's currency
 * code.
 *
 * Read by ProductStructuredData for the Offer of the Product JSON-LD, and by
 * the theme head for the product:price metas through the Twig function
 * product_offer_price (Plugin::registerMarkupTags()), so the two figures
 * cannot disagree.
 */
class ProductOfferPrice
{
    /**
     * @param OfferItem|null $obOffer offer the page resolved, null when none
     * @return array|null ['amount' => '8.90', 'currency' => 'EUR'], null when nobody can buy the offer
     */
    public static function resolve($obOffer): ?array
    {
        if ($obOffer === null) {
            return null;
        }
        if (!$obOffer instanceof OfferItem) {
            throw new InvalidArgumentException(sprintf(
                'ProductOfferPrice: obOffer must be an OfferItem or null, %s given',
                is_object($obOffer) ? get_class($obOffer) : gettype($obOffer)
            ));
        }

        if ($obOffer->isEmpty()) {
            return null;
        }

        $fPrice = (float) $obOffer->price_value;
        if ($fPrice <= 0) {
            return null;
        }

        $sCurrencyCode = trim((string) $obOffer->currency_code);
        if ($sCurrencyCode === '') {
            throw new InvalidArgumentException(sprintf('ProductOfferPrice: offer %s has no currency code', $obOffer->id));
        }

        return [
            'amount'   => number_format($fPrice, 2, '.', ''),
            'currency' => $sCurrencyCode,
        ];
    }
}
