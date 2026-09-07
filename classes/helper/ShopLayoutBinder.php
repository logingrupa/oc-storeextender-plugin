<?php namespace Logingrupa\StoreExtender\Classes\Helper;

use Request;
use BackendAuth;
use Cms\Classes\ComponentManager;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\PriceTypeHelper;

/**
 * Class ShopLayoutBinder
 * @package Logingrupa\StoreExtender\Classes\Helper
 *
 * Writes the shared layout variable bag and registers the components every shop
 * page reads. Both layouts/shop.htm and layouts/shop-lean.htm call this and
 * nothing else, so the two cannot drift apart.
 *
 * The ActivePriceHelper call below is the access control for price tiers: drop
 * it and every visitor is served the izpl distributor prices.
 *
 * October re-runs the layout onInit on the Larajax partial-capture path, so a
 * re-rendered fragment gets the same bag - the same price type, the same VAT
 * flag - as the page render that asked for it.
 */
class ShopLayoutBinder
{
    const CART_COMPONENT_CLASS = 'Lovata\OrdersShopaholic\Components\Cart';
    const CART_COMPONENT_ALIAS = 'Cart';
    const SEO_COMPONENT_CLASS = 'Lovata\MightySeo\Components\SeoToolbox';
    const SEO_COMPONENT_ALIAS = 'SeoToolbox';

    /**
     * Register the shop components and populate the layout variables the theme reads
     * @param \Cms\Classes\CodeBase $obLayout
     * @return void
     */
    public static function bind($obLayout)
    {
        if (empty($obLayout)) {
            throw new \InvalidArgumentException('ShopLayoutBinder::bind() needs the layout object');
        }

        $obLayout['cart_is_available'] = false;
        $obLayout['showEditButton'] = false;

        $obManager = ComponentManager::instance();
        if ($obManager->hasComponent(self::CART_COMPONENT_CLASS)) {
            $obLayout['cart_is_available'] = true;
            self::resolveComponent($obLayout, self::CART_COMPONENT_CLASS, self::CART_COMPONENT_ALIAS);
        }

        // Read-only request: badge + cart-state ride one indexed CartStateReader
        // read; the full CartProcessor build (and its cart row INSERT) stays on POST
        $obLayout['bSkipCartBuild'] = !Request::isMethod('post');
        $obLayout['arCartState'] = $obLayout['bSkipCartBuild']
            ? CartStateReader::getState()
            : null;

        if ($obManager->hasComponent(self::SEO_COMPONENT_CLASS)) {
            $obLayout['seo_toolbox_is_available'] = true;
            self::resolveComponent($obLayout, self::SEO_COMPONENT_CLASS, self::SEO_COMPONENT_ALIAS);
        }

        if (BackendAuth::getUser()) {
            $obLayout['showEditButton'] = true;
        }

        ThemeUserBinder::bind($obLayout);

        $obLayout['activeCurrencyCode'] = CurrencyHelper::instance()->getActiveCurrencyCode();

        ActivePriceHelper::instance()->setActivePriceType();
        // If price type is other then Main or salona - it does not include VAT
        $obLayout['sPriceType'] = PriceTypeHelper::instance()->getActivePriceTypeCode();
        if (is_null($obLayout['sPriceType']) || $obLayout['sPriceType'] == 'salona' || $obLayout['sPriceType'] == 'vairum') {
            $obLayout['bPriceIncludesVAT'] = true;
        } else {
            $obLayout['bPriceIncludesVAT'] = false;
        }
    }

    /**
     * Reuse the instance the layout INI declared, otherwise register one
     * @param \Cms\Classes\CodeBase $obLayout
     * @param string                $sClass
     * @param string                $sAlias
     * @return \Cms\Classes\ComponentBase|null
     */
    protected static function resolveComponent($obLayout, $sClass, $sAlias)
    {
        // addComponent would shadow a configured instance with a bare one.
        $obComponent = $obLayout->{$sAlias} ?? null;
        if (!empty($obComponent)) {
            return $obComponent;
        }

        return $obLayout->addComponent($sClass, $sAlias, []);
    }
}
