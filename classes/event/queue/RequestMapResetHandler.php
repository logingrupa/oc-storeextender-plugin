<?php namespace Logingrupa\StoreExtender\Classes\Event\Queue;

use Illuminate\Queue\Events\JobProcessing;
use Lovata\Shopaholic\Classes\Item\CategoryItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;

/**
 * Class RequestMapResetHandler
 * @package Logingrupa\StoreExtender\Classes\Event\Queue
 *
 * ProductItem's active offer map and CategoryItem's active children map (root
 * composer patches) are primed once per process. A queue:work daemon outlives
 * one job, so each job starts with both maps unprimed, like a web request.
 */
class RequestMapResetHandler
{
    /**
     * Add listeners
     * @param mixed $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen(JobProcessing::class, function () {
            ProductItem::clearActiveOfferMap();
            CategoryItem::clearActiveChildrenMap();
        });
    }
}
