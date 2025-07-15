<?php namespace Logingrupa\StoreExtender\Classes\Event;

use Lovata\Toolbox\Classes\Event\ModelHandler;

use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Store\OfferListStore;

/**
 * Class OfferModelHandler
 * @package Lovata\PopularityShopaholic\Classes\Event
 * @author  Andrey Kharanenka, a.khoronenko@lovata.com, LOVATA Group
 */
class ExtendOfferHandler extends ModelHandler
{
    /** @var  Offer */
    protected $obElement;

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        parent::subscribe($obEvent);

        // Offer::extend(function ($obElement) {
        //     /** @var Offer $obElement */
        //     $obElement->fillable[] = 'popularity';
        // });

        $obEvent->listen('shopaholic.sorting.offer.get.list', function ($sSorting) {
            return $this->getSortingList($sSorting);
        });
    }

    /**
     * Get sorting by name ASC
     * @param string $sSorting
     * @return array|null
     */
    protected function getSortingList($sSorting)
    {
        $arOfferIDList = Offer::orderBy('name', 'asc')->lists('id');

        return $arOfferIDList;
    }

    /**
     * Get model class name
     * @return string
     */
    protected function getModelClass()
    {
        return Offer::class;
    }

    /**
     * Get item class name
     * @return string
     */
    protected function getItemClass()
    {
        return OfferItem::class;
    }
}
