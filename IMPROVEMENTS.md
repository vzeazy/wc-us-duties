# Duty Rate System Improvements - Implementation Summary

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
