<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Cookie;
use Crypt;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Models\Cart;
use Lovata\OrdersShopaholic\Models\CartPosition;

/**
 * Class CartStateReader
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Read-only cart state for non-POST requests: the header badge count and the
 * item_id => quantity map that partials/cart-state emits. Resolves the cart
 * the same way CartProcessor::init() does (user cart first, then the
 * shopaholic_cart_id cookie) but never creates a cart row and never runs the
 * position/promo build - mutations stay on POST, where CartProcessor already
 * pays for the full build.
 *
 * A user whose guest cart was not merged yet (merge happens on the next POST)
 * sees their user cart if one exists, else the guest cookie cart - the same
 * positions the merge would produce unless both carts hold items.
 */
class CartStateReader
{
    /**
     * Get badge count and item_id => quantity map for the current visitor
     * @return array{count: int, positions: array<int, int>}
     */
    public static function getState(): array
    {
        $iCartID = static::resolveCartID();
        if (empty($iCartID)) {
            return ['count' => 0, 'positions' => []];
        }

        // Secondary index (cart_id, id) serves this in id order, matching
        // CartPositionCollection iteration; soft-deleted rows excluded by scope
        $obPositionList = CartPosition::getByCart($iCartID)
            ->orderBy('id')
            ->toBase()
            ->get(['item_id', 'quantity']);

        $arPositionMap = [];
        foreach ($obPositionList as $obPositionRow) {
            $arPositionMap[(int) $obPositionRow->item_id] = (int) $obPositionRow->quantity;
        }

        return ['count' => count($obPositionList), 'positions' => $arPositionMap];
    }

    /**
     * Offer ids currently in the cart, cheapest possible read: one indexed
     * query, no cart creation, no position/promo build
     * @return array<int>
     */
    public static function getOfferIdList(): array
    {
        $iCartID = static::resolveCartID();
        if (empty($iCartID)) {
            return [];
        }

        $arOfferIdList = CartPosition::getByCart($iCartID)
            ->where('item_type', \Lovata\Shopaholic\Models\Offer::class)
            ->toBase()
            ->pluck('item_id')
            ->all();

        return array_map('intval', $arOfferIdList);
    }

    /**
     * Resolve the visitor's cart ID without creating one
     * @return int|null
     */
    protected static function resolveCartID(): ?int
    {
        $obUser = UserHelper::instance()->getUser();
        if (!empty($obUser)) {
            $iUserCartID = Cart::getByUser($obUser->id)->value('id');
            if (!empty($iUserCartID)) {
                return (int) $iUserCartID;
            }
        }

        $iCartID = Cookie::get(CartProcessor::COOKIE_NAME, CartProcessor::$iTestCartID);
        if (!empty($iCartID) && !is_numeric($iCartID)) {
            try {
                $iCartID = Crypt::decryptString($iCartID);
            // Unusable cookie value reads as no cart; ClearShadowCartCookie expires it
            } catch (\Exception $obException) {
                return null;
            }
        }

        if (empty($iCartID) || !is_numeric($iCartID)) {
            return null;
        }

        return (int) $iCartID;
    }
}
