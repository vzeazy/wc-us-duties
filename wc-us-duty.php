<?php
/**
 * Plugin Name: WooCommerce US Duties & Customs
 * Description: Estimate US import duties/fees at checkout and route shipments using global customs profiles keyed by description|origin. HPOS compatible. Includes import/export tools.
 * Author: Webme Media Group
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 * Requires at least: 6.1
 * Tested up to: 6.6
 * WC requires at least: 7.0
 * WC tested up to: 9.2
 */

if (!defined('ABSPATH')) { exit; }

define('WRD_US_DUTY_VERSION', '0.1.0');
define('WRD_US_DUTY_FILE', __FILE__);
define('WRD_US_DUTY_DIR', plugin_dir_path(__FILE__));
define('WRD_US_DUTY_URL', plugin_dir_url(__FILE__));

// Autoload simple includes
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-db.php';
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-fx.php';
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-duty-engine.php';
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-admin.php';
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-settings.php';
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-frontend.php';
require_once WRD_US_DUTY_DIR . 'includes/class-wrd-us-duty-plugin.php';

register_activation_hook(__FILE__, ['WRD_US_Duty_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WRD_US_Duty_Plugin', 'deactivate']);

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>WooCommerce US Duties & Customs requires WooCommerce to be active.</p></div>';
        });
        return;
    }
    WRD_US_Duty_Plugin::instance()->init();
});

// HPOS compatibility flag
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Nice admin shortcuts on the Plugins list row
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (!current_user_can('manage_woocommerce')) { return $links; }
    $settings = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=wrd-customs&tab=settings')), esc_html__('Settings', 'wrd-us-duty'));
    $profiles = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=wrd-customs&tab=profiles')), esc_html__('Customs & Duties', 'wrd-us-duty'));
    $import = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=wrd-customs&tab=import')), esc_html__('Import/Export', 'wrd-us-duty'));
    $tools = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=wrd-customs&tab=tools')), esc_html__('Tools', 'wrd-us-duty'));
    array_unshift($links, $settings, $profiles, $import, $tools);
    return $links;
});

add_filter('plugin_row_meta', function ($links, $file) {
    if ($file !== plugin_basename(__FILE__)) { return $links; }
    if (!current_user_can('manage_woocommerce')) { return $links; }
    $export = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=wrd-customs&tab=import&action=export')), esc_html__('Export Profiles CSV', 'wrd-us-duty'));
    $reindex = sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=wrd-customs&tab=tools')), esc_html__('Reindex Products', 'wrd-us-duty'));
    $docs = '<a href="https://" target="_blank" rel="noopener">' . esc_html__('Docs', 'wrd-us-duty') . '</a>';
    // We don’t have a hosted docs URL; keep placeholder or remove if undesired
    $links[] = $export;
    $links[] = $reindex;
    return $links;
}, 10, 2);
