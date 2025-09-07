# WooCommerce US Duties & Customs — Documentation

This plugin estimates US import duties and fees, adds an optional checkout fee/notice, and provides an admin hub to manage customs profiles keyed by product description and country of origin. It is HPOS compatible and works with multi-currency (e.g., WPML).

- Plugin bootstrap: `wc-us-duty.php`
- Core engine: `includes/class-wrd-duty-engine.php`
- Frontend output: `includes/class-wrd-frontend.php`
- Admin UI: `includes/class-wrd-admin.php`
- Settings: `includes/class-wrd-settings.php`
- DB access: `includes/class-wrd-db.php`
- FX: `includes/class-wrd-fx.php`
- Developer template tags/shortcode: `includes/template-tags.php`

Compatibility

- WordPress 6.1+
- WooCommerce 7.0–9.2 (tested)
- PHP 7.4+
- HPOS: declared compatible


## 1) Quick start (Basics)

1. Install and activate the plugin.
2. Go to WooCommerce → Customs & Duties (submenu):
   - Import Customs Profiles via CSV or Zonos JSON (see Import/Export below).
   - In Settings, set “US Only”, fee label, and display options.
   - (Optional) Configure FX provider cache and shipping channel keyword rules.
3. Open a product and set the two trade fields on the Shipping tab:
   - `_customs_description` (commercial description)
   - `_country_of_origin` (ISO-2, e.g., CA, CN, TW)
   Variations can override these fields; they fall back to the parent if empty.
4. Add an item to cart and proceed to checkout. You’ll see:
   - A duty fee line item (DDP) or a notice (DAP), depending on the “Checkout Mode”.
   - A “Details” toggle with a breakdown of rates per line (if enabled).
5. (Optional) Enable the Product Page Hint in Settings → Display to show an estimate below price / below add-to-cart / in product meta.


## 2) What customers see (Overview)

- Checkout:
  - A fee line (“Estimated US Duties”) added via `woocommerce_cart_calculate_fees` when mode is DDP/charge.
  - Or a notice (when mode is info/DAP).
  - A small “Details” toggle beside the fee amount expands a table of items, rates, duties, and totals.
- Cart:
  - The same “Details” panel can be shown under totals to review the composition.
  - Optional per-line inline hints on item names (cart and/or checkout) showing “Duty est: $X.XX” or “CUSMA”.
- Product page (optional):
  - A compact hint below the price (or chosen position) with “CUSMA: duty-free to US” or “Estimated US duties: $X.XX (Y.YY%)”.


## 3) Admin hub

Open: WooCommerce → Customs & Duties. Tabs:

- Profiles
- Import/Export
- Settings
- Tools

### 3.1 Profiles

Grid of customs profiles from the custom table `wrd_customs_profiles`.

Columns include description, country, HS, version dates, notes, and a Products count link (drilldown).

Actions:
- Add New (create a profile row)
- Edit (update fields)
- Delete (bulk action)
- Products count → opens the “Impacted Products” list filtered by the normalized description and country.

Impacted Products table (`includes/admin/class-wrd-impacted-products-table.php`) shows products and variations whose normalized description `_wrd_desc_norm` and origin `_wrd_origin_cc` match the selected profile key.

### 3.2 Import/Export

- Import CSV (Profiles): upload a CSV with columns: `description,country_code,hs_code,fta_flags,us_duty_json,effective_from,effective_to,notes`.
- Import JSON (Zonos dump): choose JSON, set “Effective From”, choose whether to update existing entries with same `description|country` and date, and add notes.
- Assign Customs to Products (CSV): update product metadata in bulk. Columns can be mapped, and you may identify products by ID or SKU.
- Export CSV (Profiles): available via Profiles or Import/Export tabs for convenience.

Scripts to help seed data are provided in `scripts/` and explained in `docs/seed.md`:
- `scripts/zonos_json_to_customs_csv.php`
- `scripts/zonos_json_to_sql.php`

### 3.3 Settings

Settings are rendered by `includes/class-wrd-settings.php` and grouped as follows.

- General
  - Checkout Mode (`ddp_mode`):
    - `charge` — add duties as a non-taxable fee (DDP)
    - `info` — show a notice only (DAP)
  - Fee Label (`fee_label`): text for the fee row
  - US Only (`us_only`): only estimate/show for US destination
  - Missing Profile Behavior (`missing_profile_behavior`): `fallback` (treat as 0 duty) or `block` (prevent checkout)
  - Min Split Savings (USD) (`min_split_savings`): reserved for future split routing heuristics
  - Postal Informal Threshold (USD) (`postal_informal_threshold_usd`): reserved/visible for policy reference

- Calculation
  - CUSMA duty-free (`cusma_auto`): treat origins in list as duty-free into US
  - CUSMA countries (`cusma_countries`): comma-separated ISO-2 list (default `CA,US`)
  - Min Split Savings / Postal Informal Threshold: repeated for clarity

- Fees
  - Postal Clearance Fee (USD) (`postal_clearance_fee_usd`): per-order, applied if any postal channel used
  - Commercial Brokerage (flat, USD) (`commercial_brokerage_flat_usd`): per-order, applied if any commercial channel used

- FX
  - Enable FX (`fx_enabled`): fetch rates from exchangerate.host
  - Provider (`fx_provider`): exchangerate.host (free)
  - Refresh Interval (`fx_refresh_hours`): transient TTL in hours

- Shipping Channels
  - Discovered shipping methods table: explicit mapping from method instance (`method:instance_id`) to `postal`/`commercial`, or leave blank to ignore.
  - Default channel for unmapped methods (`default_shipping_channel`): `auto`, `postal`, or `commercial`.
  - Keyword rules fallback (`shipping_channel_rules`): one per line — `keyword|postal` or `keyword|commercial`. Matches chosen rate label and method ID (case-insensitive).

- Display
  - Duty Details (`details_mode`): `none` or `inline` (collapsible)
  - Inline Duties (Cart) (`inline_cart_line`): shows a small per-line hint
  - Inline Duties (Checkout) (`inline_checkout_line`)
  - Debug Mode (`debug_mode`): shows a detailed logic flow under the details pane
  - Product Page Hint (`product_hint_enabled`): enable per-product estimate on product page
    - Position (`product_hint_position`): `under_price` (recommended) | `after_cart` | `in_meta`
    - Note (optional) (`product_hint_note`): e.g., “Fees added at checkout”

### 3.4 Tools

- Reindex Products: rebuilds normalized meta for products/variations so the “Products” counts in Profiles are accurate.
- FX Tools: clear and warm the FX cache for USD base.


## 4) How estimation works (Detailed)

Core logic resides in `includes/class-wrd-duty-engine.php`.

- Destination detection: `WC()->customer` shipping country, fallback to billing.
- Currency: `WRD_Duty_Engine::current_currency()` delegates to `WRD_FX::current_currency()` which respects multi-currency filters.
- Product data: `_customs_description` and `_country_of_origin`; variations fall back to parent if empty.
- Profile lookup: `WRD_DB::get_profile($description, $origin)` uses a normalized key and returns the latest active row. JSON columns (`us_duty_json`, `fta_flags`) are decoded.
- Channel decision:
  - Cart: `WRD_Duty_Engine::cart_channel_override()` inspects the chosen shipping method and `shipping_channel_map`/`shipping_channel_rules`. If none apply, it uses `default_shipping_channel` or a heuristic (`decide_channel()` based on origin; e.g., TW/CN → postal, CA → commercial, else commercial).
  - Product page estimate: uses `default_shipping_channel` if set; otherwise the heuristic.
- CUSMA handling: if destination is US and `cusma_auto` is enabled and the origin is in `cusma_countries`, or if the profile has `fta_flags` including `CUSMA` for CA/US/MX, the line duty rate is forced to 0%.
- Duty rate: `compute_rate_percent($us_duty_json, $channel)` sums the rate components for that channel. The implementation treats values as fractional or percentage; ensure your `us_duty_json` aligns with your data (rates map values should represent component fractions, e.g., 0.035 for 3.5%).
- Line duty amount (USD): `rate_pct/100 × line_value_usd`.
- Order-level fees: if any `commercial` channel lines → add `commercial_brokerage_flat_usd`. If any `postal` channel lines → add `postal_clearance_fee_usd`.
- Cart estimate result: `WRD_Duty_Engine::estimate_cart_duties()` returns
  - `lines`: `[ { product_id, key, desc, origin, channel, rate_pct, value_usd, duty_usd, cusma, debug } ]`
  - `total_usd` (duties), `fees_usd`, `fees`, `composition`, `scenario`, `missing_profiles`.

Order snapshot: `includes/class-wrd-admin.php`

- On `woocommerce_checkout_create_order`, the plugin stores `_wrd_duty_snapshot` (JSON) on the order containing `estimate_cart_duties()` + `currency` + `timestamp`.


## 5) Frontend output controls

`includes/class-wrd-frontend.php`

- Details pane around cart/checkout totals is rendered by `render_details()` and toggled by a small inline jQuery script registered via `enqueue_assets()`.
- Fee label formatting hook: `filter_fee_amount_html()` extends the fee HTML to add the “Details” toggle link next to the amount.
- Inline per-line hints on the cart/checkout order table names are added by `filter_cart_item_name()` if enabled in settings.
- Product page hint is rendered by `render_product_hint()`
  - Placement controlled via product page hooks:
    - Under price: `woocommerce_single_product_summary` (priority 25)
    - Below Add to cart: `woocommerce_after_add_to_cart_button`
    - In product meta: `woocommerce_product_meta_end`
  - Only shows when US-only destination is satisfied (if “US Only” setting is enabled) and a profile exists.


## 6) Developer guide (APIs, hooks, shortcodes)

### 6.1 Template tags and shortcode

Defined in `includes/template-tags.php`.

- `wrd_us_duty_estimate($product = null, array $args = []) : ?array`
  - Args: `{ qty = 1, respect_us_only = true }`
  - Returns: `{ rate_pct, duty_usd, duty_store, channel, cusma, origin, dest }` or `null`.

- `wrd_us_duty_rate($product = null, array $args = []) : ?float`
  - Convenience accessor for `rate_pct` post-CUSMA.

- `wrd_us_duty_hint($product = null, array $args = []) : string`
  - Args: `{ qty=1, respect_us_only=true, show_rate=true, inline_style=true, class='', show_zero=false, zero_text='', note='' }`
  - Returns an HTML snippet similar to the built-in product hint.

- `wrd_the_us_duty_hint($product = null, array $args = []) : void`
  - Echoes the hint HTML.

- Shortcode: `[wrd_duty_hint ...]`
  - Attributes: `product_id, qty, respect_us_only, show_rate, inline_style, class, show_zero, zero_text, note`.

Filters for customization:

- `wrd_us_duty_estimate_args` — Adjust the estimate args before calculation.
- `wrd_us_duty_estimate_result` — Modify the estimate array before returning.
- `wrd_us_duty_hint_args` — Adjust hint rendering args.
- `wrd_us_duty_hint_html` — Replace or wrap the final hint HTML.

Examples

Render a compact hint in a theme template:
```php
if (function_exists('wrd_the_us_duty_hint')) {
    wrd_the_us_duty_hint(null, [
        'inline_style' => false,
        'class' => 'my-duty-hint',
        'show_rate' => true,
        'respect_us_only' => true,
        'note' => 'Fees added at checkout',
    ]);
}
```

Just the percentage rate:
```php
$rate = function_exists('wrd_us_duty_rate') ? wrd_us_duty_rate(null, ['respect_us_only' => true]) : null;
if ($rate !== null) {
    echo '<span class="duty-rate">' . esc_html(number_format_i18n($rate, 2)) . '%</span>';
}
```

Shortcode usage in content/builders:
```text
[wrd_duty_hint]
[wrd_duty_hint product_id="123" class="my-hint" inline_style="no" show_rate="yes" note="Fees added at checkout"]
```

### 6.2 Public engine methods

From `includes/class-wrd-duty-engine.php`:

- `WRD_Duty_Engine::estimate_cart_duties() : array`
- `WRD_Duty_Engine::estimate_for_product($product, int $qty=1) : ?array`
- `WRD_Duty_Engine::current_currency() : string`
- `WRD_Duty_Engine::to_usd(float $amount, string $currency=null) : float`

Channel decision helpers:

- `WRD_Duty_Engine::cart_channel_override() : ?array` — resolves `channel` and `source` (`map_exact`, `map_family`, `keyword`, `default`) based on chosen shipping rate.
- `WRD_Duty_Engine::decide_channel(string $origin_cc) : string` — heuristic fallback (TW/CN → postal; CA → commercial; else commercial).

### 6.3 FX override hook

- `wrd_duty_fx_rate` filter: return a numeric rate to override the FX conversion for a given currency pair.

### 6.4 Product and order meta keys

- Product meta:
  - `_customs_description` (string)
  - `_country_of_origin` (ISO-2)
  - Normalized keys maintained for reporting:
    - `_wrd_desc_norm` (lowercased/trimmed description)
    - `_wrd_origin_cc` (uppercased origin)

- Order meta:
  - `_wrd_duty_snapshot`: JSON snapshot saved at checkout time with estimate totals and lines.

### 6.5 Database table

- Table name: `{prefix}wrd_customs_profiles`
- Migration: `migrations/001_create_customs_profiles.sql` (the plugin also runs a `dbDelta` schema installer in `WRD_DB::install_tables()`).
- Accessor: `WRD_DB::get_profile($descriptionRaw, $countryCode)`.

### 6.6 Admin AJAX & assets

- AJAX action: `wp_ajax_wrd_search_profiles` (autocomplete for Quick/Bulk Edit). Enqueues `assets/admin-quick-bulk.js` on Products list screens.

### 6.7 i18n / misc

- Text domain: `woocommerce-us-duties` (loaded on `init`).
- HPOS compatibility is declared in `wc-us-duty.php`.


## 7) Display and styling

Default hint container uses class `.wrd-product-duty-hint`. If you set `'inline_style' => false`, you can style via theme CSS:

```css
.wrd-product-duty-hint {
  margin: 8px 0;
  padding: 8px;
  border: 1px solid #e2e6ea;
  border-radius: 4px;
  background: #f8f9fa;
}
.wrd-product-duty-hint .wrd-hint-rate,
.wrd-product-duty-hint .wrd-hint-note {
  color: #666;
}
```

The details table uses `.wrd-duty-details`, `.wrd-duty-table`, and `.wrd-summary-table`. A small `CUSMA` badge is rendered for eligible lines.


## 8) Troubleshooting & FAQ

- No duties showing:
  - Ensure the product has both `_customs_description` and `_country_of_origin` set (variation or parent). The profile must exist for `description|origin` and be active for the current date.
  - If Settings → General → US Only is enabled, make sure the customer destination is US.
- Wrong channel/fees:
  - Confirm your chosen shipping method matches a mapping or keyword rule; otherwise the `default_shipping_channel` applies.
  - Product page hints use `default_shipping_channel` or origin heuristic (not cart override) by design.
- CUSMA not applied:
  - Check `cusma_auto` and the `cusma_countries` list. Also verify the profile `fta_flags` includes `CUSMA` when appropriate.
- Multi-currency amounts look off:
  - The engine calculates in USD but converts to the store currency via `WRD_FX`. Ensure FX is enabled and the API is reachable, or override via the `wrd_duty_fx_rate` filter.
- Missing profile at checkout:
  - If “Missing Profile Behavior” is set to block, the plugin will add an error notice and prevent checkout. Switch to fallback to estimate 0 or complete your profiles.


## 9) Changelog and seeding

- See `readme.txt` for the public changelog.
- See `docs/seed.md` for details on seeding customs profiles from Zonos JSON.


---

If you need sample CSVs, a demo config, or a Gutenberg block for the duty hint, open an issue or request and we’ll include them in a future release.
