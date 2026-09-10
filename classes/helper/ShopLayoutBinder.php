<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Request;
use BackendAuth;
use Cms\Classes\ComponentManager;
use Lovata\Toolbox\Classes\Helper\UserHelper;
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
 * The ActivePriceHelper call in bindPriceTier() is the access control for price
 * tiers: drop it and every visitor is served the izpl distributor prices.
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

    /** @var array<string> trade tiers quoted with VAT, like the public price */
    const VAT_INCLUSIVE_PRICE_TYPE_LIST = ['salona', 'vairum'];

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

        // First, before any UserHelper read: CartStateReader and the
        // ActivePriceHelper singleton both resolve the shopper through it
        self::primeUserGuard();

        $obLayout['cart_is_available'] = false;
        $obLayout['seo_toolbox_is_available'] = false;
        $obLayout['showEditButton'] = BackendAuth::getUser() !== null;

        self::registerComponents($obLayout);

        // Read-only request: badge + cart-state ride one indexed CartStateReader
        // read; the full CartProcessor build (and its cart row INSERT) stays on POST
        $obLayout['bSkipCartBuild'] = !Request::isMethod('post');
        $obLayout['arCartState'] = $obLayout['bSkipCartBuild']
            ? CartStateReader::getState()
            : null;

        ThemeUserBinder::bind($obLayout);

        $obLayout['activeCurrencyCode'] = CurrencyHelper::instance()->getActiveCurrencyCode();

        self::bindPriceTier($obLayout);
    }

    /**
     * Load the session user into the auth guard. On RainLab.User the guard's
     * getUser() answers only the user already loaded in this request, so a read
     * through UserHelper before check() sees a guest.
     * @return void
     */
    protected static function primeUserGuard()
    {
        $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
        if (empty($sAuthFacadeClass)) {
            return;
        }

        $sAuthFacadeClass::check();
    }

    /**
     * Register the cart and the SEO toolbox, each only when its plugin is installed
     * @param \Cms\Classes\CodeBase $obLayout
     * @return void
     */
    protected static function registerComponents($obLayout)
    {
        $obManager = ComponentManager::instance();

        if ($obManager->hasComponent(self::CART_COMPONENT_CLASS)) {
            $obLayout['cart_is_available'] = true;
            self::resolveComponent($obLayout, self::CART_COMPONENT_CLASS, self::CART_COMPONENT_ALIAS);
        }

        if ($obManager->hasComponent(self::SEO_COMPONENT_CLASS)) {
            $obLayout['seo_toolbox_is_available'] = true;
            self::resolveComponent($obLayout, self::SEO_COMPONENT_CLASS, self::SEO_COMPONENT_ALIAS);
        }
    }

    /**
     * Access control for price tiers, then the VAT flag the price partial reads:
     * the public price and the salon and wholesale tiers include VAT, every
     * other tier is quoted without it
     * @param \Cms\Classes\CodeBase $obLayout
     * @return void
     */
    protected static function bindPriceTier($obLayout)
    {
        ActivePriceHelper::instance()->setActivePriceType();

        $sPriceType = PriceTypeHelper::instance()->getActivePriceTypeCode();
        $obLayout['sPriceType'] = $sPriceType;
        $obLayout['bPriceIncludesVAT'] = $sPriceType === null
            || in_array($sPriceType, self::VAT_INCLUSIVE_PRICE_TYPE_LIST, true);
    }

    /**
     * Reuse the instance the layout INI declared, otherwise register one
     * @param \Cms\Classes\CodeBase $obLayout
     * @param string                $sClass
     * @param string                $sAlias
     * @return void
     */
    protected static function resolveComponent($obLayout, $sClass, $sAlias)
    {
        // addComponent would shadow a configured instance with a bare one
        if (isset($obLayout->{$sAlias})) {
            return;
        }

        $obLayout->addComponent($sClass, $sAlias, []);
    }
}
