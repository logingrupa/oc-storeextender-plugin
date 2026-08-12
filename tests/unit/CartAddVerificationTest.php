<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\Cart\CartComponentHandler;

/**
 * The verified Cart::onAdd rests on two pure rules: a payload row must carry
 * a real offer id and a quantity of at least one (the legacy handler let ""
 * from a cleared spinner become a silent no-op behind a success toast), and
 * success means every requested offer is provably among the cart positions.
 */
class CartAddVerificationTest extends TestCase
{
    public function testNormalizeCoercesStringsToIntegers()
    {
        $obHandler = new CartComponentHandler();

        $arResult = $obHandler->normalizeCartPayload([['offer_id' => '4676', 'quantity' => '2']]);

        $this->assertSame([['offer_id' => 4676, 'quantity' => 2]], $arResult);
    }

    public function testNormalizeRejectsEmptyQuantity()
    {
        $obHandler = new CartComponentHandler();

        $this->assertSame([], $obHandler->normalizeCartPayload([['offer_id' => '4676', 'quantity' => '']]));
        $this->assertSame([], $obHandler->normalizeCartPayload([['offer_id' => '4676', 'quantity' => '0']]));
        $this->assertSame([], $obHandler->normalizeCartPayload([['offer_id' => '', 'quantity' => '1']]));
        $this->assertSame([], $obHandler->normalizeCartPayload('not-an-array'));
        $this->assertSame([], $obHandler->normalizeCartPayload([]));
    }

    public function testAllOffersInCartFindsThePosition()
    {
        $obHandler = new CartComponentHandler();
        $arCartData = ['position' => ['299008' => ['item_id' => 4676, 'quantity' => 1]]];

        $this->assertTrue($obHandler->allOffersInCart([['offer_id' => 4676]], $arCartData));
        $this->assertFalse($obHandler->allOffersInCart([['offer_id' => 9999]], $arCartData));
    }

    public function testAllOffersInCartFailsOnEmptyCart()
    {
        $obHandler = new CartComponentHandler();

        $this->assertFalse($obHandler->allOffersInCart([['offer_id' => 4676]], []));
        $this->assertFalse($obHandler->allOffersInCart([['offer_id' => 4676]], ['position' => []]));
    }
}
