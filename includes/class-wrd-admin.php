<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Admin {
    public function init(): void {
        // Product fields
        add_action('woocommerce_product_options_shipping', [$this, 'product_fields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_fields']);

        // Variations
        add_action('woocommerce_product_after_variable_attributes', [$this, 'variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_fields'], 10, 2);

        // Maintain normalized meta for fast lookups/counts
        add_action('save_post_product', [$this, 'update_normalized_meta_on_save'], 20, 3);
        add_action('save_post_product_variation', [$this, 'update_normalized_meta_on_save'], 20, 3);

        // Checkout fee + order snapshot
        add_action('woocommerce_cart_calculate_fees', [$this, 'add_estimated_duties_fee']);
        add_action('woocommerce_checkout_create_order', [$this, 'snapshot_duties_to_order'], 10, 2);

        // Order admin: display duty snapshot meta box
        add_action('add_meta_boxes', [$this, 'register_order_duty_meta_box']);

        // Admin menu
        add_action('admin_menu', [$this, 'admin_menu']);

        // Products list: customs status column
        add_filter('manage_edit-product_columns', [$this, 'add_product_customs_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_customs_column'], 10, 2);
        add_filter('post_row_actions', [$this, 'add_assign_profile_row_action'], 10, 2);

        // Quick Edit / Bulk Edit fields
        add_action('quick_edit_custom_box', [$this, 'quick_bulk_edit_box'], 10, 2);
        add_action('bulk_edit_custom_box', [$this, 'quick_bulk_edit_box'], 10, 2);
        // WooCommerce-specific edit panels (more reliable in bulk)
        add_action('woocommerce_product_quick_edit_start', [$this, 'quick_bulk_edit_panel']);
        add_action('woocommerce_product_bulk_edit_start', [$this, 'quick_bulk_edit_panel']);
        add_action('woocommerce_product_quick_edit_save', [$this, 'handle_quick_bulk_save']);
        add_action('woocommerce_product_bulk_edit_save', [$this, 'handle_quick_bulk_save']);

        // Admin assets and AJAX for profile search
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_wrd_search_profiles', [$this, 'ajax_search_profiles']);
        add_action('wp_ajax_wrd_reconcile_assign', [$this, 'ajax_reconcile_assign']);
        add_action('wp_ajax_wrd_reconcile_suggest', [$this, 'ajax_reconcile_suggest']);
        add_action('wp_ajax_wrd_quick_assign_profile', [$this, 'ajax_quick_assign_profile']);
        add_action('admin_footer', [$this, 'render_inline_assign_template']);

        // Redirect legacy admin page slugs
        add_action('admin_init', [$this, 'redirect_legacy_pages']);
    }

    public function product_fields(): void {
        global $post;
        $product = wc_get_product($post->ID);
        if (!$product) { return; }

        echo '<div class="options_group wrd-customs-fields">';

        echo '<p class="form-field">';
        echo '<label>' . esc_html__('Profile Lookup', 'woocommerce-us-duties') . '</label>';
        echo '<input type="text" class="wrd-profile-lookup short" placeholder="' . esc_attr__('Search profiles...', 'woocommerce-us-duties') . '" />';
        echo '<span class="description">' . esc_html__('Search by HS code, country, or description to auto-populate fields below.', 'woocommerce-us-duties') . '</span>';
        echo '</p>';

        // Get effective values with category fallback
        $effective = WRD_Category_Settings::get_effective_hs_code($product);
        $current_hs = $product->get_meta('_hs_code', true);
        $current_origin = $product->get_meta('_country_of_origin', true);
        
        // Build placeholder/description based on inheritance
        $hs_placeholder = '4016931000';
        $hs_desc = __('Harmonized System code for this product (primary identifier for duty lookup).', 'woocommerce-us-duties');
        if (!$current_hs && $effective['hs_code'] && $effective['source'] !== 'product') {
            $hs_placeholder = $effective['hs_code'];
            $hs_desc .= ' ' . sprintf(__('Will inherit "%s" from %s if left empty.', 'woocommerce-us-duties'), $effective['hs_code'], $effective['source']);
        }
        
        $origin_placeholder = 'CN';
        $origin_desc = __('ISO-2 country code, e.g., CA, CN, TW.', 'woocommerce-us-duties');
        if (!$current_origin && $effective['origin'] && $effective['source'] !== 'product') {
            $origin_placeholder = $effective['origin'];
            $origin_desc .= ' ' . sprintf(__('Will inherit "%s" from %s if left empty.', 'woocommerce-us-duties'), $effective['origin'], $effective['source']);
        }

        // HS Code field (primary identifier)
        woocommerce_wp_text_input([
            'id' => '_hs_code',
            'label' => __('HS Code', 'woocommerce-us-duties'),
            'desc_tip' => true,
            'description' => $hs_desc,
            'placeholder' => $hs_placeholder,
        ]);

        // Country of Origin
        woocommerce_wp_text_input([
            'id' => '_country_of_origin',
            'label' => __('Country of Origin (ISO-2)', 'woocommerce-us-duties'),
            'desc_tip' => true,
            'description' => $origin_desc,
            'placeholder' => $origin_placeholder,
            'maxlength' => 2,
        ]);

        echo '</div>';

        // Enqueue autocomplete script
        wp_enqueue_script('wrd-product-edit', WRD_US_DUTY_URL . 'assets/admin-product-edit.js', ['jquery', 'jquery-ui-autocomplete'], WRD_US_DUTY_VERSION, true);
        wp_localize_script('wrd-product-edit', 'WRDProduct', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wrd_search_profiles'),
        ]);
    }

    public function save_product_fields($product): void {
        $hs_code = isset($_POST['_hs_code']) ? trim(sanitize_text_field(wp_unslash($_POST['_hs_code']))) : '';
        $origin = isset($_POST['_country_of_origin']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['_country_of_origin']))) : '';

        $product->update_meta_data('_hs_code', $hs_code);
        $product->update_meta_data('_country_of_origin', $origin);
        $product->save();

        // Update normalized meta on product and inheriting variations
        $this->update_normalized_meta_for_product((int)$product->get_id());
    }

    public function variation_fields($loop, $variation_data, $variation): void {
        echo '<div class="wrd-variation-customs-fields">';

        // Profile lookup for variation
        echo '<p class="form-row form-row-full">';
        echo '<label>' . esc_html__('Profile Lookup', 'woocommerce-us-duties') . '</label>';
        echo '<input type="text" class="wrd-profile-lookup short" placeholder="' . esc_attr__('Search profiles...', 'woocommerce-us-duties') . '" />';
        echo '</p>';

        woocommerce_wp_text_input([
            'id' => "_hs_code[{$loop}]",
            'label' => __('HS Code', 'woocommerce-us-duties'),
            'value' => get_post_meta($variation->ID, '_hs_code', true),
            'wrapper_class' => 'form-row form-row-first',
            'placeholder' => __('Inherit from parent...', 'woocommerce-us-duties'),
        ]);

        woocommerce_wp_text_input([
            'id' => "_country_of_origin[{$loop}]",
            'label' => __('Country of Origin (ISO-2)', 'woocommerce-us-duties'),
            'value' => get_post_meta($variation->ID, '_country_of_origin', true),
            'maxlength' => 2,
            'wrapper_class' => 'form-row form-row-last',
            'placeholder' => __('Inherit from parent...', 'woocommerce-us-duties'),
        ]);

        echo '</div>';
    }

    public function save_variation_fields($variation_id, $i): void {
        if (isset($_POST['_hs_code'][$i])) {
            update_post_meta($variation_id, '_hs_code', trim(sanitize_text_field(wp_unslash($_POST['_hs_code'][$i]))));
        }
        if (isset($_POST['_country_of_origin'][$i])) {
            update_post_meta($variation_id, '_country_of_origin', strtoupper(sanitize_text_field(wp_unslash($_POST['_country_of_origin'][$i]))));
        }
        // Maintain normalized meta for this variation
        $this->update_normalized_meta_for_variation((int)$variation_id);
    }

    public function add_estimated_duties_fee(): void {
        if (is_admin() && !defined('DOING_AJAX')) { return; }
        if (!WC()->customer) { return; }
        $settings = get_option(WRD_Settings::OPTION, []);
        $us_only = !empty($settings['us_only']);
        $ship_cc = WC()->customer->get_shipping_country();
        if ($us_only && $ship_cc !== 'US') { return; }

        $estimate = WRD_Duty_Engine::estimate_cart_duties();
        $totalUsd = (float)$estimate['total_usd'] + (float)($estimate['fees_usd'] ?? 0);
        $missing = (int)($estimate['missing_profiles'] ?? 0);

        // Behavior on missing profiles
        $missingBehavior = $settings['missing_profile_behavior'] ?? 'fallback';
        if ($missing > 0 && $missingBehavior === 'block') {
            wc_add_notice(__('We cannot complete checkout: missing customs profile for one or more items.', 'woocommerce-us-duties'), 'error');
            return;
        }

        // Convert back to current currency (WPML compatible)
        $currency = WRD_Duty_Engine::current_currency();
        $amountStore = WRD_FX::convert($totalUsd, 'USD', $currency);

        if ($amountStore <= 0) { return; }

        $label = !empty($settings['fee_label']) ? $settings['fee_label'] : __('Estimated US Duties', 'woocommerce-us-duties');
        $mode = $settings['ddp_mode'] ?? 'charge';
        if ($mode === 'charge') {
            WC()->cart->add_fee(esc_html($label), $amountStore, false);
        } else {
            static $notified = false; if ($notified) { return; } $notified = true;
            /* translators: %s is the formatted amount */
            wc_add_notice(sprintf(__('Estimated import duties: %s (payable to carrier on delivery)', 'woocommerce-us-duties'), wc_price($amountStore)), 'notice');
        }
    }

    public function snapshot_duties_to_order($order, $data): void {
        // WC_Order (HPOS safe); store machine-readable snapshot
        $estimate = WRD_Duty_Engine::estimate_cart_duties();
        $estimate['currency'] = WRD_Duty_Engine::current_currency();
        $estimate['timestamp'] = time();
        $order->update_meta_data('_wrd_duty_snapshot', wp_json_encode($estimate));
    }

    public function register_order_duty_meta_box(): void {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'wrd-order-duty-snapshot',
            __('US Duty Breakdown', 'woocommerce-us-duties'),
            [$this, 'render_order_duty_meta_box'],
            $screen,
            'normal',
            'default'
        );
    }

    public function render_order_duty_meta_box($post_or_order_object): void {
        $order = $post_or_order_object instanceof \WP_Post
            ? wc_get_order($post_or_order_object->ID)
            : $post_or_order_object;

        if (!$order) {
            return;
        }

        $snapshot_json = $order->get_meta('_wrd_duty_snapshot');
        if (!$snapshot_json) {
            echo '<p>' . esc_html__('No duty data available for this order.', 'woocommerce-us-duties') . '</p>';
            return;
        }

        $snapshot = json_decode($snapshot_json, true);
        if (!$snapshot || !isset($snapshot['lines'])) {
            echo '<p>' . esc_html__('Invalid duty data format.', 'woocommerce-us-duties') . '</p>';
            return;
        }

        // Summary information
        echo '<div style="margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #dcdcde;">';
        if (isset($snapshot['timestamp'])) {
            echo '<p style="margin: 0 0 8px 0;"><strong>' . esc_html__('Calculated:', 'woocommerce-us-duties') . '</strong> ';
            echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $snapshot['timestamp']));
            echo '</p>';
        }
        if (isset($snapshot['currency'])) {
            echo '<p style="margin: 0;"><strong>' . esc_html__('Currency:', 'woocommerce-us-duties') . '</strong> ' . esc_html($snapshot['currency']) . '</p>';
        }
        echo '</div>';

        // Product line items table
        echo '<div style="overflow-x: auto;">';
        echo '<table class="widefat striped" style="border: 1px solid #c3c4c7; margin-bottom: 16px;">';
        echo '<thead><tr style="background: #f6f7f7;">';
        echo '<th style="padding: 8px;">' . esc_html__('Product', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px;">' . esc_html__('HS Code', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px;">' . esc_html__('Origin', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px;">' . esc_html__('Channel', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px; text-align: right;">' . esc_html__('Rate', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px; text-align: right;">' . esc_html__('Value', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px; text-align: right;">' . esc_html__('Duty', 'woocommerce-us-duties') . '</th>';
        echo '<th style="padding: 8px; text-align: center;">' . esc_html__('CUSMA', 'woocommerce-us-duties') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($snapshot['lines'] as $line) {
            $product_id = $line['product_id'] ?? 0;
            $product = wc_get_product($product_id);
            $product_name = $product ? $product->get_name() : __('Unknown Product', 'woocommerce-us-duties');

            $hs_code = $line['debug']['hs_code'] ?? '-';
            $origin = $line['origin'] ?? '-';
            $channel = $line['channel'] ?? '-';
            $rate = isset($line['rate_pct']) ? number_format($line['rate_pct'], 2) . '%' : '-';
            $value = isset($line['value_usd']) ? '$' . number_format($line['value_usd'], 2) : '-';
            $duty = isset($line['duty_usd']) ? '$' . number_format($line['duty_usd'], 2) : '-';
            $cusma = !empty($line['cusma']) ? '✓' : '-';

            echo '<tr>';
            echo '<td style="padding: 8px;">' . esc_html($product_name) . '</td>';
            echo '<td style="padding: 8px;">' . esc_html($hs_code) . '</td>';
            echo '<td style="padding: 8px;">' . esc_html($origin) . '</td>';
            echo '<td style="padding: 8px;">' . esc_html(ucfirst($channel)) . '</td>';
            echo '<td style="padding: 8px; text-align: right;">' . esc_html($rate) . '</td>';
            echo '<td style="padding: 8px; text-align: right;">' . esc_html($value) . '</td>';
            echo '<td style="padding: 8px; text-align: right;">' . esc_html($duty) . '</td>';
            echo '<td style="padding: 8px; text-align: center;">' . esc_html($cusma) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';

        // Totals section
        echo '<div style="background: #f6f7f7; padding: 12px; border: 1px solid #c3c4c7; border-radius: 2px;">';

        if (isset($snapshot['composition'])) {
            $comp = $snapshot['composition'];
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('CUSMA Duty-Free Value:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>$' . number_format($comp['cusma_value_usd'] ?? 0, 2) . '</strong>';
            echo '</div>';
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('Dutiable Value:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>$' . number_format($comp['non_cusma_value_usd'] ?? 0, 2) . '</strong>';
            echo '</div>';
            echo '<hr style="margin: 8px 0; border: none; border-top: 1px solid #dcdcde;">';
        }

        if (isset($snapshot['total_usd'])) {
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('Total Duties:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>$' . number_format($snapshot['total_usd'], 2) . '</strong>';
            echo '</div>';
        }

        if (isset($snapshot['fees']) && !empty($snapshot['fees'])) {
            foreach ($snapshot['fees'] as $fee) {
                echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
                echo '<span>' . esc_html($fee['label']) . ':</span>';
                echo '<strong>$' . number_format($fee['amount_usd'], 2) . '</strong>';
                echo '</div>';
            }
        }

        if (isset($snapshot['fees_usd'])) {
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('Total Fees:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>$' . number_format($snapshot['fees_usd'], 2) . '</strong>';
            echo '</div>';
        }

        $grand_total = ($snapshot['total_usd'] ?? 0) + ($snapshot['fees_usd'] ?? 0);
        echo '<hr style="margin: 8px 0; border: none; border-top: 1px solid #dcdcde;">';
        echo '<div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 1.1em;">';
        echo '<strong>' . esc_html__('Grand Total (USD):', 'woocommerce-us-duties') . '</strong>';
        echo '<strong style="color: #2271b1;">$' . number_format($grand_total, 2) . '</strong>';
        echo '</div>';

        echo '</div>';

        // Debug info (collapsible)
        if (isset($snapshot['missing_profiles']) && $snapshot['missing_profiles'] > 0) {
            echo '<details style="margin-top: 12px;">';
            echo '<summary style="cursor: pointer; color: #d63638; padding: 8px 0;"><strong>⚠ ' . esc_html__('Missing Profiles', 'woocommerce-us-duties') . '</strong></summary>';
            echo '<div style="padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; margin-top: 4px;">';
            echo '<p style="margin: 0;">' . sprintf(esc_html__('%d product(s) did not have matching duty profiles.', 'woocommerce-us-duties'), (int) $snapshot['missing_profiles']) . '</p>';
            echo '</div>';
            echo '</details>';
        }
    }

    public function admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Duty Manager', 'wrd-us-duty'),
            __('Duty Manager', 'wrd-us-duty'),
            'manage_woocommerce',
            'wrd-duty-manager',
            [$this, 'render_duty_manager']
        );
        
        add_submenu_page(
            'woocommerce',
            __('Customs & Duties', 'wrd-us-duty'),
            __('Customs & Duties', 'wrd-us-duty'),
            'manage_woocommerce',
            'wrd-customs',
            [$this, 'render_customs_hub']
        );
    }
    
    public function render_duty_manager(): void {
        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-duty-manager.php';
        $manager = new WRD_Duty_Manager();
        $manager->render_page();
    }


    public function render_customs_hub(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        // Early export handling
        if (isset($_GET['action'])) {
            $action = sanitize_key(wp_unslash($_GET['action']));
            if ($action === 'export') { // legacy: export profiles
                $this->export_csv();
                return;
            } elseif ($action === 'export_products') {
                $this->export_products_csv();
                return;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Customs & Duties', 'woocommerce-us-duties') . '</h1>';
        $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'profiles';
        $tabs = [
            'profiles' => __('Profiles', 'woocommerce-us-duties'),
            'reconcile' => __('Reconciliation', 'woocommerce-us-duties'),
            'import' => __('Import/Export', 'woocommerce-us-duties'),
            'settings' => __('Settings', 'woocommerce-us-duties'),
            'tools' => __('Tools', 'woocommerce-us-duties'),
        ];
        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(['page' => 'wrd-customs', 'tab' => $key], admin_url('admin.php'));
            printf('<a href="%s" class="nav-tab %s">%s</a>', esc_url($url), $active === $key ? 'nav-tab-active' : '', esc_html($label));
        }
        echo '</h2>';

        if ($active === 'profiles') {
            $this->render_tab_profiles();
        } elseif ($active === 'reconcile') {
            $this->render_tab_reconciliation();
        } elseif ($active === 'import') {
            $this->render_tab_import_export();
        } elseif ($active === 'settings') {
            (new WRD_Settings())->render_settings_fields(false);
        } else {
            $this->render_tab_tools();
        }

        echo '</div>';
    }

    private function render_tab_profiles(): void {
        // Impacted products or edit views
        if (isset($_GET['action']) && $_GET['action'] === 'impacted') {
            $this->render_impacted_products_page();
            return;
        }
        if (isset($_GET['action']) && in_array($_GET['action'], ['new','edit'], true)) {
            // Handle save
            if (!empty($_POST['wrd_profile_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_profile_nonce'])), 'wrd_save_profile')) {
                $this->save_profile_from_post();
                echo '<div class="updated"><p>' . esc_html__('Profile saved.', 'wrd-us-duty') . '</p></div>';
            }
            $this->render_profile_form();
            return;
        }
    $newUrl = add_query_arg(['page' => 'wrd-customs', 'tab' => 'profiles', 'action' => 'new'], admin_url('admin.php'));
    $exportUrl = add_query_arg(['page' => 'wrd-customs', 'tab' => 'import', 'action' => 'export'] + array_intersect_key($_GET, ['s' => true]), admin_url('admin.php'));
        echo '<a href="' . esc_url($newUrl) . '" class="page-title-action">' . esc_html__('Add New', 'woocommerce-us-duties') . '</a> ';
        echo '<a href="' . esc_url($exportUrl) . '" class="page-title-action">' . esc_html__('Export CSV', 'woocommerce-us-duties') . '</a>';
        echo '<hr class="wp-header-end" />';

        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-profiles-table.php';
        $table = new WRD_Profiles_Table();
        $table->process_bulk_action();
        echo '<form method="post" id="wrd-profiles-form" data-wrd-bulk-form="1">';
        echo '<input type="hidden" name="page" value="wrd-customs" />';
        echo '<input type="hidden" name="tab" value="profiles" />';
        $table->prepare_items();
        // WooCommerce-style bulk edit panel
        $this->render_bulk_edit_panel();
        $table->search_box(__('Search Profiles', 'woocommerce-us-duties'), 'wrd_profiles');
        $table->display();
        echo '</form>';
        
        // Enqueue bulk edit assets
        wp_enqueue_style('wrd-bulk-edit', WRD_US_DUTY_URL . 'assets/admin-bulk-edit.css', [], WRD_US_DUTY_VERSION);
        wp_enqueue_script('wrd-bulk-edit', WRD_US_DUTY_URL . 'assets/admin-bulk-edit.js', ['jquery', 'jquery-ui-autocomplete'], WRD_US_DUTY_VERSION, true);
        wp_localize_script('wrd-bulk-edit', 'wrdBulkEdit', [
            'nonce' => wp_create_nonce('wrd_bulk_edit_nonce'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete the selected profiles?', 'woocommerce-us-duties'),
                'no_items_selected' => __('Please select items to perform bulk actions.', 'woocommerce-us-duties'),
                'processing' => __('Processing...', 'woocommerce-us-duties'),
            ]
        ]);
    }

    private function render_bulk_edit_panel(): void {
        ?>
        <div id="wrd-bulk-edit" class="tablenav" style="display: none;">
            <div class="alignleft actions bulkactions">
                <div class="bulk-edit-fields">
                    <fieldset class="inline-edit-col-left">
                        <legend class="inline-edit-legend"><?php esc_html_e('Bulk Edit Effective Dates', 'woocommerce-us-duties'); ?></legend>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('Effective From', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_effective_from_action" class="bulk-date-action">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="set"><?php esc_html_e('Set to:', 'woocommerce-us-duties'); ?></option>
                                        <option value="clear"><?php esc_html_e('Clear', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                    <input type="date" name="bulk_effective_from" class="bulk-date-input" style="display: none;" />
                                </span>
                            </label>
                        </div>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('Effective To', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_effective_to_action" class="bulk-date-action">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="set"><?php esc_html_e('Set to:', 'woocommerce-us-duties'); ?></option>
                                        <option value="clear"><?php esc_html_e('Clear (never expires)', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                    <input type="date" name="bulk_effective_to" class="bulk-date-input" style="display: none;" />
                                </span>
                            </label>
                        </div>
                    </fieldset>
                    <div class="bulk-edit-save">
                        <button type="button" class="button cancel alignleft"><?php esc_html_e('Cancel', 'woocommerce-us-duties'); ?></button>
                        <input type="submit" name="bulk_edit" class="button button-primary alignright" value="<?php esc_attr_e('Update', 'woocommerce-us-duties'); ?>" />
                        <span class="spinner"></span>
                        <input type="hidden" name="bulk_edit_nonce" value="<?php echo esc_attr(wp_create_nonce('wrd_bulk_edit_nonce')); ?>" />
                        <br class="clear" />
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_tab_import_export(): void {
        echo '<h2>' . esc_html__('Import CSV (Profiles)', 'woocommerce-us-duties') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('wrd_import_csv', 'wrd_import_nonce');
        echo '<p><input type="file" name="wrd_csv" accept=".csv" required /></p>';
        echo '<p><strong>' . esc_html__('Header Mapping (optional)', 'woocommerce-us-duties') . '</strong><br/>';
        echo esc_html__('Leave blank to use standard names: description, country_code, hs_code, fta_flags, us_duty_json, effective_from, effective_to, notes.', 'woocommerce-us-duties') . '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('Description', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_description" placeholder="description" /></label> ';
        echo '<label>' . esc_html__('Country Code', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_country" placeholder="country_code" /></label> ';
        echo '<label>' . esc_html__('HS Code', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_hs" placeholder="hs_code" /></label>';
        echo '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('FTA Flags (JSON)', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_fta" placeholder="fta_flags" /></label> ';
        echo '<label>' . esc_html__('US Duty JSON', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_udj" placeholder="us_duty_json" /></label>';
        echo '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('Effective From', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_from" placeholder="effective_from" /></label> ';
        echo '<label>' . esc_html__('Effective To', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_to" placeholder="effective_to" /></label> ';
        echo '<label>' . esc_html__('Notes', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_notes" placeholder="notes" /></label>';
        echo '</p>';
        echo '<p><label><input type="checkbox" name="profiles_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Import', 'woocommerce-us-duties'));
        echo '</form>';

    // Quick export links
    $export_profiles_url = add_query_arg(['page' => 'wrd-customs', 'tab' => 'import', 'action' => 'export'], admin_url('admin.php'));
    $export_products_url = add_query_arg(['page' => 'wrd-customs', 'tab' => 'import', 'action' => 'export_products'], admin_url('admin.php'));
    echo '<p style="margin-top:8px;">';
    echo '<a href="' . esc_url($export_profiles_url) . '" class="button">' . esc_html__('Export Profiles CSV', 'woocommerce-us-duties') . '</a> ';
    echo '<a href="' . esc_url($export_products_url) . '" class="button button-primary">' . esc_html__('Export Products CSV', 'woocommerce-us-duties') . '</a>';
    echo '</p>';

    echo '<h2>' . esc_html__('Import Duties JSON File', 'woocommerce-us-duties') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-top:8px;">';
        wp_nonce_field('wrd_import_json', 'wrd_import_json_nonce');
        echo '<p><input type="file" name="wrd_json" accept="application/json,.json" required /></p>';
        echo '<p><label>Effective From <input type="date" name="effective_from" value="' . esc_attr(date('Y-m-d')) . '" required /></label></p>';
    echo '<p><label><input type="checkbox" name="replace_existing" value="1" /> ' . esc_html__('Update if profile exists with same HS code, country, and date', 'woocommerce-us-duties') . '</label></p>';
    echo '<p><label>Notes (optional)<br/><input type="text" name="notes" class="regular-text" placeholder="Imported duties file" /></label></p>';
        submit_button(__('Import JSON', 'woocommerce-us-duties'));
        echo '</form>';

        echo '<h2>' . esc_html__('Cleanup Duplicate Profiles', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('Merge duplicate profiles that have the same HS code and country code. Only processes profiles with HS codes. Product assignments will be preserved and moved to the canonical profile.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" style="margin-top:8px;">';
        wp_nonce_field('wrd_cleanup_duplicates', 'wrd_cleanup_nonce');
        echo '<p><label><input type="checkbox" name="cleanup_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Find & Merge Duplicates', 'woocommerce-us-duties'), 'secondary');
        echo '</form>';

        echo '<h2>' . esc_html__('Migrate Products to HS Codes', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('For products missing HS codes, look up matching profiles by description + country and populate the HS code from the profile.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" style="margin-top:8px;">';
        wp_nonce_field('wrd_migrate_hs', 'wrd_migrate_hs_nonce');
        echo '<p><label><input type="checkbox" name="migrate_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p><label>' . esc_html__('Limit', 'woocommerce-us-duties') . ' <input type="number" name="migrate_limit" value="100" min="1" max="10000" style="width:80px" /> ' . esc_html__('products', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Migrate HS Codes', 'woocommerce-us-duties'), 'secondary');
        echo '</form>';

        // Handle Profiles CSV import
        if (!empty($_POST['wrd_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_import_nonce'])), 'wrd_import_csv')) {
            $summary = $this->handle_csv_import();
            if (is_array($summary)) {
                echo '<div class="notice notice-info"><p>' . esc_html(sprintf(
                    __('Profiles CSV: %1$d rows, %2$d inserted, %3$d updated, %4$d skipped, %5$d errors. (dry-run: %6$s)', 'woocommerce-us-duties'),
                    (int)($summary['rows'] ?? 0), (int)($summary['inserted'] ?? 0), (int)($summary['updated'] ?? 0), (int)($summary['skipped'] ?? 0), (int)($summary['errors'] ?? 0), !empty($summary['dry_run']) ? 'yes' : 'no'
                )) . '</p>';
                if (!empty($summary['messages'])) {
                    echo '<details><summary>' . esc_html__('Details', 'woocommerce-us-duties') . '</summary><pre style="background:#fff;padding:8px;max-height:220px;overflow:auto;">' . esc_html(implode("\n", array_slice($summary['messages'], 0, 50))) . '</pre></details>';
                }
                echo '</div>';
            } else {
                echo '<div class="updated"><p>' . esc_html__('Profiles CSV import completed.', 'woocommerce-us-duties') . '</p></div>';
            }
        }

        // Handle duties JSON import
        if (!empty($_POST['wrd_import_json_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_import_json_nonce'])), 'wrd_import_json')) {
            $json_summary = $this->handle_duty_json_import();
            if (is_array($json_summary)) {
                echo '<div class="notice notice-info"><p>' . esc_html(sprintf(
                    /* translators: 1: inserted count, 2: updated count, 3: skipped count, 4: errors count */
                    __('JSON Import: %1$d inserted, %2$d updated, %3$d skipped, %4$d errors.', 'woocommerce-us-duties'),
                    (int)($json_summary['inserted'] ?? 0),
                    (int)($json_summary['updated'] ?? 0),
                    (int)($json_summary['skipped'] ?? 0),
                    (int)($json_summary['errors'] ?? 0)
                )) . '</p>';
                if (!empty($json_summary['error_messages'])) {
                    echo '<details><summary>' . esc_html__('Error details', 'woocommerce-us-duties') . '</summary><pre style="background:#fff;padding:8px;max-height:220px;overflow:auto;">' . esc_html(implode("\n", array_slice((array)$json_summary['error_messages'], 0, 50))) . '</pre></details>';
                }
                echo '</div>';
            }
        }

        // Handle duplicate cleanup
        if (!empty($_POST['wrd_cleanup_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_cleanup_nonce'])), 'wrd_cleanup_duplicates')) {
            $cleanup_summary = $this->handle_duplicate_cleanup();
            if (is_array($cleanup_summary)) {
                echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                    __('Cleanup: %1$d duplicates found, %2$d merged, %3$d products reassigned, %4$d profiles deleted. (dry-run: %5$s)', 'woocommerce-us-duties'),
                    (int)($cleanup_summary['duplicates_found'] ?? 0),
                    (int)($cleanup_summary['merged'] ?? 0),
                    (int)($cleanup_summary['products_reassigned'] ?? 0),
                    (int)($cleanup_summary['profiles_deleted'] ?? 0),
                    !empty($cleanup_summary['dry_run']) ? 'yes' : 'no'
                )) . '</p>';
                if (!empty($cleanup_summary['messages'])) {
                    echo '<details><summary>' . esc_html__('Details', 'woocommerce-us-duties') . '</summary><pre style="background:#fff;padding:8px;max-height:300px;overflow:auto;">' . esc_html(implode("\n", (array)$cleanup_summary['messages'])) . '</pre></details>';
                }
                echo '</div>';
            }
        }

        // Handle HS code migration
        if (!empty($_POST['wrd_migrate_hs_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_migrate_hs_nonce'])), 'wrd_migrate_hs')) {
            $migrate_summary = $this->handle_hs_migration();
            if (is_array($migrate_summary)) {
                echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
                    __('Migration: %1$d products checked, %2$d missing HS codes, %3$d matched to profiles, %4$d updated. (dry-run: %5$s)', 'woocommerce-us-duties'),
                    (int)($migrate_summary['checked'] ?? 0),
                    (int)($migrate_summary['missing_hs'] ?? 0),
                    (int)($migrate_summary['matched'] ?? 0),
                    (int)($migrate_summary['updated'] ?? 0),
                    !empty($migrate_summary['dry_run']) ? 'yes' : 'no'
                )) . '</p>';
                if (!empty($migrate_summary['messages'])) {
                    echo '<details><summary>' . esc_html__('Details', 'woocommerce-us-duties') . '</summary><pre style="background:#fff;padding:8px;max-height:300px;overflow:auto;">' . esc_html(implode("\n", array_slice((array)$migrate_summary['messages'], 0, 100))) . '</pre></details>';
                }
                echo '</div>';
            }
        }

        // Products CSV importer
        if (!empty($_POST['wrd_products_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_products_import_nonce'])), 'wrd_products_import')) {
            $summary = $this->handle_products_csv_import();
            if ($summary) {
                echo '<div class="notice notice-info"><p>' . esc_html(sprintf(__('Products CSV: %d rows, %d matched, %d updated, %d skipped, %d errors.', 'woocommerce-us-duties'), $summary['rows'], $summary['matched'], $summary['updated'], $summary['skipped'], $summary['errors'])) . '</p>';
                if (!empty($summary['messages'])) {
                    echo '<details><summary>' . esc_html__('Details', 'woocommerce-us-duties') . '</summary><pre style="background:#fff;padding:8px;max-height:220px;overflow:auto;">' . esc_html(implode("\n", array_slice($summary['messages'], 0, 50))) . '</pre></details>';
                }
                echo '</div>';
            }
        }

        echo '<h2>' . esc_html__('Assign Customs to Products (CSV)', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('Upload a CSV with columns for product ID/SKU, HS code, country code, and optionally description. HS code + country is preferred.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-top:8px;">';
        wp_nonce_field('wrd_products_import', 'wrd_products_import_nonce');
        echo '<p><input type="file" name="wrd_products_csv" accept=".csv" required /></p>';
        echo '<p><label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__('Dry run only (no changes)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p><strong>' . esc_html__('Header Mapping (optional)', 'woocommerce-us-duties') . '</strong><br/>';
        echo esc_html__('Leave blank to autodetect common names like product_id, sku, hs_code, country_code, customs_description.', 'woocommerce-us-duties') . '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('Product Identifier Header', 'woocommerce-us-duties') . ' <input type="text" name="map_identifier" placeholder="product_id or sku" /></label> ';
        echo '<label>' . esc_html__('Identifier Type', 'woocommerce-us-duties') . ' <select name="identifier_type"><option value="auto">' . esc_html__('Auto', 'woocommerce-us-duties') . '</option><option value="id">' . esc_html__('Product ID', 'woocommerce-us-duties') . '</option><option value="sku">' . esc_html__('SKU', 'woocommerce-us-duties') . '</option></select></label>';
        echo '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('HS Code Header', 'woocommerce-us-duties') . ' <input type="text" name="map_hs" placeholder="hs_code" /></label> ';
        echo '<label>' . esc_html__('Country Code Header', 'woocommerce-us-duties') . ' <input type="text" name="map_cc" placeholder="country_code" /></label> ';
        echo '<label>' . esc_html__('Description Header', 'woocommerce-us-duties') . ' <input type="text" name="map_desc" placeholder="customs_description" /></label>';
        echo '</p>';
        submit_button(__('Import Products CSV', 'woocommerce-us-duties'));
        echo '</form>';
    }

    private function render_tab_tools(): void {
        echo '<h2>' . esc_html__('Reindex Products (normalized meta)', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('Rebuilds normalized description and origin for products and variations so impacted-product counts are accurate. Safe to run multiple times.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wrd_reindex', 'wrd_reindex_nonce');
        echo '<p><label>Max items <input type="number" name="max" value="1000" min="100" step="100" /></label> ';
        submit_button(__('Run Reindex', 'woocommerce-us-duties'), 'secondary', '', false);
        echo '</p></form>';

        if (!empty($_POST['wrd_reindex_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_reindex_nonce'])), 'wrd_reindex')) {
            $processed = $this->reindex_products((int)($_POST['max'] ?? 1000));
            echo '<div class="updated"><p>' . esc_html(sprintf(__('Reindexed %d items.', 'woocommerce-us-duties'), $processed)) . '</p></div>';
        }

        echo '<h2>' . esc_html__('FX Tools', 'woocommerce-us-duties') . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('wrd_fx_tools', 'wrd_fx_tools_nonce');
        echo '<p>';
        submit_button(__('Refresh FX Rates Cache', 'woocommerce-us-duties'), 'secondary', 'wrd_fx_refresh', false);
        echo '</p></form>';
        if (!empty($_POST['wrd_fx_tools_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_fx_tools_nonce'])), 'wrd_fx_tools')) {
            if (!empty($_POST['wrd_fx_refresh'])) {
                delete_transient('wrd_fx_rates_exchangerate_host_USD');
                WRD_FX::get_rates_table('USD');
                echo '<div class="updated"><p>' . esc_html__('FX rates refreshed.', 'woocommerce-us-duties') . '</p></div>';
            }
        }
    }

    // --- Products list column: Customs status ---
    public function add_product_customs_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'sku') {
                $new['wrd_customs'] = __('Customs (HS)', 'wrd-us-duty');
            }
        }
        if (!isset($new['wrd_customs'])) {
            $new['wrd_customs'] = __('Customs (HS)', 'wrd-us-duty');
        }
        return $new;
    }

    public function render_product_customs_column($column, $post_id) {
        if ($column !== 'wrd_customs') { return; }

        // Get HS code and country from product
        $hs_code = trim((string) get_post_meta($post_id, '_hs_code', true));
        $origin = strtoupper(trim((string) get_post_meta($post_id, '_country_of_origin', true)));

        // For legacy products, also check description
        $desc = get_post_meta($post_id, '_customs_description', true);

        // Check variations for variable products
        $post_type = get_post_type($post_id);
        if ($post_type === 'product' && !$hs_code && !$origin) {
            $children = get_children([
                'post_parent' => (int) $post_id,
                'post_type' => 'product_variation',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
            ]);
            if ($children) {
                $vid = $children[0];
                $hs_code = trim((string) get_post_meta($vid, '_hs_code', true));
                $origin = strtoupper(trim((string) get_post_meta($vid, '_country_of_origin', true)));
                $desc = get_post_meta($vid, '_customs_description', true);
            }
        }

        // Display logic
        if ($hs_code && $origin) {
            // Has HS code + country - check if profile exists
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            if ($profile) {
                echo '<span style="color:#008a00;font-weight:500;">' . esc_html($hs_code) . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
            } else {
                echo '<span style="color:#d98300;font-weight:500;">' . esc_html($hs_code) . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
                echo '<br><span style="color:#d98300;font-size:11px;">' . esc_html__('No profile', 'wrd-us-duty') . '</span>';
            }
        } elseif ($desc && $origin) {
            // Legacy: has description + country but no HS code
            echo '<span style="color:#d98300;">' . esc_html__('Legacy', 'wrd-us-duty') . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
            echo '<br><span style="color:#999;font-size:11px;">' . esc_html__('Needs migration', 'wrd-us-duty') . '</span>';
        } elseif ($origin) {
            // Has country but missing HS code
            echo '<span style="color:#a00;">' . esc_html__('Missing HS', 'wrd-us-duty') . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
        } else {
            // Missing everything
            echo '<span style="color:#a00;">' . esc_html__('Missing', 'wrd-us-duty') . '</span>';
        }
    }

    // Add "Assign Profile" row action to products
    public function add_assign_profile_row_action($actions, $post) {
        if ($post->post_type !== 'product') {
            return $actions;
        }

        // Add the action after "Quick Edit"
        $new_actions = [];
        foreach ($actions as $key => $value) {
            $new_actions[$key] = $value;
            if ($key === 'inline hide-if-no-js') {
                $new_actions['wrd_assign_profile'] = sprintf(
                    '<a href="#" class="wrd-assign-profile-action" data-product-id="%d">%s</a>',
                    $post->ID,
                    esc_html__('Assign Profile', 'wrd-us-duty')
                );
            }
        }
        return $new_actions;
    }

    // Render inline assign profile template in admin footer (only on products page)
    public function render_inline_assign_template() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'edit-product') {
            return;
        }
        ?>
        <table id="wrd-inline-assign-template" style="display:none;">
            <tbody>
                <tr id="wrd-inline-assign-row" class="inline-edit-row inline-edit-row-post quick-edit-row quick-edit-row-post inline-edit-product">
                    <td colspan="10" class="colspanchange">
                        <fieldset class="inline-edit-col-left" style="width: 100%;">
                            <legend class="inline-edit-legend"><?php esc_html_e('Assign Profile', 'wrd-us-duty'); ?></legend>
                            <div class="inline-edit-col" style="display: flex; gap: 12px; align-items: center;">
                                <label style="flex: 1;">
                                    <span class="title" style="width: auto; display: inline-block; margin-right: 8px;"><?php esc_html_e('Profile', 'wrd-us-duty'); ?></span>
                                    <input type="text" class="wrd-profile-lookup" placeholder="<?php esc_attr_e('Search by HS code or description...', 'wrd-us-duty'); ?>" style="width: 300px;" />
                                </label>
                                <label>
                                    <span class="title" style="width: auto; display: inline-block; margin-right: 8px;"><?php esc_html_e('HS Code', 'wrd-us-duty'); ?></span>
                                    <input type="text" class="wrd-hs-code" placeholder="<?php esc_attr_e('e.g., 8206.00.0000', 'wrd-us-duty'); ?>" style="width: 140px;" />
                                </label>
                                <label>
                                    <span class="title" style="width: auto; display: inline-block; margin-right: 8px;"><?php esc_html_e('Country', 'wrd-us-duty'); ?></span>
                                    <input type="text" class="wrd-country" placeholder="<?php esc_attr_e('CC', 'wrd-us-duty'); ?>" maxlength="2" style="width: 60px;" />
                                </label>
                                <div style="margin-left: auto;">
                                    <button type="button" class="button button-primary wrd-apply-assign"><?php esc_html_e('Apply', 'wrd-us-duty'); ?></button>
                                    <button type="button" class="button wrd-cancel-assign"><?php esc_html_e('Cancel', 'wrd-us-duty'); ?></button>
                                    <span class="spinner" style="float: none; margin: 0 0 0 8px;"></span>
                                </div>
                            </div>
                        </fieldset>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    // AJAX handler for quick profile assignment from products table
    public function ajax_quick_assign_profile() {
        check_ajax_referer('wrd_quick_assign', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $hs_code = isset($_POST['hs_code']) ? sanitize_text_field(wp_unslash($_POST['hs_code'])) : '';
        $country = isset($_POST['country']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['country']))) : '';

        if (!$product_id || !$hs_code || !$country) {
            wp_send_json_error(['message' => 'Missing required fields'], 400);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Product not found'], 404);
        }

        // Update product meta
        $product->update_meta_data('_hs_code', $hs_code);
        $product->update_meta_data('_country_of_origin', $country);

        // Look up and link profile if available
        $profile = WRD_DB::get_profile_by_hs_country($hs_code, $country);
        if ($profile && isset($profile['id'])) {
            $product->update_meta_data('_wrd_profile_id', (int)$profile['id']);
        }

        $product->save();

        // Update normalized meta
        if ($product->is_type('variation')) {
            $this->update_normalized_meta_for_variation($product_id);
        } else {
            $this->update_normalized_meta_for_product($product_id);
        }

        wp_send_json_success([
            'message' => 'Profile assigned successfully',
            'hs_code' => $hs_code,
            'country' => $country,
            'has_profile' => !empty($profile)
        ]);
    }

    // --- Quick/Bulk edit fields and handler ---
    public function quick_bulk_edit_box($column_name, $post_type) {
        if ($post_type !== 'product') { return; }
        static $rendered = false; if ($rendered) { return; } $rendered = true;
        // Render our fields once in the bulk/quick edit panel
        echo '<fieldset class="inline-edit-col-right wrd-customs-fields"><div class="inline-edit-col">';
        echo '<h4>' . esc_html__('Customs', 'wrd-us-duty') . '</h4>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Profile (type to search)', 'wrd-us-duty') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" class="wrd-profile-lookup" placeholder="' . esc_attr__('Search by HS code, country, or description...', 'wrd-us-duty') . '" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('HS Code', 'wrd-us-duty') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_hs_code" value="" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Origin (ISO-2)', 'wrd-us-duty') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_country_of_origin" value="" maxlength="2" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Description', 'wrd-us-duty') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_customs_description" value="" /></span>';
        echo '</label>';
        echo '<p class="description">' . esc_html__('Leave blank to keep existing values. Applies to selected items for Bulk Edit.', 'wrd-us-duty') . '</p>';
        echo '</div></fieldset>';
    }

    public function quick_bulk_edit_panel() {
        // Secondary render inside WC panels for robustness (bulk editor UI)
        echo '<div class="wrd-customs-inline" style="margin-top:8px;">';
        echo '<strong>' . esc_html__('Customs', 'wrd-us-duty') . ':</strong> ';
        echo '<input type="text" class="wrd-profile-lookup" style="min-width:260px" placeholder="' . esc_attr__('Search profile...', 'wrd-us-duty') . '" /> ';
        echo '<input type="text" name="wrd_hs_code" placeholder="' . esc_attr__('HS Code', 'wrd-us-duty') . '" style="width:120px" /> ';
        echo '<input type="text" name="wrd_country_of_origin" placeholder="' . esc_attr__('CC', 'wrd-us-duty') . '" maxlength="2" style="width:60px" /> ';
        echo '<input type="text" name="wrd_customs_description" placeholder="' . esc_attr__('Description', 'wrd-us-duty') . '" /> ';
        echo '</div>';
    }

    public function handle_quick_bulk_save($product) {
        if (!$product instanceof WC_Product) { return; }
        // Use same fields for both quick and bulk edit
        $hs_code = isset($_REQUEST['wrd_hs_code']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['wrd_hs_code']))) : '';
        $desc = isset($_REQUEST['wrd_customs_description']) ? wp_kses_post(wp_unslash($_REQUEST['wrd_customs_description'])) : '';
        $origin = isset($_REQUEST['wrd_country_of_origin']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['wrd_country_of_origin']))) : '';

        $changed = false;
        if ($hs_code !== '') { $product->update_meta_data('_hs_code', $hs_code); $changed = true; }
        if ($desc !== '') { $product->update_meta_data('_customs_description', $desc); $changed = true; }
        if ($origin !== '') { $product->update_meta_data('_country_of_origin', $origin); $changed = true; }
        if ($changed) {
            $product->save();
            // update normalized meta
            $this->update_normalized_meta_for_product((int)$product->get_id());
        }
    }

    public function enqueue_admin_assets($hook) {
        // Products list page
        if ($hook === 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'product') {
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_script(
                'wrd-admin-quick-bulk',
                WRD_US_DUTY_URL . 'assets/admin-quick-bulk.js',
                ['jquery','jquery-ui-autocomplete'],
                WRD_US_DUTY_VERSION,
                true
            );
            wp_localize_script('wrd-admin-quick-bulk', 'WRDProfiles', [
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wrd_search_profiles'),
            ]);

            // Inline assign profile
            wp_enqueue_script(
                'wrd-admin-inline-assign',
                WRD_US_DUTY_URL . 'assets/admin-inline-assign.js',
                ['jquery','jquery-ui-autocomplete'],
                WRD_US_DUTY_VERSION,
                true
            );
            wp_localize_script('wrd-admin-inline-assign', 'WRDInlineAssign', [
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wrd_quick_assign'),
                'searchNonce' => wp_create_nonce('wrd_search_profiles'),
            ]);
            return;
        }
        
        // Customs hub pages
        if ($hook === 'woocommerce_page_wrd-customs') {
            wp_enqueue_script('jquery');
            // Reconciliation page assets
            if (isset($_GET['tab']) && $_GET['tab'] === 'reconcile') {
                wp_enqueue_script('jquery-ui-autocomplete');
                wp_enqueue_script(
                    'wrd-admin-reconcile',
                    WRD_US_DUTY_URL . 'assets/admin-reconcile.js',
                    ['jquery','jquery-ui-autocomplete'],
                    WRD_US_DUTY_VERSION,
                    true
                );
                wp_localize_script('wrd-admin-reconcile', 'WRDReconcile', [
                    'ajax' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wrd_reconcile_nonce'),
                    'searchNonce' => wp_create_nonce('wrd_search_profiles'),
                    'i18n' => [
                        'applied' => __('Applied', 'woocommerce-us-duties'),
                        'error' => __('Error', 'woocommerce-us-duties'),
                    ],
                ]);
            }
            return;
        }
    }

    public function ajax_search_profiles() {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error('forbidden', 403); }
        check_ajax_referer('wrd_search_profiles', 'nonce');
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        global $wpdb; $table = WRD_DB::table_profiles();
        $like = '%' . $wpdb->esc_like($term) . '%';
        $sql = $wpdb->prepare(
            "SELECT description_raw, country_code, hs_code
             FROM {$table}
             WHERE description_raw LIKE %s OR description_normalized LIKE %s OR country_code LIKE %s OR hs_code LIKE %s
             GROUP BY hs_code, country_code
             ORDER BY hs_code ASC, country_code ASC
             LIMIT 20",
            $like, $like, $like, $like
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $hs = !empty($r['hs_code']) ? $r['hs_code'] : '';
            $cc = strtoupper($r['country_code']);
            $desc = $r['description_raw'];

            // Format label: "HSCODE (CC): Description" or "Description (CC)" if no HS code
            if ($hs) {
                $text = $hs . ' (' . $cc . '): ' . $desc;
            } else {
                $text = $desc . ' (' . $cc . ')';
            }

            $out[] = [
                'id' => $hs . '|' . $cc,
                'label' => $text,
                'value' => $text,
                'hs' => $hs,
                'cc' => $cc,
                'desc' => $desc,
            ];
        }
        wp_send_json($out);
    }

    // Assign selected description+country to a product via AJAX (from Reconciliation UI)
    public function ajax_reconcile_assign() {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'forbidden'], 403); }
        check_ajax_referer('wrd_reconcile_nonce', 'nonce');
        $pid = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $hs_code = isset($_POST['hs_code']) ? sanitize_text_field(wp_unslash($_POST['hs_code'])) : '';
        $cc = isset($_POST['cc']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['cc']))) : '';
        if ($pid <= 0 || $hs_code === '' || $cc === '') { wp_send_json_error(['message' => 'invalid_params'], 400); }
        $product = wc_get_product($pid);
        if (!$product) { wp_send_json_error(['message' => 'not_found'], 404); }
        $product->update_meta_data('_hs_code', $hs_code);
        $product->update_meta_data('_country_of_origin', $cc);
        // Optionally store a direct profile link if available
        $profile = WRD_DB::get_profile_by_hs_country($hs_code, $cc);
        if ($profile && isset($profile['id'])) {
            $product->update_meta_data('_wrd_profile_id', (int)$profile['id']);
        }
        $product->save();
        if ($product->is_type('variation')) {
            $this->update_normalized_meta_for_variation($pid);
        } else {
            $this->update_normalized_meta_for_product($pid);
        }
        // Return a small payload with status and maybe matched profile data
        $ratePostal = 0.0; $rateComm = 0.0;
        if ($profile && is_array($profile)) {
            $udj = is_array($profile['us_duty_json']) ? $profile['us_duty_json'] : json_decode((string)$profile['us_duty_json'], true);
            if (is_array($udj)) {
                $ratePostal = WRD_Duty_Engine::compute_rate_percent($udj, 'postal');
                $rateComm = WRD_Duty_Engine::compute_rate_percent($udj, 'commercial');
            }
        }
        wp_send_json_success(['postal_rate' => $ratePostal, 'commercial_rate' => $rateComm]);
    }

    // Suggest top N profiles for a product by HS code similarity and same origin
    public function ajax_reconcile_suggest() {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'forbidden'], 403); }
        check_ajax_referer('wrd_reconcile_nonce', 'nonce');
        $hs_code = isset($_GET['hs_code']) ? sanitize_text_field(wp_unslash($_GET['hs_code'])) : '';
        $cc = isset($_GET['cc']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['cc']))) : '';
        if ($hs_code === '' || $cc === '') { wp_send_json_success([]); }
        global $wpdb; $table = WRD_DB::table_profiles();
        $like = '%' . $wpdb->esc_like($hs_code) . '%';
        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "SELECT id, description_raw, country_code, hs_code
             FROM {$table}
             WHERE country_code=%s
               AND hs_code LIKE %s
               AND (effective_from IS NULL OR effective_from <= DATE(%s))
               AND (effective_to IS NULL OR effective_to >= DATE(%s))
             ORDER BY last_updated DESC
             LIMIT 5",
            $cc, $like, $now, $now
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $hs = $r['hs_code'] ?: '';
            $desc = $r['description_raw'] ?: '';
            $label = $hs ? ($hs . ' (' . strtoupper($r['country_code']) . '): ' . $desc) : ($desc . ' (' . strtoupper($r['country_code']) . ')');
            $out[] = [
                'id' => (int)$r['id'],
                'label' => $label,
                'hs' => $hs,
                'cc' => strtoupper($r['country_code']),
            ];
        }
        wp_send_json_success($out);
    }

    public function redirect_legacy_pages(): void {
        // Redirect old slug to new hub route to avoid "Sorry, you are not allowed to access this page."
        if (!is_admin()) { return; }
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($page === 'wrd-customs-profiles') {
            $args = [
                'page' => 'wrd-customs',
                'tab' => 'profiles',
            ];
            if (isset($_GET['action'])) { $args['action'] = sanitize_key(wp_unslash($_GET['action'])); }
            if (isset($_GET['id'])) { $args['id'] = (int) $_GET['id']; }
            if (isset($_GET['s'])) { $args['s'] = sanitize_text_field(wp_unslash($_GET['s'])); }
            if (isset($_GET['orderby'])) { $args['orderby'] = sanitize_key($_GET['orderby']); }
            if (isset($_GET['order'])) { $args['order'] = (strtolower($_GET['order']) === 'asc') ? 'asc' : 'desc'; }
            if (isset($_GET['paged'])) { $args['paged'] = max(1, (int) $_GET['paged']); }
            wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
            exit;
        }
    }

    // removed legacy profiles page

    private function handle_csv_import(): ?array {
        if (empty($_FILES['wrd_csv']) || !is_uploaded_file($_FILES['wrd_csv']['tmp_name'])) { return null; }
        $fh = fopen($_FILES['wrd_csv']['tmp_name'], 'r');
        if (!$fh) { return null; }
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return ['rows'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0,'errors'=>1,'messages'=>['Missing header']]; }
        $header_lc = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);

        // Optional mapping
        $map_field = function($post_key, $default) use ($header_lc){
            $name = strtolower(trim((string)($_POST[$post_key] ?? '')));
            if ($name === '') { $name = $default; }
            $idx = array_search($name, $header_lc, true);
            return $idx === false ? -1 : $idx;
        };

        $idx_desc = $map_field('map_profile_description', 'description');
        $idx_cc   = $map_field('map_profile_country', 'country_code');
        $idx_hs   = $map_field('map_profile_hs', 'hs_code');
        $idx_fta  = $map_field('map_profile_fta', 'fta_flags');
        $idx_udj  = $map_field('map_profile_udj', 'us_duty_json');
        $idx_from = $map_field('map_profile_from', 'effective_from');
        $idx_to   = $map_field('map_profile_to', 'effective_to');
        $idx_notes= $map_field('map_profile_notes', 'notes');

        $dry_run = !empty($_POST['profiles_dry_run']);
        $rows=$inserted=$updated=$skipped=$errors=0; $messages=[];
        global $wpdb; $table = WRD_DB::table_profiles();

        while (($row = fgetcsv($fh)) !== false) {
            $rows++;
            $description = $idx_desc >= 0 ? (string)($row[$idx_desc] ?? '') : '';
            $country = strtoupper(trim($idx_cc >= 0 ? (string)($row[$idx_cc] ?? '') : ''));
            if ($description === '' || $country === '') { $skipped++; $messages[] = "Row {$rows}: missing description or country"; continue; }
            $hs = $idx_hs >= 0 ? (string)($row[$idx_hs] ?? '') : '';
            $fta = $idx_fta >= 0 ? (string)($row[$idx_fta] ?? '[]') : '[]';
            $udj = $idx_udj >= 0 ? (string)($row[$idx_udj] ?? '{}') : '{}';
            $from = $idx_from >= 0 ? (string)($row[$idx_from] ?? date('Y-m-d')) : date('Y-m-d');
            $to = $idx_to >= 0 ? (string)($row[$idx_to] ?? '') : '';
            $notes = $idx_notes >= 0 ? (string)($row[$idx_notes] ?? '') : '';

            $descNorm = WRD_DB::normalize_description($description);
            // Validate JSON
            $fta_dec = json_decode($fta, true); if ($fta_dec === null && json_last_error() !== JSON_ERROR_NONE) { $fta_dec = []; }
            $udj_dec = json_decode($udj, true); if ($udj_dec === null && json_last_error() !== JSON_ERROR_NONE) { $udj_dec = ['postal'=>['rates'=>new stdClass()],'commercial'=>['rates'=>new stdClass()]]; }

            // Upsert by (desc_norm, country, effective_from)
            $existing_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE description_normalized=%s AND country_code=%s AND effective_from=%s LIMIT 1",
                $descNorm, $country, $from
            ));
            if ($dry_run) {
                if ($existing_id) { $updated++; } else { $inserted++; }
                continue;
            }
            $data = [
                'description_raw' => $description,
                'description_normalized' => $descNorm,
                'country_code' => $country,
                'hs_code' => $hs,
                'us_duty_json' => wp_json_encode($udj_dec, JSON_UNESCAPED_SLASHES),
                'fta_flags' => wp_json_encode($fta_dec),
                'effective_from' => $from,
                'effective_to' => $to !== '' ? $to : null,
                'notes' => $notes,
            ];
            if ($existing_id) {
                $ok = $wpdb->update($table, $data, ['id' => $existing_id]);
                if ($ok !== false) { $updated++; } else { $errors++; $messages[] = (string)$wpdb->last_error; }
            } else {
                $ok = $wpdb->insert($table, $data);
                if ($ok) { $inserted++; } else { $errors++; $messages[] = (string)$wpdb->last_error; }
            }
        }
        fclose($fh);
        return compact('rows','inserted','updated','skipped','errors') + ['dry_run' => $dry_run, 'messages' => $messages];
    }

    private function handle_duty_json_import(): ?array {
        if (empty($_FILES['wrd_json']) || !is_uploaded_file($_FILES['wrd_json']['tmp_name'])) { return null; }
        $json = file_get_contents($_FILES['wrd_json']['tmp_name']);
        if ($json === false) { return null; }
        $data = json_decode($json, true);
        $counters = ['inserted'=>0,'updated'=>0,'skipped'=>0,'errors'=>0, 'error_messages'=>[]];
        if (!is_array($data)) {
            $counters['errors'] = 1;
            $counters['error_messages'][] = 'Invalid JSON structure: expected object at root.';
            return $counters;
        }

        // Detect format: Zonos format has 'entries', legacy format has 'duties'
        $is_zonos_format = isset($data['entries']) && is_array($data['entries']);
        $duties = null;

        if ($is_zonos_format) {
            $duties = $data['entries'];
        } else {
            $duties = isset($data['duties']) ? $data['duties'] : null;
        }

        if (!is_array($duties)) {
            $counters['errors'] = 1;
            $counters['error_messages'][] = 'Invalid duties JSON: missing duties or entries array.';
            return $counters;
        }

        $effective_from = isset($_POST['effective_from']) ? sanitize_text_field(wp_unslash($_POST['effective_from'])) : date('Y-m-d');
        $replace = !empty($_POST['replace_existing']);
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';
        $sourceRaw = isset($data['source']) ? (string)$data['source'] : '';
        $fileSource = $this->normalize_profile_source($sourceRaw);
        if ($notes === '') {
            $notes = $sourceRaw !== '' ? sprintf('Imported from %s duties file', sanitize_text_field($sourceRaw)) : 'Imported duties file';
        }

        global $wpdb; $table = WRD_DB::table_profiles();

        foreach ($duties as $index => $entry) {
            if (!is_array($entry)) {
                $counters['skipped']++;
                $counters['error_messages'][] = sprintf('Entry %s is not an object.', (string)$index);
                continue;
            }

            // Parse based on format
            if ($is_zonos_format) {
                $description = trim((string)($entry['description'] ?? ''));
                $country = strtoupper(trim((string)($entry['origin'] ?? '')));
                $hs = (string)($entry['hs_code'] ?? '');
            } else {
                $description = trim((string)($entry['description'] ?? ''));
                $country = strtoupper(trim((string)($entry['originCountry'] ?? '')));
                $hs = (string)($entry['hsCode'] ?? '');
            }

            if ($description === '' || $country === '') {
                $counters['skipped']++;
                $counters['error_messages'][] = sprintf('Entry %s missing description or origin country.', (string)$index);
                continue;
            }

            if (strlen($hs) > 20) { $hs = substr($hs, 0, 20); }

            $entrySource = isset($entry['source']) ? $this->normalize_profile_source((string)$entry['source']) : $fileSource;

            $postal_rates = [];
            $commercial_rates = [];
            $fta_flags = [];

            if ($is_zonos_format) {
                // Zonos format has separate postal and commercial rate objects
                if (isset($entry['postal']['rates']) && is_array($entry['postal']['rates'])) {
                    $postal_rates = $entry['postal']['rates'];
                }
                if (isset($entry['commercial']['rates']) && is_array($entry['commercial']['rates'])) {
                    $commercial_rates = $entry['commercial']['rates'];
                }
            } else {
                // Legacy format: parse tariffs array and use same rates for postal/commercial
                $us_duty_rates = [];
                if (isset($entry['tariffs']) && is_array($entry['tariffs'])) {
                    foreach ($entry['tariffs'] as $tariffIndex => $tariff) {
                        if (!is_array($tariff)) { continue; }
                        if (!isset($tariff['rate']) || !is_numeric($tariff['rate'])) { continue; }

                        $rate_value = (float)$tariff['rate'];
                        $rate_label = isset($tariff['description']) ? (string)$tariff['description'] : '';
                        $fallback_key = isset($tariff['code']) ? (string)$tariff['code'] : '';
                        $rate_key_raw = $rate_label !== '' ? $rate_label : $fallback_key;
                        $rate_key = sanitize_key($rate_key_raw);
                        if ($rate_key === '') {
                            $rate_key = 'rate_' . substr(md5($rate_key_raw . $tariffIndex), 0, 8);
                        }
                        $us_duty_rates[$rate_key] = $rate_value;

                        $tariff_type = isset($tariff['type']) ? strtoupper((string)$tariff['type']) : '';
                        $tariff_code = isset($tariff['code']) ? strtoupper((string)$tariff['code']) : '';
                        if ($tariff_type === 'CUSMA_ELIGIBLE' || $tariff_code === 'CUSMA') {
                            if (!in_array('CUSMA', $fta_flags, true)) {
                                $fta_flags[] = 'CUSMA';
                            }
                        }
                    }
                }
                $postal_rates = $us_duty_rates;
                $commercial_rates = $us_duty_rates;
            }

            $us_duty_json_data = [
                'postal' => ['rates' => (object)$postal_rates],
                'commercial' => ['rates' => (object)$commercial_rates],
            ];

            $descNorm = WRD_DB::normalize_description($description);
            $lastUpdated = current_time('mysql');
            $fta_flags = array_values(array_unique($fta_flags));
            $dataRow = [
                'description_raw' => $description,
                'description_normalized' => $descNorm,
                'country_code' => $country,
                'hs_code' => $hs,
                'source' => $entrySource,
                'last_updated' => $lastUpdated,
                'us_duty_json' => wp_json_encode($us_duty_json_data, JSON_UNESCAPED_SLASHES),
                'fta_flags' => wp_json_encode($fta_flags),
                'effective_from' => $effective_from,
                'effective_to' => null,
                'notes' => $notes,
            ];

            // Match by HS code + country (description is fluid and can change)
            $existing_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE hs_code=%s AND country_code=%s AND effective_from=%s LIMIT 1",
                $hs, $country, $effective_from
            ));

            if ($existing_id > 0) {
                if ($replace) {
                    $ok = $wpdb->update($table, $dataRow, ['id' => $existing_id]);
                    if ($ok !== false) { $counters['updated']++; } else { $counters['errors']++; $counters['error_messages'][] = (string)$wpdb->last_error; }
                } else {
                    $counters['skipped']++;
                }
            } else {
                $ok = $wpdb->insert($table, $dataRow);
                if ($ok) { $counters['inserted']++; } else { $counters['errors']++; $counters['error_messages'][] = (string)$wpdb->last_error; }
            }
        }

        return $counters;
    }

    private function normalize_profile_source(string $source): string {
        $normalized = strtolower(trim($source));
        $normalized = preg_replace('/[^a-z0-9_\-]/', '', $normalized);
        if ($normalized === '') {
            return 'legacy';
        }
        return $normalized;
    }

    private function handle_hs_migration(): array {
        $dry_run = !empty($_POST['migrate_dry_run']);
        $limit = isset($_POST['migrate_limit']) ? max(1, min(10000, (int)$_POST['migrate_limit'])) : 100;

        $summary = [
            'checked' => 0,
            'missing_hs' => 0,
            'matched' => 0,
            'updated' => 0,
            'dry_run' => $dry_run,
            'messages' => [],
        ];

        // Query products that have description + country but no HS code
        $args = [
            'post_type' => ['product', 'product_variation'],
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [
                        'key' => '_hs_code',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => '_hs_code',
                        'value' => '',
                        'compare' => '=',
                    ],
                ],
                [
                    'key' => '_customs_description',
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => '_country_of_origin',
                    'compare' => 'EXISTS',
                ],
            ],
        ];

        $query = new WP_Query($args);
        $product_ids = $query->posts;

        if (empty($product_ids)) {
            $summary['messages'][] = 'No products found missing HS codes.';
            return $summary;
        }

        $summary['checked'] = count($product_ids);
        $summary['messages'][] = sprintf('Found %d products missing HS codes.', $summary['checked']);

        foreach ($product_ids as $product_id) {
            $desc = get_post_meta($product_id, '_customs_description', true);
            $origin = get_post_meta($product_id, '_country_of_origin', true);

            if (!$desc || !$origin) {
                continue;
            }

            $summary['missing_hs']++;

            // Look up profile by description + country
            $profile = WRD_DB::get_profile($desc, $origin);

            if (!$profile || empty($profile['hs_code'])) {
                $summary['messages'][] = sprintf(
                    'Product #%d: No profile found with HS code for "%s" (%s)',
                    $product_id,
                    mb_substr($desc, 0, 40),
                    $origin
                );
                continue;
            }

            $summary['matched']++;
            $hs_code = $profile['hs_code'];

            if (!$dry_run) {
                update_post_meta($product_id, '_hs_code', $hs_code);
                // Also update normalized meta
                $post_type = get_post_type($product_id);
                if ($post_type === 'product_variation') {
                    $this->update_normalized_meta_for_variation($product_id);
                } else {
                    $this->update_normalized_meta_for_product($product_id);
                }
                $summary['updated']++;
            } else {
                $summary['updated']++;
            }

            $summary['messages'][] = sprintf(
                'Product #%d: Matched to profile #%d - HS Code: %s',
                $product_id,
                $profile['id'],
                $hs_code
            );
        }

        if ($dry_run) {
            $summary['messages'][] = '';
            $summary['messages'][] = 'DRY RUN - No changes made. Uncheck "Dry run" to apply changes.';
        }

        return $summary;
    }

    private function handle_duplicate_cleanup(): array {
        $dry_run = !empty($_POST['cleanup_dry_run']);
        global $wpdb;
        $table = WRD_DB::table_profiles();

        $summary = [
            'duplicates_found' => 0,
            'merged' => 0,
            'products_reassigned' => 0,
            'profiles_deleted' => 0,
            'dry_run' => $dry_run,
            'messages' => [],
        ];

        // Find all profiles grouped by HS code + country code (only where HS code exists)
        $sql = "SELECT hs_code, country_code, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id ASC) as ids
                FROM {$table}
                WHERE hs_code IS NOT NULL AND hs_code != ''
                GROUP BY hs_code, country_code
                HAVING cnt > 1
                ORDER BY cnt DESC, hs_code ASC";

        $duplicates = $wpdb->get_results($sql, ARRAY_A);

        if (empty($duplicates)) {
            $summary['messages'][] = 'No duplicates found.';
            return $summary;
        }

        $summary['duplicates_found'] = count($duplicates);
        $summary['messages'][] = sprintf('Found %d groups of duplicate profiles.', count($duplicates));

        foreach ($duplicates as $dup_group) {
            $hs_code = $dup_group['hs_code'];
            $country = $dup_group['country_code'];
            $count = (int)$dup_group['cnt'];
            $ids = array_map('intval', explode(',', $dup_group['ids']));

            if (empty($ids) || count($ids) < 2) {
                continue;
            }

            // The canonical profile is the one with the lowest ID (oldest)
            $canonical_id = $ids[0];
            $duplicate_ids = array_slice($ids, 1);

            $summary['messages'][] = sprintf(
                'HS: %s, Country: %s - Merging %d duplicates into profile #%d',
                $hs_code,
                $country,
                count($duplicate_ids),
                $canonical_id
            );

            // Find all products currently assigned to duplicate profiles
            // Products are linked via _wrd_desc_norm + _wrd_origin_cc meta
            foreach ($duplicate_ids as $dup_id) {
                // Get the profile data
                $profile = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d",
                    $dup_id
                ), ARRAY_A);

                if (!$profile) {
                    continue;
                }

                $desc_norm = $profile['description_normalized'];

                // Find products using this description + country combination
                $product_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT pm1.post_id
                     FROM {$wpdb->postmeta} pm1
                     INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
                     INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
                     WHERE pm1.meta_key = '_wrd_desc_norm' AND pm1.meta_value = %s
                       AND pm2.meta_key = '_wrd_origin_cc' AND pm2.meta_value = %s
                       AND p.post_type IN ('product', 'product_variation')
                       AND p.post_status NOT IN ('trash', 'auto-draft')",
                    $desc_norm,
                    $country
                ));

                if (!empty($product_ids)) {
                    // Get the canonical profile's description
                    $canonical_profile = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$table} WHERE id = %d",
                        $canonical_id
                    ), ARRAY_A);

                    $canonical_desc_norm = $canonical_profile['description_normalized'];

                    $summary['messages'][] = sprintf(
                        '  → Found %d products with desc_norm "%s" and country "%s"',
                        count($product_ids),
                        $desc_norm,
                        $country
                    );

                    if (!$dry_run) {
                        // Update products to use canonical profile's normalized description
                        foreach ($product_ids as $product_id) {
                            update_post_meta($product_id, '_wrd_desc_norm', $canonical_desc_norm);
                            $summary['products_reassigned']++;
                        }
                    } else {
                        $summary['products_reassigned'] += count($product_ids);
                    }
                }

                // Delete the duplicate profile
                if (!$dry_run) {
                    $wpdb->delete($table, ['id' => $dup_id]);
                    $summary['profiles_deleted']++;
                } else {
                    $summary['profiles_deleted']++;
                }
            }

            $summary['merged']++;
        }

        if ($dry_run) {
            $summary['messages'][] = '';
            $summary['messages'][] = 'DRY RUN - No changes made. Uncheck "Dry run" to apply changes.';
        }

        return $summary;
    }

    private function render_profile_form(): void {
        global $wpdb; $table = WRD_DB::table_profiles();
        $is_edit = (isset($_GET['action']) && $_GET['action'] === 'edit');
        $row = null;
        if ($is_edit) {
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            // Support cloning via ?clone={id}
            if (!$id && isset($_GET['clone'])) { $id = (int) $_GET['clone']; }
            if ($id) { $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A); }
            if (!$row) { echo '<p>' . esc_html__('Profile not found.', 'wrd-us-duty') . '</p>'; return; }
            // If cloning, ensure ID is treated as new
            if (isset($_GET['clone'])) {
                $row['id'] = 0;
                $row['effective_from'] = date('Y-m-d');
                $row['effective_to'] = '';
                if (empty($row['notes'])) { $row['notes'] = 'Cloned on ' . date('Y-m-d'); }
            }
        }
        $vals = [
            'id' => $row['id'] ?? 0,
            'description_raw' => $row['description_raw'] ?? '',
            'country_code' => $row['country_code'] ?? '',
            'hs_code' => $row['hs_code'] ?? '',
            'fta_flags' => isset($row['fta_flags']) ? (is_string($row['fta_flags']) ? $row['fta_flags'] : wp_json_encode($row['fta_flags'])) : '[]',
            'us_duty_json' => isset($row['us_duty_json']) ? (is_string($row['us_duty_json']) ? $row['us_duty_json'] : wp_json_encode($row['us_duty_json'], JSON_PRETTY_PRINT)) : '{"postal":{"rates":{}},"commercial":{"rates":{}}}',
            'effective_from' => $row['effective_from'] ?? date('Y-m-d'),
            'effective_to' => $row['effective_to'] ?? '',
            'notes' => $row['notes'] ?? '',
        ];

        echo '<form method="post">';
        wp_nonce_field('wrd_save_profile', 'wrd_profile_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>Description</label></th><td><input type="text" name="description_raw" value="' . esc_attr($vals['description_raw']) . '" class="regular-text" required /></td></tr>';
        echo '<tr><th><label>Country Code</label></th><td><input type="text" name="country_code" value="' . esc_attr($vals['country_code']) . '" class="small-text" maxlength="2" required /></td></tr>';
        echo '<tr><th><label>HS Code</label></th><td><input type="text" name="hs_code" value="' . esc_attr($vals['hs_code']) . '" class="regular-text" /></td></tr>';
        // Simple mode inputs
        $fta_dec_prefill = json_decode($vals['fta_flags'], true);
        $fta_dec_prefill = is_array($fta_dec_prefill) ? $fta_dec_prefill : [];
        $udj_prefill = json_decode($vals['us_duty_json'], true);
        $postal_pref = '';
        $comm_pref = '';
        if (is_array($udj_prefill)) {
            $pr = $udj_prefill['postal']['rates'] ?? [];
            if (is_object($pr)) { $pr = (array)$pr; }
            $cr = $udj_prefill['commercial']['rates'] ?? [];
            if (is_object($cr)) { $cr = (array)$cr; }
            // Prefer key 'base' else first numeric
            $postal_pref = isset($pr['base']) && is_numeric($pr['base']) ? (string)$pr['base'] : (string)(reset($pr) !== false ? reset($pr) : '');
            $comm_pref = isset($cr['base']) && is_numeric($cr['base']) ? (string)$cr['base'] : (string)(reset($cr) !== false ? reset($cr) : '');
        }
        echo '<tr><th><label>Postal Duty Rate (%)</label></th><td><input type="number" step="0.0001" min="0" max="100" name="simple_postal_pct" value="' . esc_attr($postal_pref) . '" placeholder="e.g., 5.3" /> <span class="description">' . esc_html__('Enter as percentage (e.g., 5.3). Leave blank to manage in Advanced JSON.', 'wrd-us-duty') . '</span></td></tr>';
        echo '<tr><th><label>Commercial Duty Rate (%)</label></th><td><input type="number" step="0.0001" min="0" max="100" name="simple_commercial_pct" value="' . esc_attr($comm_pref) . '" placeholder="e.g., 7" /></td></tr>';
        $cusma_checked = in_array('CUSMA', $fta_dec_prefill, true) ? 'checked' : '';
        echo '<tr><th><label>CUSMA</label></th><td><label><input type="checkbox" name="simple_fta_cusma" value="1" ' . $cusma_checked . ' /> ' . esc_html__('Eligible for CUSMA (duty-free into US when applicable)', 'wrd-us-duty') . '</label></td></tr>';

        // Advanced JSON editor (collapsible)
        echo '<tr><th><label>Advanced JSON</label></th><td>';
        echo '<details><summary>' . esc_html__('Edit raw US Duty JSON and FTA Flags', 'wrd-us-duty') . '</summary>';
        echo '<p><label>FTA Flags (JSON array)</label><br/><textarea name="fta_flags" rows="2" class="large-text code">' . esc_textarea($vals['fta_flags']) . '</textarea></p>';
        echo '<p><label>US Duty JSON</label><br/><textarea name="us_duty_json" rows="8" class="large-text code">' . esc_textarea($vals['us_duty_json']) . '</textarea></p>';
        echo '<p class="description">' . esc_html__('If you modify JSON here, Simple rates will be ignored for those channels.', 'wrd-us-duty') . '</p>';
        echo '</details>';
        echo '</td></tr>';
        echo '<tr><th><label>Effective From</label></th><td><input type="date" name="effective_from" value="' . esc_attr($vals['effective_from']) . '" /></td></tr>';
        echo '<tr><th><label>Effective To</label></th><td><input type="date" name="effective_to" value="' . esc_attr($vals['effective_to']) . '" /></td></tr>';
        echo '<tr><th><label>Notes</label></th><td><textarea name="notes" rows="2" class="large-text">' . esc_textarea($vals['notes']) . '</textarea></td></tr>';
        if ($is_edit) { echo '<input type="hidden" name="id" value="' . (int)$vals['id'] . '" />'; }
        submit_button($is_edit ? __('Update', 'wrd-us-duty') : __('Create', 'wrd-us-duty'));
        echo '</tbody></table>';
        echo '</form>';
    }

    private function save_profile_from_post(): void {
        global $wpdb; $table = WRD_DB::table_profiles();
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        $description = isset($_POST['description_raw']) ? wp_kses_post(wp_unslash($_POST['description_raw'])) : '';
        $country = isset($_POST['country_code']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['country_code']))) : '';
        $hs = isset($_POST['hs_code']) ? sanitize_text_field(wp_unslash($_POST['hs_code'])) : '';
        $fta = isset($_POST['fta_flags']) ? wp_unslash($_POST['fta_flags']) : '[]';
        $udj = isset($_POST['us_duty_json']) ? wp_unslash($_POST['us_duty_json']) : '';
        $from = isset($_POST['effective_from']) ? sanitize_text_field(wp_unslash($_POST['effective_from'])) : date('Y-m-d');
        $to = isset($_POST['effective_to']) ? sanitize_text_field(wp_unslash($_POST['effective_to'])) : '';
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';
        $simple_postal = isset($_POST['simple_postal_pct']) && $_POST['simple_postal_pct'] !== '' ? (float) wp_unslash($_POST['simple_postal_pct']) : null;
        $simple_commercial = isset($_POST['simple_commercial_pct']) && $_POST['simple_commercial_pct'] !== '' ? (float) wp_unslash($_POST['simple_commercial_pct']) : null;
        $simple_cusma = !empty($_POST['simple_fta_cusma']);

        // Validate JSON columns
        $fta_dec = json_decode($fta, true);
        if ($fta_dec === null && json_last_error() !== JSON_ERROR_NONE) {
            $fta_dec = [];
        }
        $udj_dec = null;
        if ($udj !== '') {
            $udj_dec = json_decode($udj, true);
            if (!is_array($udj_dec) || (json_last_error() !== JSON_ERROR_NONE && $udj_dec === null)) {
                $udj_dec = null;
            }
        }

        // Ensure base structure for JSON
        if (!is_array($udj_dec)) {
            $udj_dec = [
                'postal' => ['rates' => new stdClass()],
                'commercial' => ['rates' => new stdClass()],
            ];
        }

        // Normalise nested structures
        if (!isset($udj_dec['postal']) || !is_array($udj_dec['postal'])) {
            $udj_dec['postal'] = ['rates' => new stdClass()];
        }
        if (!isset($udj_dec['postal']['rates']) || !is_array($udj_dec['postal']['rates'])) {
            $udj_dec['postal']['rates'] = [];
        }
        if (!isset($udj_dec['commercial']) || !is_array($udj_dec['commercial'])) {
            $udj_dec['commercial'] = ['rates' => new stdClass()];
        }
        if (!isset($udj_dec['commercial']['rates']) || !is_array($udj_dec['commercial']['rates'])) {
            $udj_dec['commercial']['rates'] = [];
        }

        // Apply simple-mode overrides when present
        if ($simple_postal !== null) {
            $udj_dec['postal']['rates']['base'] = (float) $simple_postal;
        }
        if ($simple_commercial !== null) {
            $udj_dec['commercial']['rates']['base'] = (float) $simple_commercial;
        }
        if ($simple_cusma && !in_array('CUSMA', $fta_dec, true)) { $fta_dec[] = 'CUSMA'; }

        $descNorm = WRD_DB::normalize_description($description);

        $data = [
            'description_raw' => $description,
            'description_normalized' => $descNorm,
            'country_code' => $country,
            'hs_code' => $hs,
            'us_duty_json' => wp_json_encode($udj_dec, JSON_UNESCAPED_SLASHES),
            'fta_flags' => wp_json_encode($fta_dec),
            'effective_from' => $from,
            'effective_to' => $to ?: null,
            'notes' => $notes,
        ];

        if ($id) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $wpdb->insert($table, $data);
        }
    }

    private function export_csv(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wrd_customs_profiles_' . date('Ymd_His') . '.csv');

        $fh = fopen('php://output', 'w');
        fputcsv($fh, ['description','country_code','hs_code','fta_flags','us_duty_json','effective_from','effective_to','notes']);

        global $wpdb; $table = WRD_DB::table_profiles();
        $where = '1=1'; $params = [];
        if (!empty($_GET['s'])) {
            $s = sanitize_text_field(wp_unslash($_GET['s']));
            $where .= ' AND (description_raw LIKE %s OR country_code LIKE %s OR hs_code LIKE %s)';
            $like = '%' . $wpdb->esc_like($s) . '%';
            $params = [$like, $like, $like];
        }
        $sql = $params ? $wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC", $params)
                       : "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as $r) {
            $fta = is_string($r['fta_flags']) ? $r['fta_flags'] : wp_json_encode($r['fta_flags']);
            $udj = is_string($r['us_duty_json']) ? $r['us_duty_json'] : wp_json_encode($r['us_duty_json'], JSON_UNESCAPED_SLASHES);
            fputcsv($fh, [
                $r['description_raw'],
                $r['country_code'],
                $r['hs_code'],
                $fta,
                $udj,
                $r['effective_from'],
                $r['effective_to'],
                $r['notes'],
            ]);
        }
        fclose($fh);
        exit;
    }

    private function export_products_csv(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        // Stream CSV of all products and variations with HS code and postal/commercial duty rates
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=wrd_products_duties_' . date('Ymd_His') . '.csv');

        $fh = fopen('php://output', 'w');
        // Columns: product id, parent id, type, sku, name, variation attributes, customs desc, origin, hs code, postal %, commercial %
        fputcsv($fh, ['product_id','parent_id','type','sku','name','variation','customs_description','country_of_origin','hs_code','postal_rate_pct','commercial_rate_pct']);

        $paged = 1;
        $per_page = 300;
        do {
            $q = new WP_Query([
                'post_type' => ['product','product_variation'],
                'post_status' => ['publish','draft','pending','private'],
                'fields' => 'ids',
                'posts_per_page' => $per_page,
                'paged' => $paged,
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
            ]);
            if (!$q->have_posts()) { break; }
            foreach ($q->posts as $pid) {
                $product = wc_get_product($pid);
                if (!$product) { continue; }
                $type = $product->get_type();
                $parent_id = $product->is_type('variation') ? (int)$product->get_parent_id() : 0;
                $sku = (string)$product->get_sku();
                $name = (string)$product->get_name();
                // Resolve customs description and origin with inheritance for variations
                $desc = (string)$product->get_meta('_customs_description', true);
                $origin = strtoupper((string)$product->get_meta('_country_of_origin', true));
                if ($product->is_type('variation') && $parent_id) {
                    if ($desc === '') { $desc = (string) get_post_meta($parent_id, '_customs_description', true); }
                    if ($origin === '') { $origin = strtoupper((string) get_post_meta($parent_id, '_country_of_origin', true)); }
                }
                $desc = trim((string)$desc);
                $origin = strtoupper(trim((string)$origin));

                // Variation attributes printable
                $variation = '';
                if ($product->is_type('variation')) {
                    $attrs = $product->get_attributes(); // e.g., [ 'attribute_pa_color' => 'red' ]
                    if (is_array($attrs) && !empty($attrs)) {
                        $parts = [];
                        foreach ($attrs as $k => $v) {
                            $label = is_string($k) ? preg_replace('/^attribute_/', '', $k) : (string)$k;
                            $val = is_array($v) ? implode('|', array_map('strval', $v)) : (string)$v;
                            $parts[] = $label . '=' . $val;
                        }
                        $variation = implode(';', $parts);
                    }
                }

                $hs = '';
                $postalPct = '';
                $commercialPct = '';
                if ($desc !== '' && $origin !== '') {
                    $profile = WRD_DB::get_profile($desc, $origin);
                    if ($profile) {
                        $hs = (string)($profile['hs_code'] ?? '');
                        $udj = is_array($profile['us_duty_json']) ? $profile['us_duty_json'] : json_decode((string)$profile['us_duty_json'], true);
                        if (is_array($udj)) {
                            $postalPct = round(WRD_Duty_Engine::compute_rate_percent($udj, 'postal'), 4);
                            $commercialPct = round(WRD_Duty_Engine::compute_rate_percent($udj, 'commercial'), 4);
                        }
                    }
                }

                fputcsv($fh, [
                    (int)$pid,
                    (int)$parent_id,
                    $type,
                    $sku,
                    $name,
                    $variation,
                    $desc,
                    $origin,
                    $hs,
                    $postalPct,
                    $commercialPct,
                ]);
            }
            $paged++;
            wp_reset_postdata();
        } while (true);

        fclose($fh);
        exit;
    }

    private function handle_products_csv_import(): ?array {
        if (empty($_FILES['wrd_products_csv']) || !is_uploaded_file($_FILES['wrd_products_csv']['tmp_name'])) { return null; }
        $fh = fopen($_FILES['wrd_products_csv']['tmp_name'], 'r');
        if (!$fh) { return null; }
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return null; }
        $header_lc = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);

        $map_identifier = strtolower(trim((string)($_POST['map_identifier'] ?? '')));
        $map_hs = strtolower(trim((string)($_POST['map_hs'] ?? '')));
        $map_desc = strtolower(trim((string)($_POST['map_desc'] ?? '')));
        $map_cc = strtolower(trim((string)($_POST['map_cc'] ?? '')));
        $identifier_type = strtolower(trim((string)($_POST['identifier_type'] ?? 'auto')));
        $dry_run = !empty($_POST['dry_run']);

        // Autodetect common names if not provided
        $auto_idx = function(array $cands) use ($header_lc) {
            foreach ($cands as $cand) {
                $i = array_search($cand, $header_lc, true);
                if ($i !== false) { return $i; }
            }
            return -1;
        };

        $idx_ident = $map_identifier !== '' ? array_search($map_identifier, $header_lc, true) : $auto_idx(['product_id','id','sku','product_sku']);
        $idx_hs = $map_hs !== '' ? array_search($map_hs, $header_lc, true) : $auto_idx(['hs_code','hs','hscode','tariff_code']);
        $idx_desc = $map_desc !== '' ? array_search($map_desc, $header_lc, true) : $auto_idx(['customs_description','description','customs_desc']);
        $idx_cc = $map_cc !== '' ? array_search($map_cc, $header_lc, true) : $auto_idx(['country_code','origin','country','cc']);

        $messages = [];
        if ($idx_ident === -1) { $messages[] = 'Identifier header not found.'; }
        // HS code OR description must be present (not both required)
        if ($idx_hs === -1 && $idx_desc === -1) { $messages[] = 'Neither HS code nor customs description header found.'; }
        if ($idx_cc === -1) { $messages[] = 'Country code header not found.'; }
        if ($idx_ident === -1 || ($idx_hs === -1 && $idx_desc === -1) || $idx_cc === -1) {
            fclose($fh);
            return ['rows'=>0,'matched'=>0,'updated'=>0,'skipped'=>0,'errors'=>1,'messages'=>$messages];
        }

        $rows = $matched = $updated = $skipped = $errors = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $rows++;
            $ident = isset($row[$idx_ident]) ? trim((string)$row[$idx_ident]) : '';
            $hs_code = ($idx_hs !== -1 && isset($row[$idx_hs])) ? trim((string)$row[$idx_hs]) : '';
            $desc = ($idx_desc !== -1 && isset($row[$idx_desc])) ? trim((string)$row[$idx_desc]) : '';
            $cc = strtoupper(isset($row[$idx_cc]) ? trim((string)$row[$idx_cc]) : '');
            if ($ident === '' || ($hs_code === '' && $desc === '') || $cc === '') {
                $skipped++;
                $messages[] = "Row {$rows}: missing field(s)";
                continue;
            }

            // Resolve product id
            $pid = 0;
            $type = $identifier_type;
            if ($type === 'auto') {
                if (ctype_digit($ident)) { $type = 'id'; }
                else { $type = 'sku'; }
            }
            if ($type === 'id') {
                $pid = (int)$ident;
                if (!get_post($pid)) { $pid = 0; }
            } else {
                if (function_exists('wc_get_product_id_by_sku')) {
                    $pid = (int) wc_get_product_id_by_sku($ident);
                }
            }
            if ($pid <= 0) { $skipped++; $messages[] = "Row {$rows}: product not found for identifier '{$ident}'"; continue; }

            $matched++;
            if ($dry_run) { continue; }

            $product = wc_get_product($pid);
            if (!$product) { $skipped++; $messages[] = "Row {$rows}: product {$pid} could not be loaded"; continue; }

            // Update product meta
            if ($hs_code !== '') {
                $product->update_meta_data('_hs_code', $hs_code);
            }
            if ($desc !== '') {
                $product->update_meta_data('_customs_description', wp_kses_post($desc));
            }
            $product->update_meta_data('_country_of_origin', $cc);
            $product->save();

            // Update normalized meta correctly for product vs variation
            if ($product->is_type('variation')) {
                $this->update_normalized_meta_for_variation((int) $product->get_id());
            } else {
                $this->update_normalized_meta_for_product((int) $product->get_id());
            }
            $updated++;
        }
        fclose($fh);

        return compact('rows','matched','updated','skipped','errors') + ['messages' => $messages];
    }

    // --- Normalized meta helpers and impacted products UI ---

    public function update_normalized_meta_on_save($post_id, $post, $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
        if ($post->post_type === 'product') {
            $this->update_normalized_meta_for_product((int)$post_id);
        } elseif ($post->post_type === 'product_variation') {
            $this->update_normalized_meta_for_variation((int)$post_id);
        }
    }

    private function update_normalized_meta_for_product(int $product_id): void {
        // Get HS code and origin
        $hs_code = trim((string)get_post_meta($product_id, '_hs_code', true));
        $origin = strtoupper((string)get_post_meta($product_id, '_country_of_origin', true));

        // Legacy: also handle _customs_description for backward compatibility
        $desc = get_post_meta($product_id, '_customs_description', true);
        $descNorm = $desc ? WRD_DB::normalize_description($desc) : '';

        // Store normalized values for quick lookup
        update_post_meta($product_id, '_wrd_hs_code', $hs_code);
        update_post_meta($product_id, '_wrd_origin_cc', $origin);
        update_post_meta($product_id, '_wrd_desc_norm', $descNorm); // Keep for legacy support

        // If product has HS + country but no description, pull from profile
        if ($hs_code && $origin && !$desc) {
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            if ($profile && isset($profile['description_raw'])) {
                update_post_meta($product_id, '_customs_description', $profile['description_raw']);
            }
        }

        // Update variations that inherit
        $children = get_children([
            'post_parent' => $product_id,
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'fields' => 'ids',
        ]);
        foreach ($children as $vid) {
            $vhs = get_post_meta($vid, '_hs_code', true);
            $vorigin = strtoupper((string)get_post_meta($vid, '_country_of_origin', true));
            $vdesc = get_post_meta($vid, '_customs_description', true);
            if ($vhs === '' || $vorigin === '' || $vdesc === '') {
                $this->update_normalized_meta_for_variation((int)$vid);
            }
        }
    }

    private function update_normalized_meta_for_variation(int $variation_id): void {
        $parent_id = (int) get_post_field('post_parent', $variation_id);

        // Get values from variation, inherit from parent if not set
        $hs_code = trim((string)get_post_meta($variation_id, '_hs_code', true));
        $origin = strtoupper((string)get_post_meta($variation_id, '_country_of_origin', true));
        $desc = get_post_meta($variation_id, '_customs_description', true);

        if ($parent_id) {
            if ($hs_code === '') {
                $hs_code = trim((string)get_post_meta($parent_id, '_hs_code', true));
            }
            if ($origin === '') {
                $origin = strtoupper((string)get_post_meta($parent_id, '_country_of_origin', true));
            }
            if ($desc === '') {
                $desc = get_post_meta($parent_id, '_customs_description', true);
            }
        }

        $descNorm = $desc ? WRD_DB::normalize_description($desc) : '';

        // Store normalized values
        update_post_meta($variation_id, '_wrd_hs_code', $hs_code);
        update_post_meta($variation_id, '_wrd_origin_cc', $origin);
        update_post_meta($variation_id, '_wrd_desc_norm', $descNorm); // Keep for legacy support

        // If variation has HS + country but no description, pull from profile
        if ($hs_code && $origin && !$desc) {
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            if ($profile && isset($profile['description_raw'])) {
                update_post_meta($variation_id, '_customs_description', $profile['description_raw']);
            }
        }
    }

    private function reindex_products(int $max): int {
        $processed = 0;
        $per_page = 200;
        $paged = 1;
        while ($processed < $max) {
            $left = $max - $processed;
            $pp = min($per_page, $left);
            $q = new WP_Query([
                'post_type' => ['product','product_variation'],
                'post_status' => ['publish','draft','pending','private'],
                'fields' => 'ids',
                'posts_per_page' => $pp,
                'paged' => $paged,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            if (!$q->have_posts()) { break; }
            foreach ($q->posts as $pid) {
                $post_type = get_post_type($pid);
                if ($post_type === 'product') {
                    $this->update_normalized_meta_for_product((int)$pid);
                } else {
                    $this->update_normalized_meta_for_variation((int)$pid);
                }
                $processed++;
                if ($processed >= $max) { break 2; }
            }
            $paged++;
        }
        wp_reset_postdata();
        return $processed;
    }

    private function render_impacted_products_page(): void {
        $hsCode = isset($_GET['hs_code']) ? sanitize_text_field(wp_unslash($_GET['hs_code'])) : '';
        $cc = isset($_GET['cc']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['cc']))) : '';
        if ($hsCode === '' || $cc === '') { echo '<p>' . esc_html__('Missing filter parameters.', 'wrd-us-duty') . '</p>'; return; }

        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-impacted-products-table.php';
        $table = new WRD_Impacted_Products_Table($hsCode, $cc);
        // Handle bulk actions only on POST to avoid conflicting with page query arg 'action=impacted'
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $table->process_bulk_action();
        }
        $table->prepare_items();
        echo '<h2>' . esc_html(sprintf(__('Impacted Products — %s (%s)', 'wrd-us-duty'), $hsCode, $cc)) . '</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="page" value="wrd-customs" />';
        echo '<input type="hidden" name="tab" value="profiles" />';
        echo '<input type="hidden" name="hs_code" value="' . esc_attr($hsCode) . '" />';
        echo '<input type="hidden" name="cc" value="' . esc_attr($cc) . '" />';
        $table->search_box(__('Search products', 'wrd-us-duty'), 'wrd_impacted');
        $table->display();
        echo '</form>';
    }

    private function render_tab_reconciliation(): void {
        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-reconciliation-table.php';
        echo '<h2>' . esc_html__('Product Reconciliation', 'woocommerce-us-duties') . '</h2>';
        echo '<p class="description">' . esc_html__('Find products missing classification or without a matching duty profile. Assign an existing group or create a new one.', 'woocommerce-us-duties') . '</p>';
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'any_missing';
        $table = new WRD_Reconciliation_Table($status);
        echo '<form method="get">';
        foreach (['page'=>'wrd-customs','tab'=>'reconcile'] as $k=>$v) { echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '" />'; }
        // Filters
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="wrd-status">' . esc_html__('Filter status') . '</label>';
        echo '<select name="status" id="wrd-status">';
        $opts = [
            'any_missing' => __('Any missing', 'woocommerce-us-duties'),
            'missing_desc' => __('Missing description', 'woocommerce-us-duties'),
            'missing_origin' => __('Missing origin', 'woocommerce-us-duties'),
            'no_profile' => __('No matching profile', 'woocommerce-us-duties'),
        ];
        foreach ($opts as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($status, $key, false), esc_html($label));
        }
        echo '</select> ';
        submit_button(__('Filter'), 'secondary', '', false);
        echo '</div>';
        echo '<br class="clear" />';
        echo '</div>';
        $table->prepare_items();
        $table->display();
        echo '</form>';
    }
}
