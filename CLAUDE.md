# Logingrupa.StoreExtender

The main store customization plugin for nailscosmetics: offer colour grouping + shade UI,
SafeMailManager wrapper over Laravel mail, currency rounding/whole-number formatting,
user/usergroup approval extensions, cart/order position handlers, offer render context for
larajax fragments, Vite asset helper for migrated theme pages. Namespace
Logingrupa\StoreExtender, composer package logingrupa/oc-storeextender-plugin. Requires
Lovata.DiscountsShopaholic, Lovata.Toolbox, Lovata.Shopaholic, Lovata.OrdersShopaholic,
Logingrupa.CustomXMLImportPricing. See README.MD, SQL_IMPORT_README.md, USAGE_EXAMPLES.md.

## Environment

- Parent app: C:\laragon\www\nc.
- This plugin dir is its OWN git repo - commit here, not in the root repo.

## Architecture map

- classes/event/     per-domain handler dirs: cart/, cartposition/, currency/ (conversion
                     rounding + WholeNumberPriceFormatter), offer/ (ExtendOfferImportMetadata,
                     OfferDiscountImportSubscriber - subscribed to CustomXMLImportPricing
                     after_vat/after_factor hooks), orderposition/, product/, settings/
                     (SettingsSiteFallbackHandler multisite fallback), user/, usergroup/,
                     plus ExtendPaymentGateway, ExtendMenuHandler, ExtendOfferHandler
- classes/color/     ColorApiClient, ColorMapRepository, OfferColorGrouper (shade families)
- classes/helper/    OfferRenderContext (Twig fn offer_render_context - THE single place a
                     fragment resolves which offer to render), ViteAssetHelper,
                     RoundedCurrencyHelper, CurrencyHelperSwapper, ActivePriceHelper,
                     OfferImageHelper, WholeNumberCurrencyConfig
- classes/mail/      SafeMailManager, SafeMailer (mail.manager wrapper)
- components/        CustomProductPage, OfferSheet (shade sheet + swatch strip AJAX),
                     LazyPromoBlockLoader
- console/           storeextender:sql-import, storeextender:sync-offer-colors,
                     storeextender:import-theme-messages, storeextender:verify-xml-import-settings,
                     storeextender:warm-offer-thumbs
- formwidgets/       VideoFormWidget; models/OfferColor; controllers/Groups;
                     config/shopaholic; views/mail; routes.php (legacy /api/offers experiment)

## Quality gates

Own phpunit.xml, unit + integration suites (root CLAUDE.md names it). From plugin dir:

```bash
php ../../../vendor/bin/phpunit
```

composer lint does NOT cover this plugin (phpcs.xml scope excludes plugins/logingrupa) - fix
phpcs.xml scope or lint manually; `vendor/bin/phpcs --standard=phpcs.xml <plugin path>` won't
work either since the ruleset pins files; note as known gap.

## Ship

Ship via /nc-ship (root CLAUDE.md release flow); package logingrupa/oc-storeextender-plugin.

## Conventions

Root CLAUDE.md governs: Hungarian notation, Store -> Collection -> Item read path, Tiger-Style.

## Gotchas

- mail.manager MUST be wrapped with `$this->app->extend()`, never singleton(): Laravel's
  MailServiceProvider is deferred and would clobber a singleton rebind (comment in
  Plugin::register() explains it).
- Offer-price import pipeline moved to Logingrupa.CustomXMLImportPricing (2.0.28, removed
  here 2.0.30). Same XmlImportSettings keys, no data migration - price logic lives THERE.
- OfferRenderContext: a pinned offer that is empty or not an OfferItem THROWS by design -
  the silent fallback to the page's own shade was the original bug (2.0.27).
- onGetOfferBatch output is deliberately NOT cached: gallery/cart fragments carry
  per-visitor wishlist and approval-gate state. Strip HTML (onGetSwatchStrip) IS cached,
  keyed per colour family without offer id (2.0.29).
- version.yaml entries are long prose post-mortems and a unit test checks the file parses
  (UpdatesVersionYamlTest) - keep the format valid YAML.
