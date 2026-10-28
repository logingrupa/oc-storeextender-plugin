<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use System\Classes\PluginManager;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Lovata\OrdersShopaholic\Models\OrderPosition;
use Lovata\FilterShopaholic\Classes\Store\FilterValueStore;

/**
 * Class OrderStockHolds
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * The offer units a checkout took from stock, and moving them with the order's status:
 * a canceled order gives them back, a reopened one takes them again, a shipped one keeps
 * them for good (the goods left, so a later cancel moves nothing). Only checkouts record
 * holds, so backend-made orders and orders placed before the ledger never move stock.
 */
class OrderStockHolds
{
    const TABLE = 'logingrupa_storeextender_order_stock_holds';

    /** Status codes after which the units have left the shop */
    const SHIPPED_LIST = [OrderStatusCode::SENT, OrderStatusCode::COMPLETE];

    /** Lovata's checkout transaction retries a deadlock only at level 0 */
    const TRANSACTION_ATTEMPTS = 3;

    /**
     * Record what each offer of a just-created checkout order lost: the quantity Lovata
     * decremented from, minus the quantity it wrote, never more than the ordered units.
     * Runs inside the checkout transaction, which sees its own writes.
     * @param Order $obOrder
     * @param array $arQuantityBefore offer id => quantity before the positions were created
     * @return void
     */
    public static function record(Order $obOrder, array $arQuantityBefore): void
    {
        $arOrderedList = [];
        foreach (OrderPosition::where('order_id', $obOrder->id)->where('item_type', Offer::class)->get(['item_id', 'quantity']) as $obPosition) {
            $arOrderedList[$obPosition->item_id] = ($arOrderedList[$obPosition->item_id] ?? 0) + (int) $obPosition->quantity;
        }

        $arQuantityAfter = Offer::withTrashed()->whereIn('id', array_keys($arOrderedList))->pluck('quantity', 'id')->all();
        $sNow = now()->toDateTimeString();
        $arRowList = [];
        foreach ($arOrderedList as $iOfferID => $iOrdered) {
            if (!array_key_exists($iOfferID, $arQuantityBefore) || !array_key_exists($iOfferID, $arQuantityAfter)) {
                continue;
            }

            // allow_negative_offer_quantity off clamps at 0: an order above stock takes only what was there
            $iTaken = min($iOrdered, max(0, (int) $arQuantityBefore[$iOfferID] - (int) $arQuantityAfter[$iOfferID]));
            if ($iTaken > 0) {
                $arRowList[] = ['order_id' => $obOrder->id, 'offer_id' => $iOfferID, 'quantity' => $iTaken, 'created_at' => $sNow, 'updated_at' => $sNow];
            }
        }

        if (!empty($arRowList)) {
            DB::table(self::TABLE)->insert($arRowList);
        }
    }

    /**
     * Bring the order's holds in line with its committed status. The order row lock makes
     * concurrent saves of one order take turns; reading the status under it, instead of
     * comparing the saved model with its original, also heals a reconcile that failed before.
     * The ledger rows are locked too: inside a caller's transaction (backend form) a plain read
     * would see the snapshot from before the turn it waited for.
     * @param int $iOrderID
     * @return void
     */
    public static function reconcile(int $iOrderID): void
    {
        if (!self::hasOpenHolds($iOrderID)) {
            return;
        }

        $arMovedOfferIDList = DB::transaction(function () use ($iOrderID) {
            $iStatusID = Order::whereKey($iOrderID)->lockForUpdate()->value('status_id');
            if ($iStatusID === null) {
                // deleted meanwhile: releaseDeleted settles its rows
                return [];
            }

            $sStatusCode = (string) Status::whereKey((int) $iStatusID)->value('code');
            $bCanceled = $sStatusCode === OrderStatusCode::CANCELED;

            $arMoveList = array_filter(self::lockRowList($iOrderID), fn ($obRow) => $obRow->settled_at === null && $bCanceled === ($obRow->returned_at === null));
            $arMovedOfferIDList = self::moveRowList($iOrderID, array_values($arMoveList), $bCanceled);

            if (in_array($sStatusCode, self::SHIPPED_LIST, true)) {
                DB::table(self::TABLE)->where('order_id', $iOrderID)->whereNull('settled_at')->update(['settled_at' => now()->toDateTimeString()]);
            }

            return $arMovedOfferIDList;
        }, self::TRANSACTION_ATTEMPTS);

        self::clearOfferCachesAfterCommit($arMovedOfferIDList);
    }

    /**
     * A deleted order holds nothing: units it still held go back, its rows go.
     * @param int $iOrderID
     * @return void
     */
    public static function releaseDeleted(int $iOrderID): void
    {
        if (!DB::table(self::TABLE)->where('order_id', $iOrderID)->exists()) {
            return;
        }

        $arMovedOfferIDList = DB::transaction(function () use ($iOrderID) {
            $arHeldRowList = array_filter(self::lockRowList($iOrderID), fn ($obRow) => $obRow->settled_at === null && $obRow->returned_at === null);
            $arMovedOfferIDList = self::moveRowList($iOrderID, array_values($arHeldRowList), true);
            DB::table(self::TABLE)->where('order_id', $iOrderID)->delete();

            return $arMovedOfferIDList;
        }, self::TRANSACTION_ATTEMPTS);

        self::clearOfferCachesAfterCommit($arMovedOfferIDList);
    }

    /**
     * The order's ledger rows, locked by primary key. A locking read returns the latest rows even
     * inside an older snapshot; locking by order_id instead would also lock the index gap after
     * the newest order's rows and hold up the next checkout's insert.
     * @param int $iOrderID
     * @return array
     */
    protected static function lockRowList(int $iOrderID): array
    {
        $arRowIDList = DB::table(self::TABLE)->where('order_id', $iOrderID)->pluck('id')->all();
        if (empty($arRowIDList)) {
            return [];
        }

        return DB::table(self::TABLE)->whereIn('id', $arRowIDList)->orderBy('offer_id')->lockForUpdate()->get()->all();
    }

    /**
     * @param int $iOrderID
     * @return bool
     */
    protected static function hasOpenHolds(int $iOrderID): bool
    {
        return DB::table(self::TABLE)->where('order_id', $iOrderID)->whereNull('settled_at')->exists();
    }

    /**
     * Give back (or take again) each row's units through the Offer model, so its caches and
     * filters update like any stock change. Offers lock in id order, the same order every
     * reconcile uses. A re-take records what the clamp at 0 really let it take.
     * @param int   $iOrderID
     * @param array $arRowList ledger rows
     * @param bool  $bGiveBack
     * @return array moved offer ids
     */
    protected static function moveRowList(int $iOrderID, array $arRowList, bool $bGiveBack): array
    {
        if (empty($arRowList)) {
            return [];
        }

        $arOfferIDList = array_map(fn ($obRow) => (int) $obRow->offer_id, $arRowList);
        $obOfferList = Offer::withTrashed()->whereIn('id', $arOfferIDList)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $sNow = now()->toDateTimeString();
        $arMoveLogList = [];
        foreach ($arRowList as $obRow) {
            $iQuantity = (int) $obRow->quantity;
            /** @var Offer|null $obOffer */
            $obOffer = $obOfferList->get((int) $obRow->offer_id);
            if (!empty($obOffer)) {
                $iBefore = (int) $obOffer->quantity;
                $obOffer->quantity = $bGiveBack ? $iBefore + $iQuantity : $iBefore - $iQuantity;
                $obOffer->save();
                $iQuantity = abs((int) $obOffer->quantity - $iBefore);
            }

            DB::table(self::TABLE)->where('id', $obRow->id)->update($bGiveBack
                ? ['returned_at' => $sNow, 'updated_at' => $sNow]
                : ['returned_at' => null, 'quantity' => $iQuantity, 'updated_at' => $sNow]);
            $arMoveLogList[] = 'offer '.$obRow->offer_id.(empty($obOffer) ? ' gone' : ($bGiveBack ? ' +' : ' -').$iQuantity);
        }

        Log::info('Order '.$iOrderID.($bGiveBack ? ' gave back stock: ' : ' took stock again: ').implode(', ', $arMoveLogList));

        return array_keys(array_filter($obOfferList->all()));
    }

    /**
     * The Offer saves cleared the caches inside the transaction; a page rendered before the
     * commit can have cached the old stock again, so clear once more after it.
     * @param array $arOfferIDList
     * @return void
     */
    protected static function clearOfferCachesAfterCommit(array $arOfferIDList): void
    {
        if (empty($arOfferIDList)) {
            return;
        }

        DB::afterCommit(function () use ($arOfferIDList) {
            foreach ($arOfferIDList as $iOfferID) {
                OfferItem::clearCache($iOfferID);
            }

            if (PluginManager::instance()->hasPlugin('Lovata.FilterShopaholic')) {
                FilterValueStore::instance()->quantity->clear();
                FilterValueStore::instance()->offer_quantity->clear();
            }
        });
    }
}
