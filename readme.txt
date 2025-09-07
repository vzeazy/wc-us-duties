=== WooCommerce US Duties & Customs ===
Contributors: webmemediagroup
Tags: woocommerce, customs, duties, import duties, us, hs code, hts, cusma, ddp, dap
Requires at least: 6.1
Tested up to: 6.6
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Estimate US import duties and fees at checkout and manage customs profiles. HPOS-compatible. JSON/CSV importers, WPML-ready currency, and CUSMA handling.

== Description ==

WooCommerce US Duties & Customs adds a duty estimate at checkout and tools to manage customs profiles (HS codes and rate components) keyed by product description and origin. It supports CUSMA duty-free handling per line item, live FX via exchangerate.host, and WPML multi-currency compatibility. Admins can import data from Zonos JSON dumps and CSV, and bulk-assign customs fields to products.

Features:
- Checkout fee (DDP) or info-only (DAP)
- Line-item CUSMA duty-free for CA/US origins (configurable)
- Shipping channel mapping (postal vs commercial) from chosen rate
- JSON/CSV importers for profiles; CSV assigner for products
- Admin grid with search, pagination, edit, bulk delete, and impacted products drilldown
- WPML multi-currency compatibility; exchangerate.host FX cache
- HPOS compatible; order snapshot stored in meta

== Installation ==

Install and activate. Visit WooCommerce → Customs & Duties to configure settings, import customs profiles, and assign customs fields to products.

== Frequently Asked Questions ==

= Does this calculate brokerage or MPF? =

Basic fee settings are available; detailed brokerage/MPF tables will be added in future versions.

= Does it support split-ship optimization (Case B)? =

The MVP includes structure for this; a full optimizer is planned.

== Changelog ==

= 0.1.0 =
* Initial release.

