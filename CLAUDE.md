# Logingrupa.StoreExtender

Main store customization plugin (`Logingrupa\StoreExtender`, package `logingrupa/oc-storeextender-plugin`). Extends root `CLAUDE.md`; full `$require` list (`Lovata.*`, `Logingrupa.CustomXMLImportPricing`, `Logingrupa.RetrypaymentShopaholic`, `RainLab.User`, `RainLab.Pages`) is authoritative in `Plugin.php`.

## Architecture Map
- `classes/event/` - per-domain subscribers: `cache/`, `cart/`, `cartposition/`, `category/`, `currency/`, `device/` (`DeviceLayoutHandler`), `googleanalytics/` (margin hooks, idle when GA inactive), `image/`, `import/`, `metapixel/`, `offer/` (`ExtendOfferImportMetadata`, `OfferDiscountImportSubscriber`), `order/`, `orderposition/`, `price/`, `product/`, `property/`, `queue/`, `review/`, `seo/` (legacy slug redirects, 302 locale redirect), `settings/` (`SettingsSiteFallbackHandler`), `user/` (`UserAddress` sync), `usergroup/`.
- `classes/color/` - `ColorApiClient`, `ColorMapRepository`, `OfferColorGrouper`, `ColorFamilyHubReader` (live `/collections` hub & family pages).
- `classes/helper/` - `ShopLayoutBinder` (shared `shop`/`shop-lean` variable bag: user, `iPriceTypeID`, cart, currency, SEO), `UserAddressHelper` (shipping/billing single source of truth), `OfferRenderContext` (Twig `offer_render_context`), `OfferImageHelper` (`nail()` crop + `cover()` uncropped 420x320 hub slot), `SiblingShopIdMap` / `SiblingShopSitemap` / `ShopIdMap` (1C-ID cross-shop hreflang), `CanonicalPageSuffix`, `DeviceHint`, `ViteAssetHelper`, `RoundedCurrencyHelper`, `WholeNumberCurrencyConfig`, `ActivePriceHelper`, `OrderStatusCode` / `OrderPaymentState` / `OrderPageData` / `BankTransferDetails`.
- `classes/mail/` - `SafeMailManager`, `SafeMailer`, `OrderMailState`, `OrderMailData`, `OrderMailSender`, `PaymentReminderSender`.
- `classes/deploy/` - `ReleaseIntegrityVerifier` (pre-activation release checks: active plugins, Twig template compile under strict functions, mirrored Vite assets, `composer.lock` vs `installed.json` parity).
- `classes/twig/` - `OptionalTwigFunctionFallback` (`cms.extendTwig` fail-open no-op fallback + rate-limited error logging for allowlisted `gaEvent` / `gaListBlock`; disabled when `STOREEXTENDER_STRICT_TWIG_FUNCTIONS=1`).
- `classes/middleware/`, `classes/pickup/`, `classes/shipping/`, `classes/ajax/`, `classes/queue/`, `classes/registrar/`.
- `components/` - `CustomProductPage`, `OfferSheet`, `LazyPromoBlockLoader`, `UserPhoneCheck`.
- `console/` - `storeextender:verify-release`, `storeextender:sync-offer-colors`, `import-theme-messages`, `verify-xml-import-settings`, `warm-offer-thumbs`, `warm-sibling-maps`, `forget-sibling-maps`, `refresh-pickup-points`, `parse-legacy-addresses`, `regenerate-slugs`, `send-payment-reminders`, `purge-order-property-secrets`, `sql-import`.
- `controllers/` (`ColorSync`, `UserProperties`), `formwidgets/VideoFormWidget`, `models/OfferColor`, `routes.php` (`/api/shop-id-map.json`, pickup endpoints).

## Quality Gates
```bash
php artisan plugin:test Logingrupa.StoreExtender   # from root
```
**Do not run default root Laravel Pint on this plugin**: Pint's `php_unit_set_up_tear_down_visibility` converts `public function setUp()` to `protected`, breaking October `PluginTestCase`.

## Gotchas
- **ShopLayoutBinder (`2.0.128`)**: binds `iPriceTypeID` on migrated `shop`/`shop-lean` pages so logged-in group price tiers apply on checkout and every Vite page. Add shared layout vars here, never in one layout template.
- **UserAddress single source of truth (`2.0.126`)**: `UserAddressHelper` owns shipping/billing addresses across Backend Users, My Account, and Checkout (one latest row per address type; cleans legacy `undefined`/`NaN`).
- **Cross-shop hreflang (`2.0.119`+)**: pairs `.lv`/`.lt`/`.no` by 1C ID via `/api/shop-id-map.json` + `shop_sibling_url` reading warmed caches (`storeextender:warm-sibling-maps`) and fails closed when cold. Bare-URL browser-language redirects on prefixed shops return `302` with `Vary: Accept-Language` (`2.0.124`); old product slugs redirect even when switched off (`2.0.125`).
- **`mail.manager`**: MUST be wrapped via `$this->app->extend()`, never `singleton()` (deferred `MailServiceProvider` would clobber it).
- **Offer-price import pipeline**: moved to `Logingrupa.CustomXMLImportPricing` (2.0.28/2.0.30).
- **`OfferRenderContext`**: empty/invalid pinned offer throws by design (2.0.27).
- **`onGetOfferBatch` vs `onGetSwatchStrip`**: batch output is uncached (per-visitor wishlist/approval state); swatch strip HTML is cached per colour family without offer ID.
- **`updates/version.yaml`**: validated by `UpdatesVersionYamlTest` - quote strings containing `: ` so YAML always parses cleanly.
