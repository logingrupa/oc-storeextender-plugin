<?php

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Lovata\ReviewsShopaholic\Models\Review;
use October\Rain\Database\ModelException;

/**
 * Public review input is bounded: a rating outside 1..5, an oversized comment
 * or a malformed email fails validation instead of being stored.
 */
class ReviewValidationTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function testRulesAreTightenedOnTheReviewModel()
    {
        $arRules = (new Review)->rules;
        $this->assertSame('nullable|integer|min:1|max:5', $arRules['rating']);
        $this->assertSame('nullable|string|max:2000', $arRules['comment']);
        $this->assertSame('nullable|email|max:191', $arRules['email']);
        $this->assertSame('required|integer', $arRules['product_id']);
    }

    public function testRatingAboveFiveIsRejected()
    {
        $this->expectException(ModelException::class);
        $this->fillReview(['product_id' => 1, 'rating' => 99, 'name' => 'x'])->validate();
    }

    public function testOversizedCommentIsRejected()
    {
        $this->expectException(ModelException::class);
        $this->fillReview(['product_id' => 1, 'rating' => 5, 'comment' => str_repeat('a', 2001)])->validate();
    }

    public function testMalformedEmailIsRejected()
    {
        $this->expectException(ModelException::class);
        $this->fillReview(['product_id' => 1, 'rating' => 5, 'email' => 'not-an-email'])->validate();
    }

    public function testMissingProductIdIsRejected()
    {
        $this->expectException(ModelException::class);
        $this->fillReview(['rating' => 5, 'name' => 'x'])->validate();
    }

    public function testValidReviewPasses()
    {
        $bValid = $this->fillReview([
            'product_id' => 1,
            'rating'     => 5,
            'name'       => 'Anna',
            'comment'    => 'Labs produkts',
            'email'      => 'a@b.lv',
        ])->validate();

        $this->assertTrue($bValid);
    }

    protected function fillReview(array $arData): Review
    {
        $obReview = new Review;
        $obReview->fill($arData);

        return $obReview;
    }
}
