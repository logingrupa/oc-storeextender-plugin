<?php namespace Logingrupa\StoreExtender\Classes\Shipping;

use Lovata\CampaignsShopaholic\Classes\Helper\CampaignHelper;
use Lovata\CampaignsShopaholic\Classes\Store\ShippingTypeListStore;
use Lovata\OrdersShopaholic\Classes\Collection\ShippingTypeCollection;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\AbstractPromoMechanism;
use Lovata\OrdersShopaholic\Classes\PromoMechanism\InterfacePromoMechanism;
use Lovata\OrdersShopaholic\Models\PromoMechanism;

/**
 * The TYPE_SHIPPING mechanisms that would reach CartPromoMechanismProcessor
 * for each active shipping type, from the same two sources it uses: active
 * campaigns (Lovata\CampaignsShopaholic PromoMechanismHandler) and auto-add
 * mechanisms (CartPromoMechanismProcessor::initMechanismList). Coupon
 * mechanisms are left out on purpose: they apply only once a coupon is in
 * the cart, so a ladder cannot promise them.
 *
 * A mechanism's own property.shipping_type_id and property.payment_method_id
 * are not read here; AbstractPromoMechanism::check() applies them when the
 * ladder replays the mechanism.
 */
class ShippingMechanismCollector
{
    /**
     * @return array<int, InterfacePromoMechanism[]> keyed by active shipping type id
     */
    public static function collect(): array
    {
        $arActiveTypeIdList = array_map('intval', ShippingTypeCollection::make()->active()->getIDList());
        $arResult = array_fill_keys($arActiveTypeIdList, []);

        self::addCampaignMechanisms($arResult, $arActiveTypeIdList);
        self::addAutoAddMechanisms($arResult, $arActiveTypeIdList);

        return $arResult;
    }

    /**
     * Campaign mechanisms, scoped to the campaign's shipping types (empty
     * scope = every active type), with the same scope callback the campaign
     * handler attaches so check() sees it.
     * @param array $arResult
     * @param array $arActiveTypeIdList
     * @return void
     */
    protected static function addCampaignMechanisms(array &$arResult, array $arActiveTypeIdList)
    {
        foreach (CampaignHelper::instance()->getActiveCampaignList() as $obCampaign) {
            // increase mechanisms load as null through the relation condition
            $obMechanism = $obCampaign->getPromoMechanismObject();
            if (!self::isShippingMechanism($obMechanism)) {
                continue;
            }

            $arScopeIdList = array_map('intval', ShippingTypeListStore::instance()->campaign->get($obCampaign->id));
            $obMechanism->setCheckShippingTypeCallback(function ($obShippingType) use ($arScopeIdList) {
                if (empty($obShippingType)) {
                    return false;
                }

                return empty($arScopeIdList) || in_array((int) $obShippingType->id, $arScopeIdList, true);
            });

            $arTargetIdList = empty($arScopeIdList) ? $arActiveTypeIdList : array_intersect($arActiveTypeIdList, $arScopeIdList);
            self::attach($arResult, $arTargetIdList, $obMechanism);
        }
    }

    /**
     * Auto-add mechanisms land on every active type with a pass-through
     * callback, as CartPromoMechanismProcessor::initMechanismList() does.
     * @param array $arResult
     * @param array $arActiveTypeIdList
     * @return void
     */
    protected static function addAutoAddMechanisms(array &$arResult, array $arActiveTypeIdList)
    {
        foreach (PromoMechanism::getAutoAdd()->get() as $obPromoMechanism) {
            $obMechanism = $obPromoMechanism->getTypeObject();
            if (!self::isShippingMechanism($obMechanism)) {
                continue;
            }

            $obMechanism->setCheckShippingTypeCallback(function () {
                return true;
            });
            self::attach($arResult, $arActiveTypeIdList, $obMechanism);
        }
    }

    /**
     * @param mixed $obMechanism
     * @return bool
     */
    protected static function isShippingMechanism($obMechanism): bool
    {
        return $obMechanism instanceof InterfacePromoMechanism
            && $obMechanism::getType() === AbstractPromoMechanism::TYPE_SHIPPING;
    }

    /**
     * @param array                   $arResult
     * @param array                   $arTypeIdList
     * @param InterfacePromoMechanism $obMechanism
     * @return void
     */
    protected static function attach(array &$arResult, array $arTypeIdList, InterfacePromoMechanism $obMechanism)
    {
        foreach ($arTypeIdList as $iTypeId) {
            $arResult[$iTypeId][] = $obMechanism;
        }
    }
}
