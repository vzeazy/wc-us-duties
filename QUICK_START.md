# Quick Start: Using the New Duty System

## Before (Old Way) 😓
1. Create 1000 products
2. Manually enter HS code for each product
3. Manually enter country of origin for each product
4. Hope you didn't make typos
5. Update 1000 products when something changes

## After (New Way) 🎉

### Option 1: Category Inheritance (Recommended)
1. **Set it once on the category**:
   - Go to Products > Categories
   - Edit "Clothing" category
   - Set: Default HS Code = "6205.20", Origin = "CN"
   - Save

2. **All products inherit automatically**:
   - Create 1000 clothing products
   - Leave HS code and Origin empty
   - They automatically use the category defaults
   - Done! ✅

3. **Override when needed**:
   - For special products, just set their own HS code
   - Product-level always wins over category-level

### Option 2: Duty Manager Dashboard
1. **Navigate**: WooCommerce > Duty Manager
2. **Filter**: Check "Show only products missing duty info"
3. **Edit inline**: Click "Edit" on any row, type values, click "Save"
4. **Visual feedback**: See at a glance what's complete, inherited, or missing

## Status Indicators

| Badge | Meaning | What to do |
|-------|---------|------------|
| 🟢 **Complete** | Product has its own HS code + origin | Nothing! You're good |
| 🔵 **Inherited from Category:X** | Using category defaults | Optional: Set product-specific if needed |
| 🟡 **Partial** | Has HS code OR origin but not both | Add the missing field |
| 🔴 **Missing** | No duty info at all | Set on product OR category |

## Real-World Example

**Scenario**: You sell 500 cotton shirts from China

**Old way**: 
- Edit 500 products individually
- 500 × 2 fields = 1000 manual entries
- Time: ~2 hours

**New way**:
- Edit "Shirts" category once (2 fields)
- Products inherit automatically
- Time: ~30 seconds

**Savings**: 99.6% less time! 🚀

## Pro Tips

1. **Use category inheritance for bulk items** (e.g., all t-shirts from China)
2. **Use product-level for exceptions** (e.g., one premium shirt from Italy)
3. **Use Duty Manager to audit** (filter by "missing" to find gaps)
4. **Rates stay centralized** - update once in Profiles, applies everywhere
