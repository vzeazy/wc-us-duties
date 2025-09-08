<?php
/**
 * Script to re-stock products that have been correctly classified with customs data.
 * Run with: wp eval-file restock_classified_products.php
 *
 * Options:
 * - Set $dry_run = true; for dry-run mode (no changes made).
 *
 * This script:
 * - Finds products/variations that are out of stock.
 * - Checks if they have _customs_description and _country_of_origin set.
 * - For non-CA origins (or non-CUSMA), verifies a customs profile exists.
 * - If profile found, sets stock status to 'instock' (or reports in dry-run).
 * - Logs changes to stdout.
 */

// Set to true for dry-run (no actual changes)
$dry_run = true;

require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Ensure WooCommerce is active
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    echo "Error: WooCommerce is not active.\n";
    exit(1);
}

// Include plugin files if needed (for WRD_DB)
require_once WP_PLUGIN_DIR . '/wc-us-duty/includes/class-wrd-db.php';

$cusma_countries = ['CA', 'US', 'MX']; // CUSMA eligible origins

// Get all out of stock products and variations
$args = [
    'post_type' => ['product', 'product_variation'],
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => '_stock_status',
            'value' => 'outofstock',
            'compare' => '='
        ]
    ],
    'posts_per_page' => -1,
    'fields' => 'ids'
];

$query = new WP_Query($args);
$product_ids = $query->posts;

echo "Found " . count($product_ids) . " out of stock products/variations.\n";
if ($dry_run) {
    echo "DRY-RUN MODE: No changes will be made.\n";
}

$restocked = 0;

foreach ($product_ids as $product_id) {
    $product = wc_get_product($product_id);
    if (!$product) {
        echo "Skipping invalid product ID: $product_id\n";
        continue;
    }

    // Get customs data
    $desc = $product->get_meta('_customs_description', true);
    $origin = $product->get_meta('_country_of_origin', true);

    // For variations, fallback to parent if empty
    if ($product->is_type('variation') && (empty($desc) || empty($origin))) {
        $parent = wc_get_product($product->get_parent_id());
        if ($parent) {
            $desc = $desc ?: $parent->get_meta('_customs_description', true);
            $origin = $origin ?: $parent->get_meta('_country_of_origin', true);
        }
    }

    if (empty($desc) || empty($origin)) {
        echo "Skipping product $product_id: missing customs data.\n";
        continue;
    }

    $origin_upper = strtoupper($origin);

    // Skip CA/US/MX if CUSMA is enabled (they might not need duties)
    $settings = get_option('wrd_us_duty_settings', []);
    $cusma_enabled = !empty($settings['cusma_auto']);
    if ($cusma_enabled && in_array($origin_upper, $cusma_countries, true)) {
        echo "Skipping product $product_id: CUSMA eligible origin ($origin).\n";
        continue;
    }

    // Check if profile exists
    $profile = WRD_DB::get_profile($desc, $origin_upper);
    if (!$profile) {
        echo "Skipping product $product_id: no customs profile found for '$desc' | '$origin'.\n";
        continue;
    }

    // Restock the product
    if ($dry_run) {
        echo "WOULD RESTOCK product $product_id: " . $product->get_name() . " (Origin: $origin)\n";
    } else {
        $product->set_stock_status('instock');
        $product->save();
        echo "Restocked product $product_id: " . $product->get_name() . " (Origin: $origin)\n";
    }
    $restocked++;
}

echo "\n" . ($dry_run ? "Would restock" : "Restocked") . " $restocked products.\n";
echo "Script completed.\n";
