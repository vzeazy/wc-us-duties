<?php
// Simple WP-CLI eval-file script to restock "classified" products.
// Usage examples:
//   wp eval-file wp-content/plugins/wc-us-duty/scripts/restock_classified_products_cli.php --dry-run --qty=25 --category_in="hats,accessories" --per_page=200
//   wp eval-file wp-content/plugins/wc-us-duty/scripts/restock_classified_products_cli.php --qty=50 --category_not_in=clearance --status=publish
//
// Classifed products are defined as having a non-empty customs description and country of origin
// either on the product itself, or (for variations) on their parent product.
//
// Flags supported (assoc args via $assoc_args):
//   --dry-run                Do not write changes; just print actions
//   --qty=INT                Target stock quantity to set when below that number (default: 20)
//   --status=publish|any     Product post status filter (default: publish)
//   --category_in=csv        Comma-separated category slugs or IDs to include
//   --category_not_in=csv    Comma-separated category slugs or IDs to exclude
//   --per_page=INT           Batch size per page (default: 200)
//   --page=INT               Start page (default: 1)
//   --max=INT                Max number of products (parents) to process before stopping (default: unlimited)
//   --include-variations     If present, also process variations under variable parents (default: true)
//
if ( ! defined('WP_CLI') || ! WP_CLI ) {
    echo "This script must be run via WP-CLI.\n";
    return; // Do not exit() to keep eval-file friendly
}

if ( ! class_exists('WooCommerce') ) {
    WP_CLI::error( 'WooCommerce must be active.' );
    return;
}

// WP-CLI passes $args and $assoc_args into eval-file scope
$assoc = isset($GLOBALS['assoc_args']) && is_array($GLOBALS['assoc_args']) ? $GLOBALS['assoc_args'] : [];

$dry_run   = isset($assoc['dry-run']);
$qty       = isset($assoc['qty']) ? max(0, (int)$assoc['qty']) : 20;
$status    = isset($assoc['status']) ? (string)$assoc['status'] : 'publish';
$per_page  = isset($assoc['per_page']) ? max(1, (int)$assoc['per_page']) : 200;
$page      = isset($assoc['page']) ? max(1, (int)$assoc['page']) : 1;
$max_total = isset($assoc['max']) ? max(1, (int)$assoc['max']) : 0; // 0 = unlimited
$with_vars = array_key_exists('include-variations', $assoc) ? (bool)$assoc['include-variations'] : true;

function wrd_parse_csv_list($v) {
    if ($v === null || $v === '') { return []; }
    $parts = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string)$v)));
    return array_values(array_unique($parts));
}

$cat_in  = wrd_parse_csv_list($assoc['category_in'] ?? '');
$cat_out = wrd_parse_csv_list($assoc['category_not_in'] ?? '');

// Build tax_query for product_cat supporting both slugs and IDs
function wrd_build_cat_tax_query(array $slugs_or_ids, string $op = 'IN'): array {
    if (!$slugs_or_ids) { return []; }
    $ids = []; $slugs = [];
    foreach ($slugs_or_ids as $token) {
        if (ctype_digit($token)) { $ids[] = (int)$token; }
        else { $slugs[] = $token; }
    }
    $queries = [];
    if ($ids) {
        $queries[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $ids,
            'operator' => $op,
        ];
    }
    if ($slugs) {
        $queries[] = [
            'taxonomy' => 'product_cat',
            'field'    => 'slug',
            'terms'    => $slugs,
            'operator' => $op,
        ];
    }
    if (count($queries) === 1) { return $queries; }
    if ($op === 'IN') {
        return [ array_merge(['relation' => 'OR'], $queries) ];
    }
    // For NOT IN, multiple conditions should be ANDed
    return array_merge(['relation' => 'AND'], $queries);
}

$tax_query = [];
$inc = wrd_build_cat_tax_query($cat_in, 'IN');
$exc = wrd_build_cat_tax_query($cat_out, 'NOT IN');
if ($inc) { $tax_query = array_merge($tax_query, $inc); }
if ($exc) {
    if ($tax_query) {
        $tax_query = array_merge(['relation' => 'AND'], $tax_query, is_array($exc) && isset($exc['relation']) ? $exc : $exc);
    } else {
        $tax_query = $exc;
    }
}

function wrd_resolve_classification($product) {
    if (!($product instanceof WC_Product)) { return [false, '', '']; }
    $desc = (string) $product->get_meta('_customs_description', true);
    $origin = (string) $product->get_meta('_country_of_origin', true);
    if ($origin === '' && $product->is_type('variation')) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) {
            $origin = $origin ?: (string) $parent->get_meta('_country_of_origin', true);
            $desc   = $desc   ?: (string) $parent->get_meta('_customs_description', true);
        }
    }
    $desc = trim($desc);
    $origin = strtoupper(trim($origin));
    $ok = ($desc !== '' && $origin !== '');
    return [$ok, $desc, $origin];
}

function wrd_should_update_stock($product, $target_qty) {
    if (!($product instanceof WC_Product)) { return false; }
    $current = (float) $product->get_stock_quantity();
    // If manage stock disabled, consider as needing update
    if (! $product->get_manage_stock()) { return true; }
    if ($current < (float)$target_qty) { return true; }
    return false;
}

function wrd_update_stock($product, $target_qty, $dry_run = true) {
    if (!($product instanceof WC_Product)) { return false; }
    $id = $product->get_id();
    $before_qty = (float) $product->get_stock_quantity();
    $before_ms  = (bool) $product->get_manage_stock();

    if ($dry_run) {
        WP_CLI::log(sprintf('DRY: would set stock for #%d (%s) from %s%s to %d',
            $id,
            $product->get_name(),
            $before_ms ? '' : 'no-manage/ ',
            is_numeric($before_qty) ? (string)$before_qty : 'na',
            (int)$target_qty
        ));
        return true;
    }

    // Ensure manage stock on, set qty, and status accordingly
    $product->set_manage_stock(true);
    $product->set_stock_quantity((int)$target_qty);
    $product->set_stock_status($target_qty > 0 ? 'instock' : 'outofstock');
    $product->save();

    WP_CLI::log(sprintf('OK: set stock for #%d (%s) from %s%s to %d',
        $id,
        $product->get_name(),
        $before_ms ? '' : 'no-manage/ ',
        is_numeric($before_qty) ? (string)$before_qty : 'na',
        (int)$target_qty
    ));
    return true;
}

$processed = 0; $updated = 0; $skipped = 0; $errors = 0; $parents_synced = [];

$bar_total = 0; // unknown upfront
$progress = null;

WP_CLI::log(sprintf('Starting restock: qty=%d, status=%s, per_page=%d, page=%d, dry_run=%s, include_variations=%s',
    $qty, $status, $per_page, $page, $dry_run ? 'yes':'no', $with_vars ? 'yes':'no'
));
if ($cat_in)  { WP_CLI::log('Filter category_in: ' . implode(', ', $cat_in)); }
if ($cat_out) { WP_CLI::log('Filter category_not_in: ' . implode(', ', $cat_out)); }

wp_suspend_cache_invalidation(true);
try {
    while (true) {
        $args = [
            'status'   => $status === 'any' ? array_keys(get_post_stati()) : $status,
            'type'     => ['simple','variable'], // parents only; we will iterate variations separately
            'paginate' => true,
            'limit'    => $per_page,
            'page'     => $page,
        ];
        if ($tax_query) { $args['tax_query'] = $tax_query; }

        $q = wc_get_products($args);
        $items = $q->products ?? $q; // Woo returns WC_Product[] in newer versions; safeguard
        if (empty($items)) { break; }

        foreach ($items as $product) {
            if (! ($product instanceof WC_Product)) { continue; }
            $processed++;
            if ($max_total && $processed > $max_total) { break 2; }

            // Evaluate parent itself if simple; for variable, just proceed to children
            if ($product->is_type('simple')) {
                [$ok, $desc, $origin] = wrd_resolve_classification($product);
                if (! $ok) { $skipped++; continue; }
                if (wrd_should_update_stock($product, $qty)) {
                    if (wrd_update_stock($product, $qty, $dry_run)) { $updated++; }
                } else {
                    $skipped++;
                }
            }

            if ($product->is_type('variable') && $with_vars) {
                $children = $product->get_children();
                $any_changed = false;
                foreach ($children as $vid) {
                    $v = wc_get_product($vid);
                    if (! $v) { continue; }
                    [$ok, $desc, $origin] = wrd_resolve_classification($v);
                    if (! $ok) { $skipped++; continue; }
                    if (wrd_should_update_stock($v, $qty)) {
                        if (wrd_update_stock($v, $qty, $dry_run)) { $updated++; $any_changed = true; }
                    } else {
                        $skipped++;
                    }
                }
                if ($any_changed && ! $dry_run) {
                    // Sync parent stock/status from children
                    if (! isset($parents_synced[$product->get_id()])) {
                        WC_Product_Variable::sync($product->get_id());
                        $parents_synced[$product->get_id()] = true;
                    }
                }
            }
        }

        $page++;
    }
} catch (Throwable $e) {
    $errors++;
    WP_CLI::warning('Error: ' . $e->getMessage());
} finally {
    wp_suspend_cache_invalidation(false);
}

WP_CLI::log('--- Summary ---');
WP_CLI::log(sprintf('Processed: %d', $processed));
WP_CLI::log(sprintf('Updated:   %d', $updated));
WP_CLI::log(sprintf('Skipped:   %d', $skipped));
WP_CLI::log(sprintf('Errors:    %d', $errors));

if ($dry_run) {
    WP_CLI::success('Dry run complete. Re-run without --dry-run to apply changes.');
} else {
    WP_CLI::success('Restock complete.');
}
