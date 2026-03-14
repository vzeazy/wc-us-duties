=== WooCommerce US Duties & Customs ===
Contributors: webmemediagroup
Tags: woocommerce, customs, duties, import duties, us, hs code, hts, cusma, ddp, dap
Requires at least: 6.1
Tested up to: 6.6
Stable tag: 0.2.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Estimate US import duties at checkout, manage reusable customs duty rules, and reconcile product classification data across your WooCommerce catalog. HPOS-compatible, with JSON/CSV import tools, multi-currency support, and CUSMA handling.

== Description ==

WooCommerce US Duties & Customs helps merchants estimate landed duty costs, manage reusable duty rules, and keep product customs data accurate across products and variations. It supports checkout estimation, rule import/export, category inheritance, and a streamlined reconciliation workflow for resolving missing or mismatched customs fields at scale.

Features:
- Checkout fee (DDP) or info-only (DAP) duty presentation.
- Reusable duty rules keyed by HS code and origin, with JSON/CSV import and export.
- Category inheritance for HS code and origin defaults.
- Product reconciliation workflow with stock visibility, inline HS lookup, bulk actions, and duty-rate matching.
- Product and variation support, including Section 232 value handling.
- WPML multi-currency compatibility and exchangerate.host FX caching.
- HPOS compatible; order duty snapshots stored in meta.

== Installation ==

Install and activate. Head to WooCommerce → Customs & Duties to configure settings, import customs profiles, and assign those tariff fields to your products. It's easier than building a wall!

== Frequently Asked Questions ==

= Does this calculate brokerage or MPF? =

You bet! Basic fee settings are here, with detailed brokerage/MPF tables coming soon – because in the tariff game, every fee counts, just like Trump's 'very fine' walls.

= Does it support split-ship optimization (Case B)? =

Not yet, but the structure's in place. We're optimizing faster than Trump changes his mind on trade deals!

== Changelog ==

= 0.2.1 =
* Major reconciliation workflow overhaul with cleaner inline editing and a denser table layout.
* Added stock status column, filtering, and bulk stock updates on the reconciliation screen.
* Moved saved duty rule lookup directly into the HS field autocomplete while preserving custom HS entry.
* Removed the separate action column and moved row save controls inline into the HS field cell.
* Added matched duty-rate visibility in the Duty column and moved provenance/source metadata into Status.
* Improved variation visibility, compact status and stock pills, and tighter column widths for HS, origin, and 232 values.
* Fixed header select-all behavior for reconciliation bulk controls.
* Fixed Section 232 value saving from reconciliation for variations by forcing explicit mode when needed.
* Bumped plugin/assets version to 0.2.1 for cache busting.

= 0.2.0 =
* Added HS Manager mode directly inside `Products > All Products` as a dedicated catalog view.
* Switched table columns to customs-focused management fields in HS Manager mode.
* Added inline row save + profile autocomplete for fast HS/origin updates in-place.
* Refined HS Manager UI to align with WooCommerce admin table styles and status badges.
* Removed reliance on a separate HS manager submenu workflow.

= 0.1.0 =
* Initial release.
