
# Store Extender Plugin for OctoberCMS

This plugin is designed to extend the functionality of the [Lovata Shopaholic](https://octobercms.com/plugin/lovata-shopaholic) plugin for OctoberCMS. 
Developed by Logingrupa, it provides additional features and customizations for an enhanced e-commerce experience.

## Features

The plugin includes the following extensions and enhancements:

### Event Handlers

1. **Extend Menu Handler**:
   - Dynamically manages backend navigation menus.
   - Allows adding, removing, or modifying menu items based on business logic.

2. **Extend Offer Handler**:
   - Adds advanced options for managing product offers:
     - Configures tiered pricing based on quantity or customer group.
     - Sets specific rules for discounts tied to time-limited events.
     - Links promotional offers to marketing campaigns or specific product categories.

3. **Extend Payment Gateway**:
   - Introduces support for custom payment methods.
   - Extends existing gateways to handle additional payment features such as transaction logging or custom tax handling.

4. **Cart and Order Position Handlers**:
   - Customizes cart item attributes (e.g., adding product personalization options).
   - Enhances order item management with additional metadata, such as packaging instructions.

### Customization Options

- **Additional Settings**:
  - Introduces new configuration tabs in the admin backend via `addAditionalSettingsTab.yaml`.
  - Allows videos to be previewed in the product image section with `addToImagesTabVideoPreview.yaml`.

### Components

1. **CustomProductPage**:
   - Enables the creation of customized product pages with support for:
     - Detailed product specifications.
     - Interactive media such as embedded videos or downloadable files.

### Model Extensions

1. **User Model Enhancements**:
   - Adds user-specific attributes for personalized offers or preferences.
   - Automates user notification for group-specific promotions.

2. **Offer Model Enhancements**:
   - Introduces new relationships for connecting offers to seasonal campaigns or featured products.

3. **User Group Extensions**:
   - Supports the management of pricing tiers and shipping rules specific to user groups.

### Form Widgets

- **VideoFormWidget**:
  - Uploads, previews, and associates videos with products or offers directly in the backend.

### Database Updates

1. **Schema Changes**:
   - Updates tables such as `lovata_shopaholic_products` and `lovata_shopaholic_offers` to support new features.
   - Adds fields for video previews, additional pricing metadata, and custom offer settings.

2. **Migrations**:
   - Includes migration scripts like `seeder_create_default_discounts.php` to initialize default data.

### Helpers

- **ActivePriceHelper**:
  - Calculates product prices dynamically:
    - Applies discounts in real-time based on user group or campaign conditions.
    - Supports tiered and group-specific pricing logic.

### Language Support

Supports multilingual setups with translations in:
  - English
  - German
  - Latvian
  - Russian

### Views

- Custom frontend views (`offer_colors.htm`, `offer_price_list.htm`) for enhanced offer and pricing presentations.

## Installation

1. Run 
```bash
php artisan plugin:install Logingrupa.StoreExtender --from=git@github.com:logingrupa/oc-storeextender-plugin.git --want=dev-master --oc
```

## About

This plugin was developed by **Logingrupa** to enhance the capabilities of the Lovata Shopaholic plugin for OctoberCMS. It provides a comprehensive set of tools and extensions to build a robust e-commerce platform.

