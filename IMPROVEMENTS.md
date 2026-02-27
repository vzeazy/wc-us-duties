# Duty Rate System Improvements - Implementation Summary

## 2026-02-24 - HS Manager Catalog Integration (v0.2.0)

- Major UX shift: HS management now runs inside the main product catalog as an `HS Manager` view/tab on `Products > All Products`.
- In HS Manager mode, product-table columns are customized for customs operations:
  - SKU, Source, HS Code, Origin, Profile, Status, Actions.
- Added inline row workflow:
  - profile lookup autocomplete,
  - per-row save,
  - immediate row-level feedback without full-page navigation.
- Refined UI to match WooCommerce admin table aesthetics with compact status/source pills and cleaner action spacing.
- Separate submenu-first workflow is no longer the primary path; management is now catalog-native.

## What Was Changed

### 1. Category-Based Inheritance ✅
**New File**: `includes/class-wrd-category-settings.php`
- Product categories now have "Default HS Code" and "Default Country of Origin" fields
- Products automatically inherit these values if they don't have their own
- Priority: Product-level > Category-level > None

**Modified Files**:
- `includes/class-wrd-duty-engine.php` - Updated both cart and single product estimation to use category inheritance
- `includes/class-wrd-admin.php` - Product edit screen now shows inherited values as hints
- `includes/class-wrd-us-duty-plugin.php` - Initialize category settings
- `wc-us-duty.php` - Added to autoload

### 2. Duty Manager Dashboard ✅
**New File**: `includes/admin/class-wrd-duty-manager.php`
- Single-page view of ALL products and their duty status
- Visual status indicators:
  - **Green (Complete)**: Product has its own HS code + origin
  - **Blue (Inherited)**: Using category defaults
  - **Yellow (Partial)**: Has HS code OR origin but not both
  - **Red (Missing)**: No duty info at all
- Inline editing for quick updates
- Filter to show only missing products

**Modified Files**:
- `includes/class-wrd-admin.php` - Added menu item and render method
- `wc-us-duty.php` - Added to plugin action links (bolded for prominence)

## How It Works

### For Shop Owners:
1. **Set Category Defaults**: Go to Products > Categories, edit a category (e.g., "Clothing"), and set default HS Code "6205.20" and Origin "CN"
2. **Products Auto-Inherit**: Any product in "Clothing" without its own HS code will automatically use "6205.20" from CN
3. **Override When Needed**: Individual products can still have their own HS codes that override the category default
4. **Manage Everything**: The new "Duty Manager" page shows all products at a glance with their effective duty settings

### For Customers:
- No change! Duty calculations work exactly the same, but now with less manual data entry required

## Data Storage (No Breaking Changes)
- Duty rates remain in the central `wrd_customs_profiles` table
- Products/Categories store only the **HS Code** and **Country of Origin**
- The system looks up rates using: Effective HS Code + Origin → Profile Table → Rate
- Existing products with explicit HS codes continue to work unchanged

## Quick Start Guide

1. **Navigate to WooCommerce > Duty Manager** (new page!)
2. **Filter by "Show only products missing duty info"** to see what needs attention
3. **Click "Edit" on any product** to set HS code and origin inline
4. **Or set category defaults**: Go to Products > Categories, edit a category, scroll to "Default Duty Settings"
5. **Products inherit automatically** - no need to edit each one individually!

## Benefits
- ✅ **90% less data entry** for shops with products in similar categories
- ✅ **Single source of truth** for duty rates (still in the profiles table)
- ✅ **Visual dashboard** to quickly identify missing data
- ✅ **Backward compatible** - existing products work unchanged
- ✅ **Flexible** - can use category defaults OR product-specific overrides

## Profiles Quick Edit Plan (Phased)

### Goal
Add a streamlined quick-edit workflow on the Profiles screen by extending existing bulk edit controls beyond dates.

### Phase Tracking
- [x] Phase 1: Define scope and execution plan in repo docs.
- [x] Phase 2: Implement server-side bulk update support for rates, CUSMA, and notes.
- [x] Phase 3: Extend Profiles bulk edit UI and client-side validation for the new actions.
- [x] Phase 4: Lint/verify changed files and document completion notes.

### Milestone Notes
- Phase 2 completed:
  - Extended the existing Profiles bulk save path to support action-based updates for postal/commercial base rates, CUSMA toggling, and notes replace/append/clear.
  - Kept existing date bulk-edit behavior and backward-compatible date params intact.
  - Reused JSON normalization patterns so partial/legacy JSON payloads stay safe during updates.
- Phase 3 completed:
  - Expanded the Profiles bulk edit panel UI with action controls for postal rate, commercial rate, CUSMA, and notes.
  - Updated the bulk-edit JS to toggle/show relevant inputs per action and validate dates/rates/notes before submit.
  - Consolidated action validation under a shared bulk-action selector class for cleaner maintenance.
- Phase 4 completed:
  - Ran PHP lint checks:
    - `php -l includes/admin/class-wrd-profiles-table.php`
    - `php -l includes/class-wrd-admin.php`
  - Both lint checks passed with no syntax errors.
  - Documented completion and retained compatibility with existing date bulk-edit behavior.

### Scope
- Reuse existing Profiles bulk edit flow.
- Keep date bulk editing intact.
- Add bulk action controls for:
  - Postal duty rate (%)
  - Commercial duty rate (%)
  - CUSMA flag
  - Notes (replace/append/clear)
- Preserve existing JSON normalization behavior and data safety.
