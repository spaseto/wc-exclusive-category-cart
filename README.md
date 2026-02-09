# WC Exclusive Category Cart (Pickup Locations)

## Overview
`WC Exclusive Category Cart (Pickup Locations)` is a WooCommerce plugin that enforces separate ordering flows based on product category and pickup location rules:

- Category A products must be ordered separately (Location A pickup).
- All non-Category A products must be ordered separately from Category A (Location B pickup).
- Category A products can be purchased together.
- Non-Category A products can be purchased together.

The plugin is ZIP-ready for **WP Admin -> Plugins -> Add New -> Upload Plugin**.

## Full Feature List
- Admin settings page under **WooCommerce -> Pickup Cart Rules**.
- Category A selector from `product_cat` taxonomy (includes all child categories automatically).
- Configurable pickup method IDs for Location A and Location B (example: `local_pickup:3` and `local_pickup:4`).
- Add-to-cart blocking logic with clear pickup-location messaging.
- Safety-net cart validation at checkout/cart to prevent mixed-location carts.
- Smart resolve mode:
  - `Block` (default)
  - `Clear cart and add clicked item` (secure nonce-based link)
- Test mode (dry run): never blocks; warns and logs decisions.
- Pickup method auto-detection:
  - Cart contains Category A -> desired pickup method A
  - Cart contains only non-A items -> desired pickup method B
- Optional auto-selection of desired pickup method in session.
- Optional hiding of the wrong pickup rate (per shipping package, with multi-package support).
- Missing desired pickup-rate notice:
  - `Pickup option for this cart is not available. Please check shipping zones and pickup configuration.`
- WooCommerce logger integration (`source: wc-ecc`) for test-mode diagnostics.
- Classic + WooCommerce Blocks notice styling on cart/checkout.
- WooCommerce-inactive protection with admin notice (no fatal errors).

## Installation
### Option A: ZIP Upload (recommended)
1. Ensure this plugin folder exists:
   - `wc-exclusive-category-cart/`
2. Zip that folder (not the repository root), producing:
   - `wc-exclusive-category-cart.zip`
3. In WordPress admin, go to:
   - **Plugins -> Add New -> Upload Plugin**
4. Upload ZIP and activate.

### Option B: Manual Install
1. Copy `wc-exclusive-category-cart/` into `wp-content/plugins/`.
2. Activate **WC Exclusive Category Cart (Pickup Locations)** from **Plugins**.

## Configuration (Step-by-Step)
1. Open **WooCommerce -> Pickup Cart Rules**.
2. Select **Category A (exclusive)**.
3. Set **Pickup method ID for Location A** (example: `local_pickup:3`).
4. Set **Pickup method ID for Location B** (example: `local_pickup:4`).
5. Keep **Auto-select pickup method** enabled (recommended).
6. Keep **Hide the wrong pickup method** enabled (recommended).
7. Choose **Smart resolve mode**:
   - `Block`: hard stop when cart mixing is attempted.
   - `Clear cart and add clicked item`: offers a secure one-click resolve link.
8. Save changes.

## How to Find Shipping Method IDs
1. Go to **WooCommerce -> Settings -> Shipping -> Shipping zones**.
2. Edit the relevant shipping zone.
3. Open each shipping method row and inspect its method/rate ID.
4. IDs usually look like:
   - `local_pickup:3`
   - `local_pickup:4`

Use those exact strings in plugin settings.

## Test Mode Explanation
When **Test mode** is enabled:
- Add-to-cart and checkout/cart safety checks do not block.
- The plugin shows warnings instead of hard errors.
- Decisions are logged with WooCommerce logger source `wc-ecc`:
  - cart mode
  - desired pickup
  - rate hiding decisions (or would-hide in test mode)
  - forcing decisions (or skipped-forcing in test mode)
  - missing configuration/rate situations

Use this to validate behavior before switching to strict enforcement.

## Troubleshooting
- Pickup options not changing:
  - Verify both pickup method IDs are set.
  - Confirm those rate IDs actually exist in your shipping zones.
- Wrong method is not hidden:
  - Ensure **Hide the wrong pickup method** is enabled.
  - Hiding only applies if the desired method exists in that package's rates.
- Mixed cart not blocked:
  - Confirm Category A is selected in settings.
  - Check if Test mode is enabled.
- Variable product clear-and-add fails:
  - Re-save product variations and ensure variation is purchasable/in stock.
- WooCommerce Blocks checkout notice style looks unchanged:
  - Clear caches/minification and retest cart/checkout.

## Testing Checklist
- [ ] Set Category A and both pickup method IDs.
- [ ] Add two Category A products -> allowed.
- [ ] Add two non-A products -> allowed.
- [ ] Add Category A then non-A -> blocked or smart resolve offered.
- [ ] Add non-A then Category A -> blocked or smart resolve offered.
- [ ] Enable `Clear cart and add clicked item` and verify secure resolve link works.
- [ ] Enable Test mode and verify no hard blocking occurs.
- [ ] Verify desired pickup method is auto-selected for cart composition.
- [ ] Verify wrong pickup method is hidden only when desired method exists.
- [ ] Validate missing desired-rate notice by temporarily removing expected rate.
- [ ] Confirm notices are styled on both classic and block-based cart/checkout.

## Plugin Structure
```text
README.md
LICENSE
wc-exclusive-category-cart/
  wc-exclusive-category-cart.php
  assets/css/notices.css
```

## Changelog
### 1.0.0
- Initial release.
- Added exclusive Category A cart separation rules.
- Added pickup method auto-detection, optional auto-select, and rate hiding.
- Added test mode, smart resolve mode, logging, and classic/blocks notice styling.
