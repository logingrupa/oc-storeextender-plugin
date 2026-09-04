<?php namespace Logingrupa\StoreExtender\Classes\Event\Review;

use Lovata\ReviewsShopaholic\Models\Review;

/**
 * Anonymous visitors post reviews through MakeReview with the product id as the
 * only rule, so a request can set any rating and an unbounded comment. These
 * rules bound the public input; moderation stays with the review_activation
 * setting, which beforeCreate reads.
 */
class ReviewValidationHandler
{
    protected const RULES = [
        'product_id' => 'required|integer',
        'rating'     => 'nullable|integer|min:1|max:5',
        'name'       => 'nullable|string|max:191',
        'email'      => 'nullable|email|max:191',
        'phone'      => 'nullable|string|max:64',
        'comment'    => 'nullable|string|max:2000',
    ];

    /**
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        if (!class_exists(Review::class)) {
            return;
        }

        Review::extend(function (Review $obReview) {
            $obReview->rules = array_merge($obReview->rules, self::RULES);
        });
    }
}
