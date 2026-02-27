<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Duty_Manager {
    public function render_page(): void {
        if (!current_user_can('manage_woocommerce')) { return; }

        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-duty-manager-table.php';

        $status = isset($_GET['wrd_status']) ? sanitize_key(wp_unslash($_GET['wrd_status'])) : 'all';
        $type = isset($_GET['wrd_type']) ? sanitize_key(wp_unslash($_GET['wrd_type'])) : 'all';

        $table = new WRD_Duty_Manager_Table($status, $type);
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('HS Manager', 'woocommerce-us-duties') . '</h1>';
        echo '<p class="description">' . esc_html__('Single-screen customs management for all products and variations. Update HS and origin inline, then save row-by-row.', 'woocommerce-us-duties') . '</p>';

        echo '<form method="get">';
        echo '<input type="hidden" name="post_type" value="product" />';
        echo '<input type="hidden" name="page" value="wrd-duty-manager" />';

        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';

        echo '<label class="screen-reader-text" for="wrd-status">' . esc_html__('Filter by status', 'woocommerce-us-duties') . '</label>';
        echo '<select name="wrd_status" id="wrd-status">';
        $status_options = [
            'all' => __('All statuses', 'woocommerce-us-duties'),
            'needs_hs' => __('Needs HS', 'woocommerce-us-duties'),
            'needs_origin' => __('Needs Origin', 'woocommerce-us-duties'),
            'missing_profile' => __('Missing Profile', 'woocommerce-us-duties'),
            'legacy' => __('Legacy', 'woocommerce-us-duties'),
            'ready' => __('Ready', 'woocommerce-us-duties'),
        ];
        foreach ($status_options as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($status, $key, false), esc_html($label));
        }
        echo '</select> ';

        echo '<label class="screen-reader-text" for="wrd-type">' . esc_html__('Filter by product type', 'woocommerce-us-duties') . '</label>';
        echo '<select name="wrd_type" id="wrd-type">';
        $type_options = [
            'all' => __('All types', 'woocommerce-us-duties'),
            'product' => __('Products', 'woocommerce-us-duties'),
            'variation' => __('Variations', 'woocommerce-us-duties'),
        ];
        foreach ($type_options as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($type, $key, false), esc_html($label));
        }
        echo '</select> ';

        submit_button(__('Filter', 'woocommerce-us-duties'), 'secondary', '', false);

        echo '</div>';
        $table->search_box(__('Search products', 'woocommerce-us-duties'), 'wrd-duty-manager-search');
        echo '<br class="clear" />';
        echo '</div>';

        $table->display();
        echo '</form>';
        echo '</div>';
    }
}
