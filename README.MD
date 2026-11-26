# StoreExtender Plugin for October CMS v4

Core storefront customization plugin for [Lovata Shopaholic](https://octobercms.com/plugin/lovata-shopaholic) by **Logingrupa** (`logingrupa/oc-storeextender-plugin`).

For the full architecture map, console commands, and agent gotchas, see [CLAUDE.md](CLAUDE.md).

## Key Capabilities
- **Layout & Vite integration**: `ShopLayoutBinder` (shared `shop`/`shop-lean` layout vars including user group `iPriceTypeID`), `ViteAssetHelper` (`vite_entry()`), `DeviceLayoutHandler`.
- **Shades & Collections**: `OfferSheet` component, Color Lab sync (`storeextender:sync-offer-colors`), `ColorFamilyHubReader`, `OfferImageHelper` (`nail()` crop + `cover()` uncropped hub slot).
- **Addresses & Checkout**: `UserAddressHelper` (single source of truth for shipping/billing), `UserPhoneCheck`, `OrderPageData`, `SafeMailManager` + transactional order/reminder mails.
- **SEO & Multisite**: 1C-ID cross-shop `hreflang` (`/api/shop-id-map.json`, `warm-sibling-maps`), legacy slug 301 redirects, `302 + Vary: Accept-Language` locale redirects, `SettingsSiteFallbackHandler`, currency rounding (`RoundedCurrencyHelper`, `WholeNumberCurrencyConfig`).
- **Pricing ownership**: offer import pricing lives in `Logingrupa.CustomXMLImportPricing` and quantity campaigns in `Logingrupa.CampaignPricingShopaholic`; StoreExtender handles user-group price type binding and display formatting.

## Testing
```bash
php artisan plugin:test Logingrupa.StoreExtender
```

See also `SQL_IMPORT_README.md` and `USAGE_EXAMPLES.md`.
