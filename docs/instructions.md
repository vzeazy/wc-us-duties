# WooCommerce US Duties & Customs Plugin - User Instructions

## Basics

### Installation
1. Download the plugin zip file.
2. In your WordPress admin, go to **Plugins > Add New > Upload Plugin**.
3. Upload the zip and activate it.
4. Ensure WooCommerce is installed and active (required).

### Quick Setup
1. Go to **WooCommerce > Customs & Duties** in the admin menu.
2. Import customs profiles via CSV (see Detailed section).
3. Configure settings under **Routing & Fees** tab.
4. For each product, add:
   - **Customs Description**: Commercial description (e.g., "Vulcanized rubber suction pad").
   - **Country of Origin**: ISO-2 code (e.g., CA, CN).

### How It Works
- The plugin estimates US import duties based on product descriptions and origins.
- Duties are added as a fee at checkout for US destinations.
- Supports CUSMA (USMCA) for duty-free treatment on eligible goods from CA/US/MX.
- Handles postal vs commercial channels automatically or via settings.
- Uses global customs profiles for HS codes, duty rates, and FTA flags.

### Basic Usage
- **For Store Owners**: Set up products with customs data, configure fees, and let the plugin handle duty calculations.
- **For Customers**: Duties appear as a line item on cart/checkout if shipping to US.

## Detailed

### Product Management
#### Adding Customs Data to Products
1. Edit a product (simple or variable).
2. In the **Shipping** tab, find the "Customs" section.
3. Enter:
   - **Customs Description**: Text description for lookup.
   - **Country of Origin**: ISO-2 code.
4. For variations: Enter on each variation; falls back to parent if empty.
5. Save.

#### Bulk Editing
- Use WooCommerce's bulk edit or the plugin's quick edit features.
- In the products list, check products and use "Quick Edit" or "Bulk Edit".
- Update customs fields in bulk.
- Use the JavaScript asset `admin-quick-bulk.js` for enhanced bulk editing.

#### Customs Profiles
- Profiles store duty rates, HS codes, FTA flags.
- Import via CSV in **Customs & Duties > Import/Export**.
- CSV format:
  ```
  description,country_code,hs_code,fta_flags,us_duty_json,effective_from,effective_to,notes
  "Vulcanized rubber suction pad","CN","5607493000","[]","{""postal"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":3.5,""section_301"":25.0}},""commercial"":{""de_minimis_threshold_usd"":800,""rates"":{""base_mfn"":3.5,""section_301"":25.0}}}","2025-08-01",,"CN 301 applies"
  ```
- Duty JSON: Rates by channel (postal/commercial), e.g., base MFN, Section 301.
- Admin grid: Searchable table with edit, bulk delete, and link to impacted products.

#### Impacted Products
- For each profile, see count of products using it.
- Drill down to list products matching the description and country.
- Table shows title, type, SKU, origin, description.

### Settings Configuration
#### General
- **Checkout Mode**: Charge duties as fee (DDP) or show info only (DAP).
- **Fee Label**: Customize the fee name (e.g., "Estimated US Duties").
- **US Only**: Estimate for US destinations only.
- **Missing Profile Behavior**: Fallback (0 duty) or block checkout.
- **Details Mode**: None, inline summary on cart/checkout.
- **Inline Duties**: Per-line hints on cart/checkout.
- **Debug Mode**: Show detailed logic in details area (admin/testing).
- **Product Page Hint**: Show estimated duties on product pages (under price, after cart, in meta).

#### Calculation
- **CUSMA Duty-Free**: Enable for origins in list (CA, US, MX).
- **Min Split Savings**: Threshold for optimizing splits (advanced).
- **Postal Informal Threshold**: USD limit for informal postal entry.

#### Fees
- **Postal Clearance Fee**: Flat fee for postal entries.
- **Commercial Brokerage**: Flat fee for commercial entries.

#### FX (Currency)
- Enable fetching rates from exchangerate.host.
- Refresh interval: Hours between updates.

#### Shipping Channels
- Map shipping methods to postal/commercial.
- Keyword rules: e.g., "USPS|postal".
- Default channel: Auto (heuristic) or fixed.
- Channel override based on chosen shipping method.

### Import/Export
- **Export Profiles**: Download CSV of all profiles.
- **Import Profiles**: Upload CSV to add/update profiles.
- **Reindex Products**: Update normalized meta for fast lookups.

### Tools
- **Reindex Products**: Run after bulk changes to update search indexes.
- **Scripts**: Use `scripts/zonos_json_to_customs_csv.php` or `scripts/zonos_json_to_sql.php` to convert Zonos data.
- **Seed Data**: Load from `zonos/zonos_classification_100.json` using scripts.

### Duty Calculation Logic
- Normalizes descriptions for lookup.
- Decides channel: postal for TW/CN, commercial for CA, or based on settings.
- Computes rates from profile JSON.
- Handles CUSMA: 0% if eligible.
- Adds fees per channel.
- Converts to store currency using FX.

## Dev Main

### Hooks and Filters
#### Actions
- `woocommerce_cart_calculate_fees`: Add duties fee.
- `woocommerce_checkout_create_order`: Snapshot duties to order.
- `woocommerce_product_options_shipping`: Add product fields.
- `woocommerce_admin_process_product_object`: Save product fields.
- `save_post_product`: Update normalized meta.

#### Filters
- `plugin_action_links`: Add settings links.
- `manage_edit-product_columns`: Add customs column.
- `woocommerce_cart_totals_fee_html`: Modify fee display.
- `wrd_duty_fx_rate`: Override FX rates.

### Classes and Methods
#### Main Classes
- `WRD_US_Duty_Plugin`: Singleton, initializes components.
- `WRD_Duty_Engine`: Core calculation logic.
  - `estimate_cart_duties()`: Estimate for cart.
  - `estimate_for_product($product, $qty)`: Estimate for single product.
  - `cart_channel_override()`: Determine channel from shipping method.
- `WRD_DB`: Database operations.
  - `get_profile($desc, $origin)`: Lookup profile.
  - `normalize_description($s)`: Normalize for search.
- `WRD_FX`: Currency conversion.
  - `convert($amount, $from, $to)`: Convert amount.
  - `get_rates_table($base)`: Fetch cached rates.
- `WRD_Admin`: Admin UI and product fields.
  - `enqueue_admin_assets()`: Load JS/CSS.
  - `ajax_search_profiles()`: AJAX for profile search.
- `WRD_Settings`: Settings page.
- `WRD_Frontend`: Customer-facing display.
  - `render_details()`: Show duty breakdown.
  - `render_product_hint()`: Hint on product page.
- `WRD_Profiles_Table`: WP_List_Table for profiles.
- `WRD_Impacted_Products_Table`: Table for affected products.

#### Database Tables
- `wp_wrd_customs_profiles`: Stores profiles.
  - Columns: id, description_raw, description_normalized, country_code, hs_code, us_duty_json, fta_flags, effective_from, effective_to, notes.
- Normalized meta: `_wrd_desc_norm`, `_wrd_origin_cc` for fast queries.

### AJAX Endpoints
- `wrd_search_profiles`: Search profiles for autocomplete.

### Templates and Assets
- Assets: `admin-quick-bulk.js` for bulk edits.
- Templates: Uses WooCommerce hooks for display.

### Template Tags and Shortcodes
#### Template Tags
- `wrd_us_duty_estimate($product, $args)`: Get duty estimate array for a product.
- `wrd_us_duty_rate($product, $args)`: Get duty rate percentage.
- `wrd_us_duty_hint($product, $args)`: Get HTML hint for product page.
- `wrd_the_us_duty_hint($product, $args)`: Echo the hint HTML.

#### Shortcode
- `[wrd_duty_hint product_id="123" qty="1" show_rate="yes" respect_us_only="yes" show_zero="no" class="" note=""]`
- Attributes:
  - `product_id`: Product ID (optional, uses global if empty).
  - `qty`: Quantity (default 1).
  - `show_rate`: Show rate % (yes/no).
  - `respect_us_only`: Respect US only setting (yes/no).
  - `show_zero`: Show when duty is zero (yes/no).
  - `class`: Extra CSS class.
  - `note`: Custom note text.
  - `zero_text`: Text when zero and show_zero=yes.
  - `inline_style`: Include inline styles (yes/no).

Example: `[wrd_duty_hint product_id="123" qty="2" show_rate="yes"]`

### Customization
- Override FX rates: `add_filter('wrd_duty_fx_rate', function($rate, $from, $to) { return $custom_rate; }, 10, 3);`
- Custom duty logic: Hook into `woocommerce_cart_calculate_fees`.
- Modify hint HTML: `add_filter('wrd_us_duty_hint_html', function($html, $est, $args, $product) { return $custom_html; }, 10, 4);`
- Adjust estimate args: `add_filter('wrd_us_duty_estimate_args', function($args, $product) { return $args; }, 10, 2);`

### API Integration
- FX API: exchangerate.host (free, no key).
- Extendable for other providers.

### Debugging
- Enable debug mode in settings to see calculation details.
- Check order meta: `_wrd_duties_snapshot` for saved estimates.
- Use `WRD_Duty_Engine::estimate_cart_duties()` for testing.

### Version and Compatibility
- Version: 0.1.0
- Requires: WooCommerce 7.0+, WordPress 6.1+
- HPOS Compatible.
- WPML Multi-currency ready.

### Scripts and Data
- `scripts/zonos_json_to_customs_csv.php`: Convert Zonos JSON to CSV.
- `scripts/zonos_json_to_sql.php`: Convert to SQL inserts.
- `zonos/zonos_classification_100.json`: Sample data.
- `migrations/001_create_customs_profiles.sql`: DB setup.

For support, check the plugin's GitHub or contact the developer.
