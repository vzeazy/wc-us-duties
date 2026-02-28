<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Admin {
    private const PRODUCT_CATALOG_MODE_QUERY_VAR = 'wrd_catalog_mode';
    private const PRODUCT_CATALOG_MODE_HS_MANAGER = 'hs_manager';
    private const PRODUCT_CUSTOMS_VIEW_QUERY_VAR = 'wrd_customs_view';
    private const PRODUCT_CUSTOMS_FILTER_QUERY_VAR = 'wrd_customs_status';
    private const PRODUCT_CUSTOMS_COUNT_CACHE_KEY = 'wrd_product_customs_view_counts_v1';
    private const PRODUCT_CUSTOMS_STATUSES = [
        'needs_hs',
        'needs_origin',
        'missing_profile',
        'legacy',
        'ready',
    ];

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
        add_filter('manage_edit-product_sortable_columns', [$this, 'add_product_customs_sortable_column']);
        add_filter('views_edit-product', [$this, 'filter_product_views']);
        add_action('restrict_manage_posts', [$this, 'render_product_status_filter']);
        add_action('pre_get_posts', [$this, 'apply_product_status_filters']);
        add_action('admin_notices', [$this, 'render_hs_manager_catalog_notice']);
        add_action('admin_head-edit.php', [$this, 'render_hs_manager_catalog_styles']);
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
        add_action('wp_ajax_wrd_reconcile_assign_bulk', [$this, 'ajax_reconcile_assign_bulk']);
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

        woocommerce_wp_text_input([
            'id' => '_wrd_232_metal_value_usd',
            'label' => __('Section 232 Metal Value (USD)', 'woocommerce-us-duties'),
            'desc_tip' => true,
            'description' => __('Used only for duty components with product_metal_value_usd basis (such as section_232). Enter per-unit USD value.', 'woocommerce-us-duties'),
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
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
        self::upsert_product_classification((int) $product->get_id(), [
            'hs_code' => isset($_POST['_hs_code']) ? wp_unslash($_POST['_hs_code']) : '',
            'origin' => isset($_POST['_country_of_origin']) ? wp_unslash($_POST['_country_of_origin']) : '',
            'metal_value_232' => isset($_POST['_wrd_232_metal_value_usd']) ? wp_unslash($_POST['_wrd_232_metal_value_usd']) : '',
        ]);
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

        $mode = (string) get_post_meta($variation->ID, '_wrd_232_basis_mode', true);
        if (!in_array($mode, ['inherit', 'explicit', 'none'], true)) {
            $mode = 'inherit';
        }
        woocommerce_wp_select([
            'id' => "_wrd_232_basis_mode[{$loop}]",
            'label' => __('Section 232 Basis', 'woocommerce-us-duties'),
            'value' => $mode,
            'options' => [
                'inherit' => __('Inherit parent metal value', 'woocommerce-us-duties'),
                'explicit' => __('Use variation metal value', 'woocommerce-us-duties'),
                'none' => __('No Section 232 basis value', 'woocommerce-us-duties'),
            ],
            'wrapper_class' => 'form-row form-row-first',
        ]);

        woocommerce_wp_text_input([
            'id' => "_wrd_232_metal_value_usd[{$loop}]",
            'label' => __('Section 232 Metal Value (USD)', 'woocommerce-us-duties'),
            'value' => get_post_meta($variation->ID, '_wrd_232_metal_value_usd', true),
            'wrapper_class' => 'form-row form-row-last',
            'type' => 'number',
            'custom_attributes' => ['step' => '0.01', 'min' => '0'],
        ]);

        echo '</div>';
    }

    public function save_variation_fields($variation_id, $i): void {
        $changes = [];
        if (isset($_POST['_hs_code'][$i])) {
            $changes['hs_code'] = wp_unslash($_POST['_hs_code'][$i]);
        }
        if (isset($_POST['_country_of_origin'][$i])) {
            $changes['origin'] = wp_unslash($_POST['_country_of_origin'][$i]);
        }
        if (isset($_POST['_wrd_232_basis_mode'][$i])) {
            $changes['metal_mode_232'] = wp_unslash($_POST['_wrd_232_basis_mode'][$i]);
        }
        if (isset($_POST['_wrd_232_metal_value_usd'][$i])) {
            $changes['metal_value_232'] = wp_unslash($_POST['_wrd_232_metal_value_usd'][$i]);
        }
        if ($changes) {
            self::upsert_product_classification((int) $variation_id, $changes);
        }
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
            echo '<p style="margin: 0;"><strong>' . esc_html__('Checkout Currency:', 'woocommerce-us-duties') . '</strong> ' . esc_html($snapshot['currency']) . '</p>';
        }
        echo '<p style="margin: 8px 0 0 0;"><strong>' . esc_html__('Duty Calculation Currency:', 'woocommerce-us-duties') . '</strong> USD</p>';
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
            $value = isset($line['value_usd']) ? wc_price((float) $line['value_usd'], ['currency' => 'USD']) : '-';
            $duty = isset($line['duty_usd']) ? wc_price((float) $line['duty_usd'], ['currency' => 'USD']) : '-';
            $cusma = !empty($line['cusma']) ? '✓' : '-';

            echo '<tr>';
            echo '<td style="padding: 8px;">' . esc_html($product_name) . '</td>';
            echo '<td style="padding: 8px;">' . esc_html($hs_code) . '</td>';
            echo '<td style="padding: 8px;">' . esc_html($origin) . '</td>';
            echo '<td style="padding: 8px;">' . esc_html(ucfirst($channel)) . '</td>';
            echo '<td style="padding: 8px; text-align: right;">' . esc_html($rate) . '</td>';
            echo '<td style="padding: 8px; text-align: right;">' . wp_kses_post($value) . '</td>';
            echo '<td style="padding: 8px; text-align: right;">' . wp_kses_post($duty) . '</td>';
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
            echo '<strong>' . wp_kses_post(wc_price((float)($comp['cusma_value_usd'] ?? 0), ['currency' => 'USD'])) . '</strong>';
            echo '</div>';
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('Dutiable Value:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>' . wp_kses_post(wc_price((float)($comp['non_cusma_value_usd'] ?? 0), ['currency' => 'USD'])) . '</strong>';
            echo '</div>';
            echo '<hr style="margin: 8px 0; border: none; border-top: 1px solid #dcdcde;">';
        }

        if (isset($snapshot['total_usd'])) {
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('Total Duties:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>' . wp_kses_post(wc_price((float)$snapshot['total_usd'], ['currency' => 'USD'])) . '</strong>';
            echo '</div>';
        }

        if (isset($snapshot['fees']) && !empty($snapshot['fees'])) {
            foreach ($snapshot['fees'] as $fee) {
                echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
                echo '<span>' . esc_html($fee['label']) . ':</span>';
                echo '<strong>' . wp_kses_post(wc_price((float)$fee['amount_usd'], ['currency' => 'USD'])) . '</strong>';
                echo '</div>';
            }
        }

        if (isset($snapshot['fees_usd'])) {
            echo '<div style="display: flex; justify-content: space-between; margin-bottom: 6px;">';
            echo '<span>' . esc_html__('Total Fees:', 'woocommerce-us-duties') . '</span>';
            echo '<strong>' . wp_kses_post(wc_price((float)$snapshot['fees_usd'], ['currency' => 'USD'])) . '</strong>';
            echo '</div>';
        }

        $grand_total = ($snapshot['total_usd'] ?? 0) + ($snapshot['fees_usd'] ?? 0);
        echo '<hr style="margin: 8px 0; border: none; border-top: 1px solid #dcdcde;">';
        echo '<div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 1.1em;">';
        echo '<strong>' . esc_html__('Grand Total (USD):', 'woocommerce-us-duties') . '</strong>';
        echo '<strong style="color: #2271b1;">' . wp_kses_post(wc_price((float)$grand_total, ['currency' => 'USD'])) . '</strong>';
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
            __('Customs & Duties', 'woocommerce-us-duties'),
            __('Customs & Duties', 'woocommerce-us-duties'),
            'manage_woocommerce',
            'wrd-customs',
            [$this, 'render_customs_hub']
        );
    }
    
    public function render_duty_manager(): void {
        wp_safe_redirect(add_query_arg([
            'post_type' => 'product',
            self::PRODUCT_CATALOG_MODE_QUERY_VAR => self::PRODUCT_CATALOG_MODE_HS_MANAGER,
        ], admin_url('edit.php')));
        exit;
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
        if (!empty($_POST['wrd_curation_export_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_curation_export_nonce'])), 'wrd_curation_export')) {
            $this->export_description_curation_package();
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Customs & Duties', 'woocommerce-us-duties') . '</h1>';
        $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'profiles';
        if ($active === '232') {
            $active = 'section_232';
        }
        $tabs = [
            'profiles' => __('Profiles', 'woocommerce-us-duties'),
            'reconcile' => __('Reconciliation', 'woocommerce-us-duties'),
            'section_232' => __('Section 232', 'woocommerce-us-duties'),
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
        } elseif ($active === 'section_232') {
            $this->render_tab_232();
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
                echo '<div class="updated"><p>' . esc_html__('Profile saved.', 'woocommerce-us-duties') . '</p></div>';
            }
            $this->render_profile_form();
            return;
        }
        $newUrl = add_query_arg(['page' => 'wrd-customs', 'tab' => 'profiles', 'action' => 'new'], admin_url('admin.php'));
        $exportUrl = add_query_arg(['page' => 'wrd-customs', 'tab' => 'import', 'action' => 'export'] + array_intersect_key($_GET, ['s' => true]), admin_url('admin.php'));

        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-profiles-table.php';
        $table = new WRD_Profiles_Table();
        $table->process_bulk_action();
        echo '<style>
            .wrd-profiles-controlbar {
                margin: 12px 0 10px;
                padding: 10px 12px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                background: #fff;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
            }
            .wrd-profiles-controlbar .wrd-profiles-actions {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
            }
            .wrd-profiles-controlbar .search-box {
                float: none;
                margin: 0;
                padding: 0;
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .wrd-profiles-controlbar .search-box input[type="search"] {
                margin: 0;
            }
            .wrd-profiles-controlbar .search-box input[type="submit"] {
                margin: 0;
            }
            #wrd-profiles-form .tablenav.top {
                margin-top: 0;
            }
            #wrd-profiles-form .column-active {
                width: 170px;
            }
            #wrd-profiles-form .column-notes {
                width: 28%;
            }
            #wrd-profiles-form .wrd-status-pill {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 11px;
                font-weight: 600;
                border: 1px solid;
                line-height: 1.4;
                white-space: nowrap;
            }
            #wrd-profiles-form .wrd-status-pill--active {
                background: #edf7ed;
                color: #0f5132;
                border-color: #b7dfc0;
            }
            #wrd-profiles-form .wrd-status-pill--scheduled {
                background: #edf6ff;
                color: #0a4b78;
                border-color: #b8dcf5;
            }
            #wrd-profiles-form .wrd-status-pill--expired {
                background: #fff1f0;
                color: #b42318;
                border-color: #ffc9c5;
            }
            #wrd-profiles-form .wrd-cell-meta {
                display: block;
                margin-top: 4px;
                font-size: 11px;
                color: #646970;
                line-height: 1.3;
            }
            #wrd-profiles-form .wrd-cell-empty {
                color: #8c8f94;
            }
            #wrd-profiles-form .wrd-notes-snippet {
                display: block;
                color: #1d2327;
                line-height: 1.4;
            }
        </style>';
        echo '<form method="post" id="wrd-profiles-form" data-wrd-bulk-form="1">';
        echo '<input type="hidden" name="page" value="wrd-customs" />';
        echo '<input type="hidden" name="tab" value="profiles" />';
        $table->prepare_items();
        echo '<div class="wrd-profiles-controlbar">';
        echo '<div class="wrd-profiles-actions">';
        echo '<a href="' . esc_url($newUrl) . '" class="button button-primary">' . esc_html__('Add New', 'woocommerce-us-duties') . '</a>';
        echo '<a href="' . esc_url($exportUrl) . '" class="button">' . esc_html__('Export CSV', 'woocommerce-us-duties') . '</a>';
        echo '</div>';
        $table->search_box(__('Search Profiles', 'woocommerce-us-duties'), 'wrd_profiles');
        echo '</div>';
        // WooCommerce-style bulk edit panel
        $this->render_bulk_edit_panel();
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
                'select_action' => __('Please select at least one bulk action to perform.', 'woocommerce-us-duties'),
                'enter_date' => __('Please enter a date for the selected action.', 'woocommerce-us-duties'),
                'enter_rate' => __('Please enter a rate for the selected action.', 'woocommerce-us-duties'),
                'invalid_rate' => __('Please enter a valid rate between 0 and 100.', 'woocommerce-us-duties'),
                'enter_notes' => __('Please enter notes text for the selected action.', 'woocommerce-us-duties'),
            ]
        ]);
    }

    private function render_bulk_edit_panel(): void {
        ?>
        <div id="wrd-bulk-edit" class="tablenav" style="display: none;">
            <div class="alignleft actions bulkactions">
                <div class="bulk-edit-fields">
                    <fieldset class="inline-edit-col-left">
                        <legend class="inline-edit-legend"><?php esc_html_e('Bulk Edit Profiles', 'woocommerce-us-duties'); ?></legend>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('Effective From', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_effective_from_action" class="bulk-date-action wrd-bulk-action-select">
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
                                    <select name="bulk_effective_to_action" class="bulk-date-action wrd-bulk-action-select">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="set"><?php esc_html_e('Set to:', 'woocommerce-us-duties'); ?></option>
                                        <option value="clear"><?php esc_html_e('Clear (never expires)', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                    <input type="date" name="bulk_effective_to" class="bulk-date-input" style="display: none;" />
                                </span>
                            </label>
                        </div>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('Postal Duty Rate (%)', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_postal_rate_action" class="bulk-rate-action wrd-bulk-action-select">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="set"><?php esc_html_e('Set to:', 'woocommerce-us-duties'); ?></option>
                                        <option value="clear"><?php esc_html_e('Clear base rate', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                    <input type="number" min="0" max="100" step="0.0001" name="bulk_postal_rate" class="bulk-rate-input" style="display: none;" />
                                </span>
                            </label>
                        </div>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('Commercial Duty Rate (%)', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_commercial_rate_action" class="bulk-rate-action wrd-bulk-action-select">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="set"><?php esc_html_e('Set to:', 'woocommerce-us-duties'); ?></option>
                                        <option value="clear"><?php esc_html_e('Clear base rate', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                    <input type="number" min="0" max="100" step="0.0001" name="bulk_commercial_rate" class="bulk-rate-input" style="display: none;" />
                                </span>
                            </label>
                        </div>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('CUSMA', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_cusma_action" class="wrd-bulk-action-select">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="enable"><?php esc_html_e('Enable', 'woocommerce-us-duties'); ?></option>
                                        <option value="disable"><?php esc_html_e('Disable', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                </span>
                            </label>
                        </div>
                        <div class="inline-edit-col">
                            <label class="alignleft">
                                <span class="title"><?php esc_html_e('Notes', 'woocommerce-us-duties'); ?></span>
                                <span class="input-text-wrap">
                                    <select name="bulk_notes_action" class="bulk-notes-action wrd-bulk-action-select">
                                        <option value=""><?php esc_html_e('— No change —', 'woocommerce-us-duties'); ?></option>
                                        <option value="replace"><?php esc_html_e('Replace with:', 'woocommerce-us-duties'); ?></option>
                                        <option value="append"><?php esc_html_e('Append:', 'woocommerce-us-duties'); ?></option>
                                        <option value="clear"><?php esc_html_e('Clear', 'woocommerce-us-duties'); ?></option>
                                    </select>
                                    <textarea name="bulk_notes" class="bulk-notes-input" rows="2" style="display: none; width: 100%;"></textarea>
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
        $notices = [];

        if (!empty($_POST['wrd_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_import_nonce'])), 'wrd_import_csv')) {
            $summary = $this->handle_csv_import();
            if (is_array($summary)) {
                $notices[] = [
                    'class' => 'notice notice-info',
                    'message' => sprintf(
                        __('Profiles CSV: %1$d rows, %2$d inserted, %3$d updated, %4$d skipped, %5$d errors. (dry-run: %6$s)', 'woocommerce-us-duties'),
                        (int) ($summary['rows'] ?? 0),
                        (int) ($summary['inserted'] ?? 0),
                        (int) ($summary['updated'] ?? 0),
                        (int) ($summary['skipped'] ?? 0),
                        (int) ($summary['errors'] ?? 0),
                        !empty($summary['dry_run']) ? 'yes' : 'no'
                    ),
                    'details' => !empty($summary['messages']) ? implode("\n", array_slice((array) $summary['messages'], 0, 50)) : '',
                ];
            } else {
                $notices[] = [
                    'class' => 'notice notice-success',
                    'message' => __('Profiles CSV import completed.', 'woocommerce-us-duties'),
                    'details' => '',
                ];
            }
        }

        if (!empty($_POST['wrd_import_json_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_import_json_nonce'])), 'wrd_import_json')) {
            $json_summary = $this->handle_duty_json_import();
            if (is_array($json_summary)) {
                $notices[] = [
                    'class' => 'notice notice-info',
                    'message' => sprintf(
                        /* translators: 1: inserted count, 2: updated count, 3: skipped count, 4: errors count */
                        __('JSON Import: %1$d inserted, %2$d updated, %3$d skipped, %4$d errors.', 'woocommerce-us-duties'),
                        (int) ($json_summary['inserted'] ?? 0),
                        (int) ($json_summary['updated'] ?? 0),
                        (int) ($json_summary['skipped'] ?? 0),
                        (int) ($json_summary['errors'] ?? 0)
                    ),
                    'details' => !empty($json_summary['error_messages']) ? implode("\n", array_slice((array) $json_summary['error_messages'], 0, 50)) : '',
                ];
            }
        }

        if (!empty($_POST['wrd_cleanup_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_cleanup_nonce'])), 'wrd_cleanup_duplicates')) {
            $cleanup_summary = $this->handle_duplicate_cleanup();
            if (is_array($cleanup_summary)) {
                $notices[] = [
                    'class' => 'notice notice-success',
                    'message' => sprintf(
                        __('Cleanup: %1$d duplicates found, %2$d merged, %3$d products reassigned, %4$d profiles deleted. (dry-run: %5$s)', 'woocommerce-us-duties'),
                        (int) ($cleanup_summary['duplicates_found'] ?? 0),
                        (int) ($cleanup_summary['merged'] ?? 0),
                        (int) ($cleanup_summary['products_reassigned'] ?? 0),
                        (int) ($cleanup_summary['profiles_deleted'] ?? 0),
                        !empty($cleanup_summary['dry_run']) ? 'yes' : 'no'
                    ),
                    'details' => !empty($cleanup_summary['messages']) ? implode("\n", (array) $cleanup_summary['messages']) : '',
                ];
            }
        }

        if (!empty($_POST['wrd_migrate_hs_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_migrate_hs_nonce'])), 'wrd_migrate_hs')) {
            $migrate_summary = $this->handle_hs_migration();
            if (is_array($migrate_summary)) {
                $notices[] = [
                    'class' => 'notice notice-success',
                    'message' => sprintf(
                        __('Migration: %1$d products checked, %2$d missing HS codes, %3$d matched to profiles, %4$d updated. (dry-run: %5$s)', 'woocommerce-us-duties'),
                        (int) ($migrate_summary['checked'] ?? 0),
                        (int) ($migrate_summary['missing_hs'] ?? 0),
                        (int) ($migrate_summary['matched'] ?? 0),
                        (int) ($migrate_summary['updated'] ?? 0),
                        !empty($migrate_summary['dry_run']) ? 'yes' : 'no'
                    ),
                    'details' => !empty($migrate_summary['messages']) ? implode("\n", array_slice((array) $migrate_summary['messages'], 0, 100)) : '',
                ];
            }
        }

        if (!empty($_POST['wrd_products_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_products_import_nonce'])), 'wrd_products_import')) {
            $summary = $this->handle_products_csv_import();
            if (is_array($summary)) {
                $notices[] = [
                    'class' => 'notice notice-info',
                    'message' => sprintf(
                        __('Products CSV: %1$d rows, %2$d matched, %3$d updated, %4$d skipped, %5$d errors. (dry-run: %6$s)', 'woocommerce-us-duties'),
                        (int) ($summary['rows'] ?? 0),
                        (int) ($summary['matched'] ?? 0),
                        (int) ($summary['updated'] ?? 0),
                        (int) ($summary['skipped'] ?? 0),
                        (int) ($summary['errors'] ?? 0),
                        !empty($summary['dry_run']) ? 'yes' : 'no'
                    ),
                    'details' => !empty($summary['messages']) ? implode("\n", array_slice((array) $summary['messages'], 0, 50)) : '',
                ];
            }
        }

        $export_profiles_url = add_query_arg(['page' => 'wrd-customs', 'tab' => 'import', 'action' => 'export'], admin_url('admin.php'));
        $export_products_url = add_query_arg(['page' => 'wrd-customs', 'tab' => 'import', 'action' => 'export_products'], admin_url('admin.php'));

        echo '<style>
            .wrd-import-intro {
                margin: 12px 0 16px;
                color: #50575e;
                max-width: 980px;
            }
            .wrd-import-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
                gap: 14px;
                align-items: start;
            }
            .wrd-import-grid .postbox {
                margin: 0;
            }
            .wrd-import-grid .postbox .hndle {
                padding: 12px 14px;
                border-bottom: 1px solid #e2e4e7;
            }
            .wrd-import-grid .postbox .inside {
                margin: 0;
                padding: 14px;
            }
            .wrd-import-grid .inside p.description {
                margin-top: 0;
                margin-bottom: 12px;
                color: #646970;
            }
            .wrd-import-grid form p {
                margin: 0 0 10px;
            }
            .wrd-import-form-row {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 10px;
                margin-bottom: 10px;
            }
            .wrd-import-form-row label {
                display: block;
                margin: 0;
                color: #1d2327;
                font-weight: 500;
            }
            .wrd-import-form-row input[type="text"],
            .wrd-import-form-row input[type="date"],
            .wrd-import-form-row input[type="number"],
            .wrd-import-form-row select {
                width: 100%;
                margin-top: 4px;
                min-width: 0;
                max-width: none;
            }
            .wrd-import-form-note {
                margin: 0 0 10px;
                color: #646970;
            }
            .wrd-import-grid input[type="file"] {
                width: 100%;
                max-width: 560px;
            }
            .wrd-import-grid .regular-text {
                width: 100%;
                max-width: 560px;
            }
            .wrd-import-grid .submit {
                margin: 0;
                padding: 0;
            }
            .wrd-import-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }
            .wrd-import-notice .details {
                margin-top: 8px;
            }
            .wrd-import-notice pre {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 8px;
                max-height: 280px;
                overflow: auto;
                margin: 8px 0 0;
            }
            .wrd-import-advanced {
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 10px 12px;
                background: #f8f9fa;
                margin: 10px 0;
            }
            .wrd-import-advanced > summary {
                cursor: pointer;
                font-weight: 600;
            }
            .wrd-import-advanced[open] > summary {
                margin-bottom: 8px;
            }
        </style>';

        echo '<p class="wrd-import-intro">' . esc_html__('Import and export profile/product data, plus run migration utilities. Start with dry-run for operations that update or delete data.', 'woocommerce-us-duties') . '</p>';

        foreach ($notices as $notice) {
            echo '<div class="' . esc_attr($notice['class']) . ' wrd-import-notice"><p>' . esc_html($notice['message']) . '</p>';
            if (!empty($notice['details'])) {
                echo '<details class="details"><summary>' . esc_html__('Details', 'woocommerce-us-duties') . '</summary><pre>' . esc_html($notice['details']) . '</pre></details>';
            }
            echo '</div>';
        }

        echo '<div class="wrd-import-grid">';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Export', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Download current duty profiles or product customs assignments as CSV.', 'woocommerce-us-duties') . '</p>';
        echo '<p class="wrd-import-actions"><a href="' . esc_url($export_profiles_url) . '" class="button">' . esc_html__('Export Profiles CSV', 'woocommerce-us-duties') . '</a> <a href="' . esc_url($export_products_url) . '" class="button button-primary">' . esc_html__('Export Products CSV', 'woocommerce-us-duties') . '</a></p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Import Profiles (CSV)', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Import profile records from CSV. Header mapping is optional and available under Advanced.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('wrd_import_csv', 'wrd_import_nonce');
        echo '<p><input type="file" name="wrd_csv" accept=".csv" required /></p>';
        echo '<p><label><input type="checkbox" name="profiles_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        echo '<details class="wrd-import-advanced">';
        echo '<summary>' . esc_html__('Advanced Header Mapping', 'woocommerce-us-duties') . '</summary>';
        echo '<p class="wrd-import-form-note"><strong>' . esc_html__('Header Mapping (optional)', 'woocommerce-us-duties') . '</strong><br />' . esc_html__('Leave blank to use standard names: description, country_code, hs_code, fta_flags, us_duty_json, effective_from, effective_to, notes.', 'woocommerce-us-duties') . '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Description', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_description" placeholder="description" /></label>';
        echo '<label>' . esc_html__('Country Code', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_country" placeholder="country_code" /></label>';
        echo '<label>' . esc_html__('HS Code', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_hs" placeholder="hs_code" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('FTA Flags (JSON)', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_fta" placeholder="fta_flags" /></label>';
        echo '<label>' . esc_html__('US Duty JSON', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_udj" placeholder="us_duty_json" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Effective From', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_from" placeholder="effective_from" /></label>';
        echo '<label>' . esc_html__('Effective To', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_to" placeholder="effective_to" /></label>';
        echo '<label>' . esc_html__('Notes', 'woocommerce-us-duties') . ' <input type="text" name="map_profile_notes" placeholder="notes" /></label>';
        echo '</p>';
        echo '</details>';
        submit_button(__('Import Profiles CSV', 'woocommerce-us-duties'), 'primary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Import Duties (JSON)', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Import duties JSON. The default parser supports Zonos-style files with an entries[] root and channel rates under postal.rates/commercial.rates.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('wrd_import_json', 'wrd_import_json_nonce');
        echo '<p><input type="file" name="wrd_json" accept="application/json,.json" required /></p>';
        echo '<p class="wrd-import-form-row"><label>' . esc_html__('Effective From', 'woocommerce-us-duties') . ' <input type="date" name="effective_from" value="' . esc_attr(date('Y-m-d')) . '" required /></label></p>';
        echo '<p><label><input type="checkbox" name="map_json_treat_placeholder_desc_blank" value="1" /> ' . esc_html__('Treat placeholder descriptions (N/A, null, -, --) as blank and auto-fill from HS code', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-import-form-note">' . esc_html__('Advanced schema mapping is optional. Leave it collapsed unless your JSON uses different field names.', 'woocommerce-us-duties') . '</p>';
        echo '<details class="wrd-import-advanced">';
        echo '<summary>' . esc_html__('Advanced JSON Mapping', 'woocommerce-us-duties') . '</summary>';
        echo '<p class="wrd-import-form-note"><strong>' . esc_html__('JSON Schema Mapping', 'woocommerce-us-duties') . '</strong><br />' . esc_html__('Use dot paths (for example: entries, country_of_origin, commercial.rates). You can provide fallbacks separated by |.', 'woocommerce-us-duties') . '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Root entries path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_root" placeholder="entries" /></label>';
        echo '<label>' . esc_html__('Description path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_description" placeholder="description|zonos_customs_description" /></label>';
        echo '<label>' . esc_html__('Country path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_country" placeholder="origin|originCountry|country_of_origin" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('HS path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_hs" placeholder="hs_code|hsCode" /></label>';
        echo '<label>' . esc_html__('Postal rates path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_postal_rates" placeholder="postal.rates" /></label>';
        echo '<label>' . esc_html__('Commercial rates path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_commercial_rates" placeholder="commercial.rates" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Tariffs path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_tariffs" placeholder="tariffs" /></label>';
        echo '<label>' . esc_html__('Source path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_source" placeholder="source" /></label>';
        echo '<label>' . esc_html__('FTA path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_fta" placeholder="fta_flags" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Postal components path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_postal_components" placeholder="postal.components" /></label>';
        echo '<label>' . esc_html__('Commercial components path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_commercial_components" placeholder="commercial.components" /></label>';
        echo '<label>' . esc_html__('Component code path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_component_code" placeholder="code|id|key" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Component rate path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_component_rate" placeholder="rate|value" /></label>';
        echo '<label>' . esc_html__('Component basis path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_component_basis" placeholder="basis|base" /></label>';
        echo '<label>' . esc_html__('Component order path', 'woocommerce-us-duties') . ' <input type="text" name="map_json_component_order" placeholder="order|priority" /></label>';
        echo '</p>';
        echo '<p><label><input type="checkbox" name="map_json_ignore_fx_duty" value="1" checked /> ' . esc_html__('Ignore fields like *_fx_rate_duty when calculating rate percentages (recommended for Zonos files)', 'woocommerce-us-duties') . '</label></p>';
        echo '</details>';
        echo '<p><label><input type="checkbox" name="replace_existing" value="1" /> ' . esc_html__('Update if profile exists with same HS code, country, and date', 'woocommerce-us-duties') . '</label></p>';
        echo '<p><label>' . esc_html__('Notes (optional)', 'woocommerce-us-duties') . '<br /><input type="text" name="notes" class="regular-text" placeholder="Imported duties file" /></label></p>';
        submit_button(__('Import Duties JSON', 'woocommerce-us-duties'), 'primary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Assign Customs to Products (CSV)', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Upload a CSV with columns for product ID/SKU, HS code, country code, and optionally description plus Section 232 inputs. HS code + country is preferred. Header mapping is optional under Advanced.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('wrd_products_import', 'wrd_products_import_nonce');
        echo '<p><input type="file" name="wrd_products_csv" accept=".csv" required /></p>';
        echo '<p><label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__('Dry run only (no changes)', 'woocommerce-us-duties') . '</label></p>';
        echo '<details class="wrd-import-advanced">';
        echo '<summary>' . esc_html__('Advanced Header Mapping', 'woocommerce-us-duties') . '</summary>';
        echo '<p class="wrd-import-form-note"><strong>' . esc_html__('Header Mapping (optional)', 'woocommerce-us-duties') . '</strong><br />' . esc_html__('Leave blank to autodetect common names like product_id, sku, hs_code, country_code, customs_description.', 'woocommerce-us-duties') . '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Product Identifier Header', 'woocommerce-us-duties') . ' <input type="text" name="map_identifier" placeholder="product_id or sku" /></label>';
        echo '<label>' . esc_html__('Identifier Type', 'woocommerce-us-duties') . ' <select name="identifier_type"><option value="auto">' . esc_html__('Auto', 'woocommerce-us-duties') . '</option><option value="id">' . esc_html__('Product ID', 'woocommerce-us-duties') . '</option><option value="sku">' . esc_html__('SKU', 'woocommerce-us-duties') . '</option></select></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('HS Code Header', 'woocommerce-us-duties') . ' <input type="text" name="map_hs" placeholder="hs_code" /></label>';
        echo '<label>' . esc_html__('Country Code Header', 'woocommerce-us-duties') . ' <input type="text" name="map_cc" placeholder="country_code" /></label>';
        echo '<label>' . esc_html__('Description Header', 'woocommerce-us-duties') . ' <input type="text" name="map_desc" placeholder="customs_description" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('Section 232 Applicable Header', 'woocommerce-us-duties') . ' <input type="text" name="map_s232_applicable" placeholder="section_232_applicable" /></label>';
        echo '<label>' . esc_html__('S232 Steel Value Header', 'woocommerce-us-duties') . ' <input type="text" name="map_s232_steel" placeholder="s232_steel_value" /></label>';
        echo '<label>' . esc_html__('S232 Aluminum Value Header', 'woocommerce-us-duties') . ' <input type="text" name="map_s232_aluminum" placeholder="s232_aluminum_value" /></label>';
        echo '</p>';
        echo '<p class="wrd-import-form-row">';
        echo '<label>' . esc_html__('S232 Copper Value Header', 'woocommerce-us-duties') . ' <input type="text" name="map_s232_copper" placeholder="s232_copper_value" /></label>';
        echo '<label>' . esc_html__('Manufacturer MID Header', 'woocommerce-us-duties') . ' <input type="text" name="map_mid" placeholder="manufacturer_id_mid" /></label>';
        echo '</p>';
        echo '</details>';
        submit_button(__('Import Products CSV', 'woocommerce-us-duties'), 'primary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Data Cleanup & Migration', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Safe maintenance actions for duplicate profiles and missing HS code population.', 'woocommerce-us-duties') . '</p>';

        echo '<h3 style="margin-top:0;">' . esc_html__('Cleanup Duplicate Profiles', 'woocommerce-us-duties') . '</h3>';
        echo '<p class="wrd-import-form-note">' . esc_html__('Merge duplicate profiles with the same HS code and country code. Product assignments are moved to the canonical profile.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wrd_cleanup_duplicates', 'wrd_cleanup_nonce');
        echo '<p><label><input type="checkbox" name="cleanup_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Find & Merge Duplicates', 'woocommerce-us-duties'), 'secondary', '', false);
        echo '</form>';

        echo '<hr />';
        echo '<h3>' . esc_html__('Migrate Products to HS Codes', 'woocommerce-us-duties') . '</h3>';
        echo '<p class="wrd-import-form-note">' . esc_html__('For products missing HS codes, match profile by description + country and populate HS code from the profile.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wrd_migrate_hs', 'wrd_migrate_hs_nonce');
        echo '<p><label><input type="checkbox" name="migrate_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-import-form-row"><label>' . esc_html__('Limit', 'woocommerce-us-duties') . ' <input type="number" name="migrate_limit" value="100" min="1" max="10000" /> ' . esc_html__('products', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Migrate HS Codes', 'woocommerce-us-duties'), 'secondary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    private function render_tab_tools(): void {
        $notices = [];

        if (!empty($_POST['wrd_curation_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_curation_import_nonce'])), 'wrd_curation_import')) {
            $curation_summary = $this->handle_description_curation_import();
            $notices[] = [
                'class' => !empty($curation_summary['errors']) ? 'notice notice-warning' : 'notice notice-success',
                'message' => sprintf(
                    __('Description curation import: %1$d parsed, %2$d valid, %3$d skipped, %4$d updated. (apply: %5$s)', 'woocommerce-us-duties'),
                    (int) ($curation_summary['parsed'] ?? 0),
                    (int) ($curation_summary['valid'] ?? 0),
                    (int) ($curation_summary['skipped'] ?? 0),
                    (int) ($curation_summary['updated'] ?? 0),
                    !empty($curation_summary['applied']) ? 'yes' : 'no'
                ),
                'details' => !empty($curation_summary['messages']) ? implode("\n", array_slice((array) $curation_summary['messages'], 0, 120)) : '',
            ];
        }

        if (!empty($_POST['wrd_cleanup_unused_profiles_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_cleanup_unused_profiles_nonce'])), 'wrd_cleanup_unused_profiles')) {
            $unused_summary = $this->handle_unused_profile_cleanup();
            if (is_array($unused_summary)) {
                $message = sprintf(
                    __('Unused profile cleanup: %1$d found, %2$d deleted. (dry-run: %3$s)', 'woocommerce-us-duties'),
                    (int) ($unused_summary['unused_found'] ?? 0),
                    (int) ($unused_summary['profiles_deleted'] ?? 0),
                    !empty($unused_summary['dry_run']) ? 'yes' : 'no'
                );
                $notices[] = [
                    'class' => 'notice notice-success',
                    'message' => $message,
                    'details' => !empty($unused_summary['messages']) ? implode("\n", (array) $unused_summary['messages']) : '',
                ];
            }
        }

        if (!empty($_POST['wrd_reindex_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_reindex_nonce'])), 'wrd_reindex')) {
            $processed = $this->reindex_products((int) ($_POST['max'] ?? 1000));
            $notices[] = [
                'class' => 'notice notice-success',
                'message' => sprintf(__('Reindexed %d items.', 'woocommerce-us-duties'), $processed),
                'details' => '',
            ];
        }

        if (!empty($_POST['wrd_fx_tools_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_fx_tools_nonce'])), 'wrd_fx_tools')) {
            if (!empty($_POST['wrd_fx_refresh'])) {
                delete_transient('wrd_fx_rates_exchangerate_host_USD');
                WRD_FX::get_rates_table('USD');
                $notices[] = [
                    'class' => 'notice notice-success',
                    'message' => __('FX rates refreshed.', 'woocommerce-us-duties'),
                    'details' => '',
                ];
            }
        }

        echo '<style>
            .wrd-tools-intro {
                margin: 12px 0 16px;
                color: #50575e;
                max-width: 980px;
            }
            .wrd-tools-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 14px;
                align-items: start;
            }
            .wrd-tools-grid .postbox {
                margin: 0;
            }
            .wrd-tools-grid .postbox.wrd-tools-card--wide {
                grid-column: 1 / -1;
            }
            .wrd-tools-grid .postbox .hndle {
                padding: 12px 14px;
                border-bottom: 1px solid #e2e4e7;
            }
            .wrd-tools-grid .postbox .inside {
                margin: 0;
                padding: 14px;
            }
            .wrd-tools-grid .inside p.description {
                margin-top: 0;
                margin-bottom: 12px;
                color: #646970;
            }
            .wrd-tool-section {
                margin-top: 12px;
                padding-top: 12px;
                border-top: 1px solid #f0f0f1;
            }
            .wrd-tool-section:first-of-type {
                margin-top: 0;
                padding-top: 0;
                border-top: 0;
            }
            .wrd-tool-title {
                margin: 0 0 8px;
                font-size: 13px;
                line-height: 1.4;
            }
            .wrd-tool-note {
                margin: 0 0 10px;
                color: #646970;
            }
            .wrd-tool-option {
                margin: 0 0 8px;
            }
            .wrd-tool-inline {
                display: flex;
                gap: 8px;
                align-items: center;
                flex-wrap: wrap;
                margin-bottom: 10px;
            }
            .wrd-tool-inline label {
                margin: 0;
            }
            .wrd-tool-inline input[type="number"] {
                width: 96px;
            }
            .wrd-tool-json-help {
                margin-top: 10px;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                background: #f8f9fa;
                padding: 8px 10px;
            }
            .wrd-tool-json-help > summary {
                cursor: pointer;
                font-weight: 600;
            }
            .wrd-tool-json-help pre {
                margin: 8px 0 0;
                max-height: 220px;
                overflow: auto;
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 8px;
            }
            .wrd-tools-grid .button {
                margin: 0;
            }
            .wrd-tools-notice .details {
                margin-top: 8px;
            }
            .wrd-tools-notice pre {
                background: #fff;
                border: 1px solid #dcdcde;
                padding: 8px;
                max-height: 280px;
                overflow: auto;
                margin: 8px 0 0;
            }
        </style>';

        echo '<p class="wrd-tools-intro">' . esc_html__('Operational tools for profile cleanup, metadata refresh, FX cache, and AI-assisted description curation. Use preview/dry-run first before applying writes.', 'woocommerce-us-duties') . '</p>';

        foreach ($notices as $notice) {
            echo '<div class="' . esc_attr($notice['class']) . ' wrd-tools-notice"><p>' . esc_html($notice['message']) . '</p>';
            if (!empty($notice['details'])) {
                echo '<details class="details"><summary>' . esc_html__('Details', 'woocommerce-us-duties') . '</summary><pre>' . esc_html($notice['details']) . '</pre></details>';
            }
            echo '</div>';
        }

        echo '<div class="wrd-tools-grid">';

        echo '<div class="postbox wrd-tools-card--wide">';
        echo '<h2 class="hndle"><span>' . esc_html__('Description Curation', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Generate a structured package for external LLM-assisted cleanup of missing/incomplete profiles, then import curated responses with preview/apply controls.', 'woocommerce-us-duties') . '</p>';

        echo '<div class="wrd-tool-section">';
        echo '<h3 class="wrd-tool-title">' . esc_html__('1) Generate Curation Package', 'woocommerce-us-duties') . '</h3>';
        echo '<p class="wrd-tool-note">' . esc_html__('Exports a Markdown prompt file with explicit instructions, output schema, and profile rows flagged as missing/incomplete.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" class="wrd-tool-form">';
        wp_nonce_field('wrd_curation_export', 'wrd_curation_export_nonce');
        echo '<p class="wrd-tool-inline"><label for="wrd-curation-limit">' . esc_html__('Max profiles', 'woocommerce-us-duties') . '</label> <input id="wrd-curation-limit" type="number" name="curation_limit" value="200" min="1" max="2000" /> <label for="wrd-curation-context">' . esc_html__('Products per profile', 'woocommerce-us-duties') . '</label> <input id="wrd-curation-context" type="number" name="curation_context_products" value="5" min="0" max="20" /></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_include_inactive" value="1" /> ' . esc_html__('Include inactive profiles', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Download Curation Prompt (MD)', 'woocommerce-us-duties'), 'secondary', 'wrd_curation_export', false);
        echo '</form>';
        echo '</div>';

        echo '<div class="wrd-tool-section">';
        echo '<h3 class="wrd-tool-title">' . esc_html__('2) Import Curation Response', 'woocommerce-us-duties') . '</h3>';
        echo '<p class="wrd-tool-note">' . esc_html__('Upload JSON output or Markdown containing a ```json response block. Run preview first, then apply updates after review.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" class="wrd-tool-form">';
        wp_nonce_field('wrd_curation_import', 'wrd_curation_import_nonce');
        echo '<p><input class="regular-text" type="file" name="wrd_curation_response" accept="application/json,.json,text/markdown,.md,text/plain,.txt" required /></p>';
        echo '<p class="wrd-tool-inline"><label for="wrd-curation-threshold">' . esc_html__('Min confidence (0-1)', 'woocommerce-us-duties') . '</label> <input id="wrd-curation-threshold" type="number" name="curation_min_confidence" value="0" min="0" max="1" step="0.01" /></p>';
        echo '<p class="wrd-tool-inline"><label for="wrd-curation-field-threshold">' . esc_html__('Min field confidence (0-1)', 'woocommerce-us-duties') . '</label> <input id="wrd-curation-field-threshold" type="number" name="curation_min_field_confidence" value="0" min="0" max="1" step="0.01" /></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_skip_needs_review" value="1" checked /> ' . esc_html__('Skip rows flagged as needs_review=true', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_guard_placeholder_only" value="1" checked /> ' . esc_html__('Only update rows currently marked as placeholder/HS description (recommended)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_guard_missing_only" value="1" checked /> ' . esc_html__('For HS/Country updates, only fill fields that are currently blank (recommended)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_apply_description" value="1" checked /> ' . esc_html__('Apply description suggestions', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_apply_hs" value="1" /> ' . esc_html__('Apply HS code suggestions', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_apply_country" value="1" /> ' . esc_html__('Apply country code suggestions', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="curation_apply_updates" value="1" /> ' . esc_html__('Apply updates now (leave unchecked for preview only)', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Preview / Import Curation Response', 'woocommerce-us-duties'), 'primary', 'wrd_curation_import', false);
        echo '</form>';
        echo '<details class="wrd-tool-json-help"><summary>' . esc_html__('Expected Response JSON', 'woocommerce-us-duties') . '</summary><pre>{
  "updates": [
    {
      "profile_id": 123,
      "suggested_description": "Synthetic adhesive tapes",
      "suggested_hs_code": "3919.90.5060",
      "suggested_country_code": "CN",
      "field_confidence": {
        "description": 0.93,
        "hs": 0.88,
        "country": 0.95
      },
      "needs_review": false,
      "evidence": [
        "5 linked products include customs description matching adhesive tape"
      ],
      "confidence": 0.92,
      "reason": "Based on linked product titles/SKUs"
    }
  ]
}</pre></details>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Profile Maintenance', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Remove profiles that are not directly linked to any active product or variation.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wrd_cleanup_unused_profiles', 'wrd_cleanup_unused_profiles_nonce');
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="unused_cleanup_dry_run" value="1" checked /> ' . esc_html__('Dry run (preview only)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p class="wrd-tool-option"><label><input type="checkbox" name="unused_cleanup_include_inactive" value="1" /> ' . esc_html__('Include inactive profiles', 'woocommerce-us-duties') . '</label></p>';
        submit_button(__('Find & Delete Unused Profiles', 'woocommerce-us-duties'), 'secondary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('Product Metadata', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Rebuild normalized customs metadata used for matching, status calculations, and impacted-product counts.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wrd_reindex', 'wrd_reindex_nonce');
        echo '<p class="wrd-tool-inline"><label for="wrd-tools-max">' . esc_html__('Max items', 'woocommerce-us-duties') . '</label> <input id="wrd-tools-max" type="number" name="max" value="1000" min="100" step="100" /></p>';
        submit_button(__('Run Reindex', 'woocommerce-us-duties'), 'secondary', '', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="postbox">';
        echo '<h2 class="hndle"><span>' . esc_html__('FX Cache', 'woocommerce-us-duties') . '</span></h2>';
        echo '<div class="inside">';
        echo '<p class="description">' . esc_html__('Clear and repopulate the USD exchange-rate cache used by duty calculations.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post">';
        wp_nonce_field('wrd_fx_tools', 'wrd_fx_tools_nonce');
        submit_button(__('Refresh FX Rates Cache', 'woocommerce-us-duties'), 'secondary', 'wrd_fx_refresh', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '</div>';
    }

    private function handle_unused_profile_cleanup(): array {
        $dry_run = !empty($_POST['unused_cleanup_dry_run']);
        $include_inactive = !empty($_POST['unused_cleanup_include_inactive']);
        global $wpdb;

        $table = WRD_DB::table_profiles();
        $today = current_time('Y-m-d');
        $status_clause = $include_inactive ? '' : $wpdb->prepare(' AND (p.effective_to IS NULL OR p.effective_to >= %s) ', $today);

        $sql = "
            SELECT p.id
            FROM {$table} p
            LEFT JOIN (
                SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS profile_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} posts ON posts.ID = pm.post_id
                WHERE pm.meta_key = '_wrd_profile_id'
                  AND pm.meta_value REGEXP '^[0-9]+$'
                  AND posts.post_type IN ('product', 'product_variation')
                  AND posts.post_status NOT IN ('trash', 'auto-draft')
            ) used_profiles ON used_profiles.profile_id = p.id
            WHERE used_profiles.profile_id IS NULL
            {$status_clause}
            ORDER BY p.id ASC
        ";

        $unused_ids = array_map('intval', (array) $wpdb->get_col($sql));
        $found_count = count($unused_ids);

        $summary = [
            'unused_found' => $found_count,
            'profiles_deleted' => 0,
            'dry_run' => $dry_run,
            'messages' => [],
        ];

        if ($found_count === 0) {
            $summary['messages'][] = 'No unused profiles found.';
            return $summary;
        }

        $summary['messages'][] = sprintf(
            'Found %d unused profile(s): %s',
            $found_count,
            implode(', ', $unused_ids)
        );

        if ($dry_run) {
            $summary['profiles_deleted'] = $found_count;
            $summary['messages'][] = '';
            $summary['messages'][] = 'DRY RUN - No changes made. Uncheck "Dry run" to apply changes.';
            return $summary;
        }

        $placeholders = implode(',', array_fill(0, $found_count, '%d'));
        $delete_sql = $wpdb->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})", $unused_ids);
        $deleted = (int) $wpdb->query($delete_sql);

        $summary['profiles_deleted'] = $deleted;
        $summary['messages'][] = sprintf('Deleted %d profile(s).', $deleted);

        return $summary;
    }

    private function export_description_curation_package(): void {
        if (!current_user_can('manage_woocommerce')) { return; }

        $limit = isset($_POST['curation_limit']) ? max(1, min(2000, (int) wp_unslash($_POST['curation_limit']))) : 200;
        $contextProducts = isset($_POST['curation_context_products']) ? max(0, min(20, (int) wp_unslash($_POST['curation_context_products']))) : 5;
        $includeInactive = !empty($_POST['curation_include_inactive']);

        $rows = $this->get_description_curation_candidates($limit, $includeInactive, $contextProducts);

        $payload = [
            'meta' => [
                'generated_at' => gmdate('c'),
                'plugin' => 'wc-us-duty',
                'version' => defined('WRD_US_DUTY_VERSION') ? WRD_US_DUTY_VERSION : 'unknown',
                'records' => count($rows),
                'filters' => [
                    'include_inactive_profiles' => $includeInactive,
                    'max_profiles' => $limit,
                    'products_per_profile' => $contextProducts,
                ],
            ],
            'prompt_template' => 'For each row, detect missing/incomplete fields, then infer from strongest evidence first: existing customs descriptions > product titles > category paths/defaults > notes. Return strict JSON with key "updates" containing objects: {"profile_id": number, "suggested_description": string|null, "suggested_hs_code": string|null, "suggested_country_code": string|null, "field_confidence": {"description": number|null, "hs": number|null, "country": number|null}, "needs_review": boolean, "evidence": string[], "confidence": number between 0 and 1, "reason": string}. Use null when unknown and avoid guessing.',
            'rows' => $rows,
        ];

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        $markdown = implode(
            "\n",
            [
                '# WRD Customs Description Curation Prompt',
                '',
                'You are helping curate customs descriptions for WooCommerce duty profiles.',
                '',
                'Task:',
                '1. Review each row in the JSON payload below.',
                '2. Identify missing/incomplete fields (description, hs_code, country_code).',
                '3. Use evidence in this order: existing customs_description samples > product titles/SKU clues > category paths/defaults > notes.',
                '4. Keep outputs factual. Use `null` for unknown fields and set `needs_review` true when uncertain.',
                '5. Skip rows when context is insufficient.',
                '',
                'Confidence rubric:',
                '- 0.90-1.00: direct supporting evidence from existing customs descriptions.',
                '- 0.70-0.89: consistent evidence across multiple product titles/category context.',
                '- 0.40-0.69: weak inference; prefer needs_review=true.',
                '- <0.40: return null for that field.',
                '',
                'Output requirements:',
                '1. Return strict JSON only (no prose) with a top-level `updates` array.',
                '2. Each object must follow: `{"profile_id": number, "suggested_description": string|null, "suggested_hs_code": string|null, "suggested_country_code": string|null, "field_confidence": {"description": number|null, "hs": number|null, "country": number|null}, "needs_review": boolean, "evidence": string[], "confidence": number, "reason": string}`.',
                '3. `confidence` and any `field_confidence` value must be between `0` and `1`.',
                '4. Do not output any markdown/prose outside JSON.',
                '',
                'Input payload:',
                '```json',
                $json,
                '```',
            ]
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename=wrd_description_curation_prompt_' . gmdate('Ymd_His') . '.md');
        echo $markdown;
        exit;
    }

    private function get_description_curation_candidates(int $limit, bool $includeInactive, int $contextProducts): array {
        global $wpdb;
        $table = WRD_DB::table_profiles();
        $today = current_time('Y-m-d');
        $query_profiles = static function (string $statusClause) use ($wpdb, $table, $limit): array {
            $sql = "
                SELECT id, description_raw, country_code, hs_code, source, effective_from, effective_to, notes
                FROM {$table}
                WHERE (
                    TRIM(description_raw) = ''
                    OR LOWER(TRIM(description_raw)) IN ('n/a','na','none','null','-','--')
                    OR LOWER(TRIM(description_raw)) REGEXP '^(n/?a|none|null|-+)([[:space:]]*\\(|$)'
                    OR LOWER(TRIM(description_raw)) LIKE '%hs provided%'
                    OR TRIM(description_raw) REGEXP '^(HS|hs)[[:space:]]+[0-9]'
                    OR TRIM(hs_code) = ''
                    OR TRIM(country_code) = ''
                )
                {$statusClause}
                ORDER BY last_updated DESC, id DESC
                LIMIT " . (int) $limit;
            return (array) $wpdb->get_results($sql, ARRAY_A);
        };

        $statusClause = $includeInactive ? '' : $wpdb->prepare(' AND (effective_to IS NULL OR effective_to >= %s) ', $today);
        $profiles = $query_profiles($statusClause);
        if (empty($profiles) && !$includeInactive) {
            // Fallback: if no active candidates match, include inactive candidates so curation export still has useful rows.
            $profiles = $query_profiles('');
        }

        $rows = [];
        foreach ($profiles as $profile) {
            $profileId = (int) ($profile['id'] ?? 0);
            if ($profileId <= 0) { continue; }
            $description = (string) ($profile['description_raw'] ?? '');
            $hsCode = (string) ($profile['hs_code'] ?? '');
            $countryCode = (string) ($profile['country_code'] ?? '');
            $issues = $this->get_profile_curation_issues($description, $hsCode, $countryCode);
            if (!$issues) { continue; }

            $context = $this->get_profile_linked_products_context($profileId, $contextProducts);
            $rows[] = [
                'profile_id' => $profileId,
                'issues' => $issues,
                'description_raw' => $description,
                'country_code' => $countryCode,
                'hs_code' => $hsCode,
                'source' => (string) ($profile['source'] ?? ''),
                'effective_from' => (string) ($profile['effective_from'] ?? ''),
                'effective_to' => (string) ($profile['effective_to'] ?? ''),
                'notes' => (string) ($profile['notes'] ?? ''),
                'linked_product_count' => (int) ($context['linked_product_count'] ?? 0),
                'linked_products' => (array) ($context['linked_products'] ?? []),
                'sample_customs_descriptions' => (array) ($context['sample_customs_descriptions'] ?? []),
                'top_title_terms' => (array) ($context['top_title_terms'] ?? []),
                'linked_category_paths' => (array) ($context['linked_category_paths'] ?? []),
                'linked_category_defaults' => (array) ($context['linked_category_defaults'] ?? []),
            ];
        }

        if (empty($rows)) {
            // Final fallback: export recent profiles with context so curation can still proceed when strict issue filters miss data.
            $seedSql = "
                SELECT id, description_raw, country_code, hs_code, source, effective_from, effective_to, notes
                FROM {$table}
                WHERE 1=1
                {$statusClause}
                ORDER BY last_updated DESC, id DESC
                LIMIT " . (int) $limit;
            $seedProfiles = (array) $wpdb->get_results($seedSql, ARRAY_A);
            if (empty($seedProfiles) && !$includeInactive) {
                $seedSql = "
                    SELECT id, description_raw, country_code, hs_code, source, effective_from, effective_to, notes
                    FROM {$table}
                    ORDER BY last_updated DESC, id DESC
                    LIMIT " . (int) $limit;
                $seedProfiles = (array) $wpdb->get_results($seedSql, ARRAY_A);
            }

            foreach ($seedProfiles as $profile) {
                $profileId = (int) ($profile['id'] ?? 0);
                if ($profileId <= 0) { continue; }
                $description = (string) ($profile['description_raw'] ?? '');
                $hsCode = (string) ($profile['hs_code'] ?? '');
                $countryCode = (string) ($profile['country_code'] ?? '');
                $issues = $this->get_profile_curation_issues($description, $hsCode, $countryCode);
                if (!$issues) {
                    $issues = ['manual_review_seed'];
                }
                $context = $this->get_profile_linked_products_context($profileId, $contextProducts);
                $rows[] = [
                    'profile_id' => $profileId,
                    'issues' => $issues,
                    'description_raw' => $description,
                    'country_code' => $countryCode,
                    'hs_code' => $hsCode,
                    'source' => (string) ($profile['source'] ?? ''),
                    'effective_from' => (string) ($profile['effective_from'] ?? ''),
                    'effective_to' => (string) ($profile['effective_to'] ?? ''),
                    'notes' => (string) ($profile['notes'] ?? ''),
                    'linked_product_count' => (int) ($context['linked_product_count'] ?? 0),
                    'linked_products' => (array) ($context['linked_products'] ?? []),
                    'sample_customs_descriptions' => (array) ($context['sample_customs_descriptions'] ?? []),
                    'top_title_terms' => (array) ($context['top_title_terms'] ?? []),
                    'linked_category_paths' => (array) ($context['linked_category_paths'] ?? []),
                    'linked_category_defaults' => (array) ($context['linked_category_defaults'] ?? []),
                ];
            }
        }

        return $rows;
    }

    private function get_profile_linked_products_context(int $profileId, int $limit = 5): array {
        global $wpdb;
        $limit = max(0, min(20, $limit));

        $countSql = $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wrd_profile_id'
               AND pm.meta_value = %d
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')",
            $profileId
        );
        $total = (int) $wpdb->get_var($countSql);

        if ($limit === 0 || $total <= 0) {
            return ['linked_product_count' => $total, 'linked_products' => [], 'sample_customs_descriptions' => []];
        }

        $idsSql = $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wrd_profile_id'
               AND pm.meta_value = %d
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status NOT IN ('trash', 'auto-draft')
             ORDER BY p.post_modified_gmt DESC
             LIMIT %d",
            $profileId,
            $limit
        );
        $ids = array_map('intval', (array) $wpdb->get_col($idsSql));

        $products = [];
        $descriptions = [];
        $titleCorpus = [];
        $categoryPaths = [];
        $categoryDefaults = [];
        foreach ($ids as $postId) {
            $post = get_post($postId);
            if (!$post) { continue; }
            $sku = (string) get_post_meta($postId, '_sku', true);
            $customsDescription = trim((string) get_post_meta($postId, '_customs_description', true));
            if ($customsDescription !== '' && !in_array($customsDescription, $descriptions, true)) {
                $descriptions[] = $customsDescription;
            }
            $titleCorpus[] = (string) $post->post_title;

            $baseProductId = ((string) $post->post_type === 'product_variation') ? (int) wp_get_post_parent_id($postId) : $postId;
            if ($baseProductId <= 0) {
                $baseProductId = $postId;
            }
            $catContext = $this->collect_product_category_context($baseProductId);
            foreach ((array) ($catContext['paths'] ?? []) as $path) {
                if ($path !== '' && !in_array($path, $categoryPaths, true)) {
                    $categoryPaths[] = $path;
                }
            }
            foreach ((array) ($catContext['defaults'] ?? []) as $defaultRow) {
                if (!is_array($defaultRow)) { continue; }
                $fingerprint = md5(wp_json_encode($defaultRow));
                if (!isset($categoryDefaults[$fingerprint])) {
                    $categoryDefaults[$fingerprint] = $defaultRow;
                }
            }
            $products[] = [
                'product_id' => $postId,
                'type' => (string) $post->post_type,
                'title' => (string) $post->post_title,
                'sku' => $sku,
                'customs_description' => $customsDescription,
            ];
        }

        return [
            'linked_product_count' => $total,
            'linked_products' => $products,
            'sample_customs_descriptions' => array_slice($descriptions, 0, 10),
            'top_title_terms' => $this->extract_top_title_terms($titleCorpus, 12),
            'linked_category_paths' => array_slice($categoryPaths, 0, 20),
            'linked_category_defaults' => array_values($categoryDefaults),
        ];
    }

    private function handle_description_curation_import(): array {
        $summary = [
            'parsed' => 0,
            'valid' => 0,
            'skipped' => 0,
            'updated' => 0,
            'errors' => 0,
            'applied' => !empty($_POST['curation_apply_updates']),
            'messages' => [],
        ];

        if (empty($_FILES['wrd_curation_response']) || !is_uploaded_file($_FILES['wrd_curation_response']['tmp_name'])) {
            $summary['errors'] = 1;
            $summary['messages'][] = 'No curation response file uploaded.';
            return $summary;
        }

        $raw = file_get_contents($_FILES['wrd_curation_response']['tmp_name']);
        if ($raw === false) {
            $summary['errors'] = 1;
            $summary['messages'][] = 'Unable to read uploaded curation response file.';
            return $summary;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $rawJson = $this->extract_json_from_markdown($raw);
            if ($rawJson !== null) {
                $decoded = json_decode($rawJson, true);
            }
        }
        if (!is_array($decoded)) {
            $summary['errors'] = 1;
            $summary['messages'][] = 'Invalid response: expected JSON or Markdown containing a ```json code block.';
            return $summary;
        }

        $updatesRaw = [];
        if ($this->is_list_array($decoded)) {
            $updatesRaw = $decoded;
        } elseif (!empty($decoded['updates']) && is_array($decoded['updates'])) {
            $updatesRaw = $decoded['updates'];
        } elseif (!empty($decoded['rows']) && is_array($decoded['rows'])) {
            $updatesRaw = $decoded['rows'];
        } elseif (!empty($decoded['results']) && is_array($decoded['results'])) {
            $updatesRaw = $decoded['results'];
        }

        $summary['parsed'] = count($updatesRaw);
        if (!$updatesRaw) {
            $summary['errors'] = 1;
            $summary['messages'][] = 'No updates array found. Expected top-level list or object with updates[].';
            return $summary;
        }

        $minConfidence = isset($_POST['curation_min_confidence']) ? (float) wp_unslash($_POST['curation_min_confidence']) : 0.0;
        if ($minConfidence < 0) { $minConfidence = 0.0; }
        if ($minConfidence > 1) { $minConfidence = 1.0; }
        $minFieldConfidence = isset($_POST['curation_min_field_confidence']) ? (float) wp_unslash($_POST['curation_min_field_confidence']) : 0.0;
        if ($minFieldConfidence < 0) { $minFieldConfidence = 0.0; }
        if ($minFieldConfidence > 1) { $minFieldConfidence = 1.0; }
        $skipNeedsReview = !empty($_POST['curation_skip_needs_review']);
        $guardPlaceholderOnly = !empty($_POST['curation_guard_placeholder_only']);
        $guardMissingOnly = !empty($_POST['curation_guard_missing_only']);
        $applyDescription = !empty($_POST['curation_apply_description']);
        $applyHs = !empty($_POST['curation_apply_hs']);
        $applyCountry = !empty($_POST['curation_apply_country']);

        if (!$applyDescription && !$applyHs && !$applyCountry) {
            $summary['errors'] = 1;
            $summary['messages'][] = 'No update targets selected. Enable at least one of description/HS/country apply options.';
            return $summary;
        }

        global $wpdb;
        $table = WRD_DB::table_profiles();
        $previewCount = 0;

        foreach ($updatesRaw as $i => $item) {
            if (!is_array($item)) {
                $summary['skipped']++;
                $summary['messages'][] = sprintf('Row %d skipped: expected object.', (int) $i);
                continue;
            }

            $profileId = isset($item['profile_id']) ? (int) $item['profile_id'] : 0;
            $candidateDescription = isset($item['suggested_description']) ? (string) $item['suggested_description'] : ((isset($item['description']) ? (string) $item['description'] : ''));
            $candidateDescription = trim(sanitize_text_field($candidateDescription));
            $candidateHs = isset($item['suggested_hs_code']) ? (string) $item['suggested_hs_code'] : ((isset($item['hs_code']) ? (string) $item['hs_code'] : ''));
            $candidateHs = trim(sanitize_text_field($candidateHs));
            if (strlen($candidateHs) > 20) {
                $candidateHs = substr($candidateHs, 0, 20);
            }
            $candidateCountry = isset($item['suggested_country_code']) ? (string) $item['suggested_country_code'] : ((isset($item['country_code']) ? (string) $item['country_code'] : ''));
            $candidateCountry = strtoupper(trim(sanitize_text_field($candidateCountry)));
            $confidence = isset($item['confidence']) && is_numeric($item['confidence']) ? (float) $item['confidence'] : null;
            $reason = isset($item['reason']) ? trim(sanitize_text_field((string) $item['reason'])) : '';
            $evidence = [];
            if (!empty($item['evidence']) && is_array($item['evidence'])) {
                foreach ($item['evidence'] as $ev) {
                    $evText = trim(sanitize_text_field((string) $ev));
                    if ($evText !== '') {
                        $evidence[] = $evText;
                    }
                }
            }
            $needsReview = false;
            if (isset($item['needs_review'])) {
                $needsReview = filter_var($item['needs_review'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $needsReview = $needsReview === null ? false : (bool) $needsReview;
            }
            $fieldConfidence = is_array($item['field_confidence'] ?? null) ? $item['field_confidence'] : [];
            $descFieldConfidence = isset($fieldConfidence['description']) && is_numeric($fieldConfidence['description']) ? (float) $fieldConfidence['description'] : null;
            $hsFieldConfidence = isset($fieldConfidence['hs']) && is_numeric($fieldConfidence['hs']) ? (float) $fieldConfidence['hs'] : null;
            $countryFieldConfidence = isset($fieldConfidence['country']) && is_numeric($fieldConfidence['country']) ? (float) $fieldConfidence['country'] : null;

            if ($profileId <= 0) {
                $summary['skipped']++;
                $summary['messages'][] = sprintf('Row %d skipped: missing profile_id.', (int) $i);
                continue;
            }

            if ($skipNeedsReview && $needsReview) {
                $summary['skipped']++;
                $summary['messages'][] = sprintf('Profile %d skipped: flagged for manual review.', $profileId);
                continue;
            }

            if ($confidence !== null && $confidence < $minConfidence) {
                $summary['skipped']++;
                $summary['messages'][] = sprintf('Profile %d skipped: confidence %.3f below threshold %.3f.', $profileId, $confidence, $minConfidence);
                continue;
            }

            $existing = $wpdb->get_row(
                $wpdb->prepare("SELECT id, description_raw, hs_code, country_code FROM {$table} WHERE id = %d LIMIT 1", $profileId),
                ARRAY_A
            );
            if (!is_array($existing)) {
                $summary['skipped']++;
                $summary['messages'][] = sprintf('Profile %d skipped: not found.', $profileId);
                continue;
            }

            $oldDescription = (string) ($existing['description_raw'] ?? '');
            $oldHs = trim((string) ($existing['hs_code'] ?? ''));
            $oldCountry = strtoupper(trim((string) ($existing['country_code'] ?? '')));
            if ($guardPlaceholderOnly && !$this->description_needs_curation($oldDescription)) {
                $candidateDescription = '';
            }
            if (!$this->is_valid_country_code($candidateCountry)) {
                $candidateCountry = '';
            }

            $data = [];
            $fmt = [];
            $changes = [];

            if ($applyDescription && $candidateDescription !== '' && $oldDescription !== $candidateDescription) {
                if ($descFieldConfidence === null || $descFieldConfidence >= $minFieldConfidence) {
                    $data['description_raw'] = $candidateDescription;
                    $data['description_normalized'] = WRD_DB::normalize_description($candidateDescription);
                    $fmt[] = '%s';
                    $fmt[] = '%s';
                    $changes[] = sprintf('description "%s" -> "%s"', $oldDescription, $candidateDescription);
                }
            }

            if ($applyHs && $candidateHs !== '' && $oldHs !== $candidateHs) {
                if (!$guardMissingOnly || $oldHs === '') {
                    if ($hsFieldConfidence === null || $hsFieldConfidence >= $minFieldConfidence) {
                        $data['hs_code'] = $candidateHs;
                        $fmt[] = '%s';
                        $changes[] = sprintf('hs "%s" -> "%s"', $oldHs, $candidateHs);
                    }
                }
            }

            if ($applyCountry && $candidateCountry !== '' && $oldCountry !== $candidateCountry) {
                if (!$guardMissingOnly || $oldCountry === '') {
                    if ($countryFieldConfidence === null || $countryFieldConfidence >= $minFieldConfidence) {
                        $data['country_code'] = $candidateCountry;
                        $fmt[] = '%s';
                        $changes[] = sprintf('country "%s" -> "%s"', $oldCountry, $candidateCountry);
                    }
                }
            }

            if (!$changes) {
                $summary['skipped']++;
                continue;
            }

            $summary['valid']++;
            if ($previewCount < 50) {
                $summary['messages'][] = sprintf(
                    'Profile %d: %s%s%s%s',
                    $profileId,
                    implode('; ', $changes),
                    $confidence !== null ? sprintf(' (confidence %.3f)', $confidence) : '',
                    $reason !== '' ? ' reason: ' . $reason : '',
                    !empty($evidence) ? ' evidence: ' . implode(' | ', array_slice($evidence, 0, 3)) : ''
                );
                $previewCount++;
            }

            if ($summary['applied']) {
                $ok = $wpdb->update($table, $data, ['id' => $profileId], $fmt, ['%d']);
                if ($ok !== false) {
                    $summary['updated']++;
                } else {
                    $summary['errors']++;
                    $summary['messages'][] = sprintf('Profile %d update error: %s', $profileId, (string) $wpdb->last_error);
                }
            }
        }

        if (!$summary['applied']) {
            $summary['messages'][] = '';
            $summary['messages'][] = 'Preview only. Enable "Apply updates now" to write changes.';
        }

        return $summary;
    }

    private function collect_product_category_context(int $productId): array {
        $terms = get_the_terms($productId, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return ['paths' => [], 'defaults' => []];
        }

        $paths = [];
        $defaults = [];
        foreach ($terms as $term) {
            if (!($term instanceof \WP_Term)) { continue; }
            $path = $this->build_category_path((int) $term->term_id, 'product_cat');
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }

            $defaultHs = trim((string) get_term_meta($term->term_id, 'wrd_default_hs_code', true));
            $defaultCountry = strtoupper(trim((string) get_term_meta($term->term_id, 'wrd_default_country_of_origin', true)));
            if ($defaultHs !== '' || $defaultCountry !== '') {
                $defaults[] = [
                    'category' => (string) $term->name,
                    'path' => $path,
                    'default_hs_code' => $defaultHs,
                    'default_country_code' => $this->is_valid_country_code($defaultCountry) ? $defaultCountry : '',
                ];
            }
        }

        return [
            'paths' => array_slice($paths, 0, 20),
            'defaults' => array_slice($defaults, 0, 20),
        ];
    }

    private function build_category_path(int $termId, string $taxonomy): string {
        if ($termId <= 0) { return ''; }
        $ids = array_reverse(array_map('intval', get_ancestors($termId, $taxonomy, 'taxonomy')));
        $ids[] = $termId;
        $names = [];
        foreach ($ids as $id) {
            $term = get_term($id, $taxonomy);
            if ($term instanceof \WP_Term && !is_wp_error($term)) {
                $names[] = (string) $term->name;
            }
        }
        return implode(' > ', $names);
    }

    private function extract_top_title_terms(array $titles, int $limit = 12): array {
        $stop = [
            'the','and','for','with','from','pack','set','inch','inches','cm','mm','new','kit','plus','pro','by','of','to','a','an',
        ];
        $counts = [];
        foreach ($titles as $title) {
            $title = strtolower((string) $title);
            $title = preg_replace('/[^a-z0-9\s]+/', ' ', $title);
            if (!is_string($title) || $title === '') { continue; }
            $parts = preg_split('/\s+/', trim($title));
            if (!is_array($parts)) { continue; }
            foreach ($parts as $part) {
                if ($part === '' || strlen($part) < 3 || in_array($part, $stop, true)) { continue; }
                if (is_numeric($part)) { continue; }
                if (!isset($counts[$part])) {
                    $counts[$part] = 0;
                }
                $counts[$part]++;
            }
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, max(1, $limit));
    }

    private function is_valid_country_code(string $country): bool {
        return (bool) preg_match('/^[A-Z]{2}$/', $country);
    }

    private function description_needs_curation(string $description): bool {
        $normalized = strtolower(trim($description));
        if ($normalized === '' || in_array($normalized, ['n/a', 'na', 'none', 'null', '-', '--'], true)) {
            return true;
        }
        if ((bool) preg_match('/^(n\/?a|none|null|-+)(\s*\(|$)/i', trim($description))) {
            return true;
        }
        if (strpos($normalized, 'hs provided') !== false) {
            return true;
        }
        return (bool) preg_match('/^hs\s+[0-9.]+$/i', trim($description));
    }

    private function get_profile_curation_issues(string $description, string $hsCode, string $countryCode): array {
        $issues = [];
        if ($this->description_needs_curation($description)) {
            $issues[] = 'description_missing_or_placeholder';
        }
        if (trim($hsCode) === '') {
            $issues[] = 'hs_code_missing';
        }
        if (trim($countryCode) === '') {
            $issues[] = 'country_code_missing';
        }
        return $issues;
    }

    private function extract_json_from_markdown(string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/```json\s*(\{.*?\}|\[.*?\])\s*```/is', $raw, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }
        if (preg_match('/```\s*(\{.*?\}|\[.*?\])\s*```/is', $raw, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return null;
    }

    // --- Products list column: Customs status ---
    public function add_product_customs_column($columns) {
        if ($this->is_hs_manager_catalog_mode()) {
            return [
                'cb' => isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />',
                'title' => __('Product', 'woocommerce-us-duties'),
                'wrd_hs_sku' => __('SKU', 'woocommerce-us-duties'),
                'wrd_hs_source' => __('Source', 'woocommerce-us-duties'),
                'wrd_hs_code' => __('HS Code', 'woocommerce-us-duties'),
                'wrd_hs_origin' => __('Origin', 'woocommerce-us-duties'),
                'wrd_hs_profile' => __('Profile', 'woocommerce-us-duties'),
                'wrd_hs_status' => __('Status', 'woocommerce-us-duties'),
            ];
        }

        // Main product table: do not inject the legacy customs column.
        if (isset($columns['wrd_customs'])) {
            unset($columns['wrd_customs']);
        }
        return $columns;
    }

    public function add_product_customs_sortable_column($columns) {
        if ($this->is_hs_manager_catalog_mode()) {
            $columns['wrd_hs_sku'] = 'sku';
            $columns['wrd_hs_status'] = 'wrd_status';
            return $columns;
        }
        if (isset($columns['wrd_customs'])) {
            unset($columns['wrd_customs']);
        }
        return $columns;
    }

    public function filter_product_views($views) {
        if (!current_user_can('edit_products')) { return $views; }

        $is_hs_manager = $this->is_hs_manager_catalog_mode();
        $hs_manager_url = add_query_arg(
            [
                'post_type' => 'product',
                self::PRODUCT_CATALOG_MODE_QUERY_VAR => self::PRODUCT_CATALOG_MODE_HS_MANAGER,
            ],
            admin_url('edit.php')
        );
        $views['wrd_hs_manager'] = sprintf(
            '<a href="%s" class="%s">%s</a>',
            esc_url($hs_manager_url),
            $is_hs_manager ? 'current' : '',
            esc_html__('HS Manager', 'woocommerce-us-duties')
        );

        return $views;
    }

    public function render_product_status_filter($post_type): void {
        if ($post_type !== 'product' || !current_user_can('edit_products')) { return; }

        if ($this->is_hs_manager_catalog_mode()) {
            echo '<input type="hidden" name="' . esc_attr(self::PRODUCT_CATALOG_MODE_QUERY_VAR) . '" value="' . esc_attr(self::PRODUCT_CATALOG_MODE_HS_MANAGER) . '" />';
        }

        $selected = $this->get_selected_customs_filter_status();
        $labels = $this->get_product_customs_status_labels();

        echo '<label class="screen-reader-text" for="wrd-customs-status-filter">' . esc_html__('Filter by customs status', 'woocommerce-us-duties') . '</label>';
        echo '<select id="wrd-customs-status-filter" name="' . esc_attr(self::PRODUCT_CUSTOMS_FILTER_QUERY_VAR) . '">';
        echo '<option value="">' . esc_html__('Customs status: Any', 'woocommerce-us-duties') . '</option>';
        foreach (self::PRODUCT_CUSTOMS_STATUSES as $status) {
            $label = isset($labels[$status]) ? $labels[$status] : ucfirst(str_replace('_', ' ', $status));
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($status),
                selected($selected, $status, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    public function apply_product_status_filters($query): void {
        if (!is_admin() || !$query instanceof WP_Query || !$query->is_main_query()) { return; }
        global $pagenow;
        if ($pagenow !== 'edit.php') { return; }
        if ($query->get('post_type') !== 'product') { return; }

        $status = $this->get_active_customs_status();
        if ($status !== '') {
            $meta_query = $query->get('meta_query');
            if (!is_array($meta_query)) { $meta_query = []; }
            $meta_query[] = $this->build_meta_query_for_customs_status($status);
            $query->set('meta_query', $meta_query);
        }

        $orderby = (string) $query->get('orderby');
        if ($orderby === 'wrd_customs' || $orderby === 'wrd_status') {
            $query->set('meta_key', '_wrd_customs_status_rank');
            $query->set('orderby', 'meta_value_num');
            if (!$query->get('order')) {
                $query->set('order', 'ASC');
            }
        }
    }

    public function render_hs_manager_catalog_notice(): void {
        if (!$this->is_hs_manager_catalog_mode()) { return; }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-product') { return; }
        if (!current_user_can('edit_products')) { return; }

        echo '<div class="notice notice-info is-dismissible wrd-hs-manager-notice"><p>';
        echo esc_html__('HS Manager mode: edit HS/origin inline and use the Profile pencil to search/apply profile matches per row.', 'woocommerce-us-duties');
        echo '</p></div>';
    }

    public function render_hs_manager_catalog_styles(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-product') { return; }
        $is_hs_manager = $this->is_hs_manager_catalog_mode();
        ?>
        <style>
            #bulk-edit .wrd-customs-fields {
                border-left: 1px solid #dcdcde;
                padding-left: 14px;
            }

            #bulk-edit .wrd-customs-fields h4 {
                margin: 0 0 8px;
                padding-bottom: 6px;
                border-bottom: 1px solid #e2e4e7;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel {
                margin-top: 10px;
                padding: 8px 10px;
                border: 1px solid #e5e7eb;
                border-radius: 4px;
                background: #fff;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel h4 {
                margin: 0 0 8px;
                padding-bottom: 5px;
                border-bottom: 1px solid #f0f0f1;
            }

            #bulk-edit .wrd-customs-fields .wrd-bulk-section,
            #woocommerce-fields-bulk .wrd-customs-bulk-panel .wrd-bulk-section {
                margin-bottom: 4px;
                padding: 4px 0;
                background: transparent;
                border: 0;
                border-bottom: 1px solid #f3f4f6;
            }

            #bulk-edit .wrd-customs-fields .wrd-bulk-section-title,
            #woocommerce-fields-bulk .wrd-customs-bulk-panel .wrd-bulk-section-title {
                display: block;
                margin-bottom: 2px;
                color: #1d2327;
                font-weight: 600;
                font-size: 12px;
            }

            #bulk-edit .wrd-customs-fields .inline-edit-group,
            #woocommerce-fields-bulk .wrd-customs-bulk-panel .inline-edit-group {
                margin-bottom: 4px;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .inline-edit-group {
                display: flex;
                align-items: center;
                gap: 10px;
                width: 100%;
                float: none;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .inline-edit-group .title {
                flex: 0 0 132px;
                width: 132px;
                margin: 0;
                line-height: 1.3;
                font-weight: 400;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .inline-edit-group .input-text-wrap {
                flex: 1 1 auto;
                min-width: 0;
                width: auto;
                margin-left: 0;
            }

            #bulk-edit .wrd-customs-fields .inline-edit-group:last-child,
            #woocommerce-fields-bulk .wrd-customs-bulk-panel .inline-edit-group:last-child {
                margin-bottom: 0;
            }

            #bulk-edit .wrd-customs-fields select,
            #bulk-edit .wrd-customs-fields input[type="text"],
            #woocommerce-fields-bulk .wrd-customs-bulk-panel select,
            #woocommerce-fields-bulk .wrd-customs-bulk-panel input[type="text"] {
                width: 100%;
                max-width: none;
            }

            #bulk-edit .wrd-customs-fields .wrd-bulk-safeguard,
            #woocommerce-fields-bulk .wrd-customs-bulk-panel .wrd-bulk-safeguard {
                margin-top: 2px;
                border-bottom: 0;
                padding-bottom: 0;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .wrd-bulk-safeguard .inline-edit-group {
                margin: 0;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .wrd-bulk-safeguard .input-text-wrap {
                width: 100%;
                display: block;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .wrd-bulk-safeguard .title {
                display: block;
                flex: 0 0 auto;
                width: auto;
                margin-right: 10px;
            }

            #woocommerce-fields-bulk .wrd-customs-bulk-panel .description {
                margin: 4px 0 0;
                color: #646970;
                font-size: 11px;
                max-width: none;
                clear: both;
            }

            <?php if ($is_hs_manager) : ?>
            .fixed .column-title { width: 26%; }
            .fixed .column-wrd_hs_sku { width: 10%; }
            .fixed .column-wrd_hs_source { width: 11%; }
            .fixed .column-wrd_hs_code { width: 9%; }
            .fixed .column-wrd_hs_origin { width: 7%; }
            .fixed .column-wrd_hs_profile { width: 22%; }
            .fixed .column-wrd_hs_status { width: 9%; }

            .wp-list-table .column-title,
            .wp-list-table .column-title .row-title,
            .wp-list-table .column-title strong {
                white-space: normal;
                word-break: normal;
                overflow-wrap: break-word;
                line-height: 1.35;
            }

            .wp-list-table .column-title .row-title {
                display: inline-block;
                min-width: 220px;
            }

            .wp-list-table .column-wrd_hs_code input.wrd-duty-hs,
            .wp-list-table .column-wrd_hs_origin input.wrd-duty-origin,
            .wp-list-table .column-wrd_hs_profile input.wrd-profile-lookup {
                border-radius: 4px;
                min-height: 30px;
            }

            .wp-list-table .column-wrd_hs_code .wrd-legacy-suggest-wrap {
                line-height: 1.3;
                display: inline-flex;
                align-items: center;
                gap: 4px;
                position: relative;
                flex: 0 0 auto;
            }

            .wp-list-table .column-wrd_hs_code .wrd-hs-inline {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                white-space: nowrap;
                position: relative;
            }

            .wp-list-table .column-wrd_hs_code .wrd-legacy-suggest-toggle {
                color: #646970;
                text-decoration: none;
                padding: 0;
            }

            .wp-list-table .column-wrd_hs_code .wrd-legacy-suggest-toggle .dashicons {
                width: 16px;
                height: 16px;
                font-size: 16px;
                line-height: 1;
                vertical-align: middle;
            }

            .wp-list-table .column-wrd_hs_code .wrd-legacy-suggest-actions {
                display: none;
                align-items: center;
                gap: 5px;
                background: #f6f7f7;
                border: 1px solid #dcdcde;
                border-radius: 4px;
                padding: 2px 6px;
                position: absolute;
                top: calc(100% + 4px);
                right: 0;
                z-index: 20;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
            }

            .wp-list-table .column-wrd_hs_code .wrd-legacy-suggest-wrap.is-open .wrd-legacy-suggest-actions {
                display: inline-flex;
            }

            .wp-list-table .column-wrd_hs_code .wrd-legacy-suggest-cancel {
                color: #646970;
                text-decoration: none;
            }

            .wp-list-table .column-wrd_hs_profile .wrd-duty-row-status {
                margin-left: 8px;
                font-size: 12px;
                color: #646970;
            }

            .wp-list-table tr.wrd-duty-row-saved td {
                background: #f0f8f1;
                transition: background .3s ease;
            }

            .wp-list-table .column-wrd_hs_profile .button {
                vertical-align: middle;
            }

            .wp-list-table .column-wrd_hs_profile .wrd-profile-editor {
                display: none;
                margin-left: 8px;
            }

            .wp-list-table .column-wrd_hs_profile.wrd-profile-editing .wrd-profile-view,
            .wp-list-table .column-wrd_hs_profile.wrd-profile-editing .wrd-profile-edit-toggle {
                display: none;
            }

            .wp-list-table .column-wrd_hs_profile.wrd-profile-editing .wrd-profile-editor {
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }

            .wp-list-table .column-wrd_hs_profile .wrd-profile-edit-toggle {
                text-decoration: none;
                color: #646970;
            }

            .wp-list-table .column-wrd_hs_profile .wrd-profile-edit-toggle .dashicons {
                font-size: 18px;
                line-height: 1;
                width: 18px;
                height: 18px;
                vertical-align: middle;
            }

            .wrd-hs-pill {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 999px;
                font-size: 11px;
                line-height: 1.6;
                border: 1px solid transparent;
                white-space: nowrap;
            }

            .wrd-hs-pill--ok { background: #edf7ed; color: #0f5132; border-color: #b7dfc0; }
            .wrd-hs-pill--warn { background: #fff8e5; color: #8a4b00; border-color: #ffd08a; }
            .wrd-hs-pill--legacy { background: #f6f0ff; color: #5b2f9d; border-color: #d9c6ff; }
            .wrd-hs-pill--error { background: #fff1f0; color: #b42318; border-color: #ffc9c5; }
            .wrd-hs-pill--info { background: #eef6ff; color: #004e98; border-color: #bddbff; }
            .wrd-hs-pill--neutral { background: #f6f7f7; color: #3c434a; border-color: #dcdcde; }
            .wrd-hs-pill--muted { background: #f7f7f7; color: #646970; border-color: #e2e4e7; }
            <?php endif; ?>
        </style>
        <?php
    }

    public function render_product_customs_column($column, $post_id) {
        if ($this->is_hs_manager_catalog_mode()) {
            $this->render_hs_manager_column($column, (int) $post_id);
            return;
        }

        if ($column !== 'wrd_customs') { return; }

        $hs_code = trim((string) get_post_meta($post_id, '_wrd_hs_code', true));
        if ($hs_code === '') {
            $hs_code = trim((string) get_post_meta($post_id, '_hs_code', true));
        }

        $origin = strtoupper(trim((string) get_post_meta($post_id, '_wrd_origin_cc', true)));
        if ($origin === '') {
            $origin = strtoupper(trim((string) get_post_meta($post_id, '_country_of_origin', true)));
        }

        $desc_norm = trim((string) get_post_meta($post_id, '_wrd_desc_norm', true));
        if ($desc_norm === '') {
            $desc_raw = (string) get_post_meta($post_id, '_customs_description', true);
            $desc_norm = $desc_raw !== '' ? WRD_DB::normalize_description($desc_raw) : '';
        }

        $profile_id = (int) get_post_meta($post_id, '_wrd_profile_id', true);
        $status = sanitize_key((string) get_post_meta($post_id, '_wrd_customs_status', true));
        if (!in_array($status, self::PRODUCT_CUSTOMS_STATUSES, true)) {
            $status = self::resolve_customs_status($hs_code, $origin, $desc_norm, $profile_id);
        }

        if ($status === 'ready') {
            echo '<span class="wrd-customs-badge wrd-customs-status-ready" data-status="ready" style="color:#008a00;font-weight:500;">' . esc_html($hs_code) . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
            return;
        }

        if ($status === 'missing_profile') {
            echo '<span class="wrd-customs-badge wrd-customs-status-missing-profile" data-status="missing-profile" style="color:#d98300;font-weight:500;">' . esc_html($hs_code) . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
            echo '<br><span style="color:#d98300;font-size:11px;">' . esc_html__('No profile', 'woocommerce-us-duties') . '</span>';
            return;
        }

        if ($status === 'legacy') {
            echo '<span class="wrd-customs-badge wrd-customs-status-legacy" data-status="legacy" style="color:#d98300;">' . esc_html__('Legacy', 'woocommerce-us-duties') . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
            echo '<br><span style="color:#999;font-size:11px;">' . esc_html__('Needs migration', 'woocommerce-us-duties') . '</span>';
            return;
        }

        if ($status === 'needs_origin') {
            echo '<span class="wrd-customs-badge wrd-customs-status-needs-origin" data-status="needs-origin" style="color:#a00;">' . esc_html__('Missing origin', 'woocommerce-us-duties') . '</span>';
            if ($hs_code !== '') {
                echo ' <span style="color:#666;">(' . esc_html($hs_code) . ')</span>';
            }
            return;
        }

        if ($origin !== '') {
            echo '<span class="wrd-customs-badge wrd-customs-status-needs-hs" data-status="needs-hs" style="color:#a00;">' . esc_html__('Missing HS', 'woocommerce-us-duties') . '</span> <span style="color:#666;">(' . esc_html($origin) . ')</span>';
            return;
        }

        echo '<span class="wrd-customs-badge wrd-customs-status-needs-hs" data-status="needs-hs" style="color:#a00;">' . esc_html__('Missing', 'woocommerce-us-duties') . '</span>';
    }

    private function render_hs_manager_column(string $column, int $post_id): void {
        $ctx = $this->get_hs_manager_row_context($post_id);
        if (!$ctx) { return; }

        if ($column === 'wrd_hs_sku') {
            echo esc_html($ctx['sku']);
            return;
        }

        if ($column === 'wrd_hs_source') {
            echo wp_kses_post($ctx['source_html']);
            return;
        }

        if ($column === 'wrd_hs_code') {
            echo '<span class="wrd-hs-inline">';
            printf(
                '<input type="text" class="wrd-duty-hs" value="%s" placeholder="%s" style="width:140px;" />',
                esc_attr($ctx['local_hs']),
                esc_attr($ctx['effective_hs'])
            );
            if (!empty($ctx['legacy_suggested_hs']) && $ctx['status'] === 'legacy') {
                echo '<div class="wrd-legacy-suggest-wrap">';
                echo '<button type="button" class="button-link wrd-legacy-suggest-toggle" title="' . esc_attr(sprintf(__('Suggested HS: %s', 'woocommerce-us-duties'), (string) $ctx['legacy_suggested_hs'])) . '" aria-label="' . esc_attr__('Show suggested HS', 'woocommerce-us-duties') . '"><span class="dashicons dashicons-lightbulb"></span></button>';
                echo '<span class="wrd-legacy-suggest-actions">';
                echo '<code>' . esc_html((string) $ctx['legacy_suggested_hs']) . '</code> ';
                echo '<button type="button" class="button-link wrd-apply-suggested-hs" data-hs="' . esc_attr((string) $ctx['legacy_suggested_hs']) . '" data-origin="' . esc_attr((string) $ctx['effective_origin']) . '">' . esc_html__('Apply', 'woocommerce-us-duties') . '</button> ';
                echo '<button type="button" class="button-link wrd-legacy-suggest-cancel" aria-label="' . esc_attr__('Hide suggestion', 'woocommerce-us-duties') . '">×</button>';
                echo '</span>';
                echo '</div>';
            }
            echo '</span>';
            return;
        }

        if ($column === 'wrd_hs_origin') {
            printf(
                '<input type="text" class="wrd-duty-origin" maxlength="2" value="%s" placeholder="%s" style="width:72px; text-transform:uppercase;" />',
                esc_attr($ctx['local_origin']),
                esc_attr($ctx['effective_origin'])
            );
            return;
        }

        if ($column === 'wrd_hs_profile') {
            $edit_btn = '<button type="button" class="button-link wrd-profile-edit-toggle" aria-label="' . esc_attr__('Edit profile assignment', 'woocommerce-us-duties') . '"><span class="dashicons dashicons-edit"></span></button>';
            $profile_editor = '<span class="wrd-profile-editor">';
            $profile_editor .= '<input type="text" class="wrd-profile-lookup" placeholder="' . esc_attr__('Search profile…', 'woocommerce-us-duties') . '" />';
            $profile_editor .= ' <button type="button" class="button button-small button-primary wrd-duty-save" data-product-id="' . esc_attr((string) $post_id) . '">' . esc_html__('Apply', 'woocommerce-us-duties') . '</button>';
            $profile_editor .= ' <button type="button" class="button button-small wrd-profile-edit-cancel">' . esc_html__('Cancel', 'woocommerce-us-duties') . '</button>';
            $profile_editor .= '</span>';

            if ($ctx['profile_id'] > 0) {
                echo '<span class="wrd-hs-pill wrd-hs-pill--ok wrd-profile-view">' . esc_html(sprintf(__('Linked #%d', 'woocommerce-us-duties'), (int) $ctx['profile_id'])) . '</span> ' . $edit_btn . ' ' . $profile_editor;
            } else {
                echo '<span class="wrd-hs-pill wrd-hs-pill--warn wrd-profile-view">' . esc_html__('No profile', 'woocommerce-us-duties') . '</span> ' . $edit_btn . ' ' . $profile_editor;
            }
            echo '<span class="wrd-duty-row-status"></span>';
            return;
        }

        if ($column === 'wrd_hs_status') {
            echo wp_kses_post($this->render_hs_manager_status_html($ctx['status']));
            return;
        }

    }

    private function get_hs_manager_row_context(int $post_id): ?array {
        static $cache = [];
        if (isset($cache[$post_id])) { return $cache[$post_id]; }

        $product = wc_get_product($post_id);
        if (!$product) {
            $cache[$post_id] = null;
            return null;
        }

        $effective = WRD_Category_Settings::get_effective_hs_code($product);
        $local_hs = trim((string) $product->get_meta('_hs_code', true));
        $local_origin = strtoupper(trim((string) $product->get_meta('_country_of_origin', true)));
        $effective_hs = trim((string) ($effective['hs_code'] ?? ''));
        $effective_origin = strtoupper(trim((string) ($effective['origin'] ?? '')));
        $effective_desc = $this->get_effective_customs_description($product);
        $source = (string) ($effective['source'] ?? 'none');
        $source_html = $this->format_hs_manager_source($source, $product);
        $status = sanitize_key((string) get_post_meta($post_id, '_wrd_customs_status', true));
        $profile_id = (int) get_post_meta($post_id, '_wrd_profile_id', true);

        if (!in_array($status, self::PRODUCT_CUSTOMS_STATUSES, true)) {
            $desc_norm = trim((string) get_post_meta($post_id, '_wrd_desc_norm', true));
            $status = self::resolve_customs_status($effective_hs, $effective_origin, $desc_norm, $profile_id);
        }

        $legacy_suggested_hs = '';
        if ($status === 'legacy' && $effective_desc !== '' && $effective_origin !== '') {
            $profile = WRD_DB::get_profile($effective_desc, $effective_origin);
            if ($profile && !empty($profile['hs_code'])) {
                $legacy_suggested_hs = trim((string) $profile['hs_code']);
            }
        }

        $cache[$post_id] = [
            'sku' => (string) $product->get_sku(),
            'local_hs' => $local_hs,
            'local_origin' => $local_origin,
            'effective_hs' => $effective_hs,
            'effective_origin' => $effective_origin,
            'source' => $source,
            'source_html' => $source_html,
            'status' => $status,
            'profile_id' => $profile_id,
            'legacy_suggested_hs' => $legacy_suggested_hs,
        ];

        return $cache[$post_id];
    }

    private function render_hs_manager_status_html(string $status): string {
        if ($status === 'ready') {
            return '<span class="wrd-hs-pill wrd-hs-pill--ok">' . esc_html__('Ready', 'woocommerce-us-duties') . '</span>';
        }
        if ($status === 'missing_profile') {
            return '<span class="wrd-hs-pill wrd-hs-pill--warn">' . esc_html__('Missing Profile', 'woocommerce-us-duties') . '</span>';
        }
        if ($status === 'legacy') {
            return '<span class="wrd-hs-pill wrd-hs-pill--legacy">' . esc_html__('Legacy', 'woocommerce-us-duties') . '</span>';
        }
        if ($status === 'needs_origin') {
            return '<span class="wrd-hs-pill wrd-hs-pill--error">' . esc_html__('Needs Origin', 'woocommerce-us-duties') . '</span>';
        }
        return '<span class="wrd-hs-pill wrd-hs-pill--error">' . esc_html__('Needs HS', 'woocommerce-us-duties') . '</span>';
    }

    private function format_hs_manager_source(string $source, WC_Product $product): string {
        if (strpos($source, 'category:') === 0) {
            $label = trim(substr($source, 9));
            if ($label === '') {
                $label = __('Category', 'woocommerce-us-duties');
            }
            return '<span class="wrd-hs-pill wrd-hs-pill--info">' . esc_html(sprintf(__('Category: %s', 'woocommerce-us-duties'), $label)) . '</span>';
        }

        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $parent_hs = trim((string) $parent->get_meta('_hs_code', true));
                $parent_origin = strtoupper(trim((string) $parent->get_meta('_country_of_origin', true)));
                if ($parent_hs !== '' || $parent_origin !== '') {
                    return '<span class="wrd-hs-pill wrd-hs-pill--info">' . esc_html__('Parent', 'woocommerce-us-duties') . '</span>';
                }
            }
        }

        $local_hs = trim((string) $product->get_meta('_hs_code', true));
        $local_origin = strtoupper(trim((string) $product->get_meta('_country_of_origin', true)));
        if ($local_hs !== '' || $local_origin !== '') {
            return '<span class="wrd-hs-pill wrd-hs-pill--neutral">' . esc_html__('Product', 'woocommerce-us-duties') . '</span>';
        }

        return '<span class="wrd-hs-pill wrd-hs-pill--muted">' . esc_html__('None', 'woocommerce-us-duties') . '</span>';
    }

    private function get_effective_customs_description(WC_Product $product): string {
        $desc = trim((string) $product->get_meta('_customs_description', true));
        if ($desc !== '') {
            return $desc;
        }
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                return trim((string) $parent->get_meta('_customs_description', true));
            }
        }
        return '';
    }

    private function get_selected_customs_view_status(): string {
        if (empty($_GET[self::PRODUCT_CUSTOMS_VIEW_QUERY_VAR])) { return ''; }
        $status = sanitize_key(wp_unslash($_GET[self::PRODUCT_CUSTOMS_VIEW_QUERY_VAR]));
        return in_array($status, self::PRODUCT_CUSTOMS_STATUSES, true) ? $status : '';
    }

    private function get_selected_customs_filter_status(): string {
        if (empty($_GET[self::PRODUCT_CUSTOMS_FILTER_QUERY_VAR])) { return ''; }
        $status = sanitize_key(wp_unslash($_GET[self::PRODUCT_CUSTOMS_FILTER_QUERY_VAR]));
        return in_array($status, self::PRODUCT_CUSTOMS_STATUSES, true) ? $status : '';
    }

    private function get_active_customs_status(): string {
        $view_status = $this->get_selected_customs_view_status();
        if ($view_status !== '') { return $view_status; }
        return $this->get_selected_customs_filter_status();
    }

    private function is_hs_manager_catalog_mode(): bool {
        if (empty($_GET[self::PRODUCT_CATALOG_MODE_QUERY_VAR])) { return false; }
        $mode = sanitize_key(wp_unslash($_GET[self::PRODUCT_CATALOG_MODE_QUERY_VAR]));
        return $mode === self::PRODUCT_CATALOG_MODE_HS_MANAGER;
    }

    private function get_product_customs_status_labels(): array {
        return [
            'needs_hs' => __('Needs HS', 'woocommerce-us-duties'),
            'needs_origin' => __('Needs Origin', 'woocommerce-us-duties'),
            'missing_profile' => __('Missing Profile', 'woocommerce-us-duties'),
            'legacy' => __('Legacy', 'woocommerce-us-duties'),
            'ready' => __('Ready', 'woocommerce-us-duties'),
        ];
    }

    private function get_product_customs_view_counts(): array {
        $cached = get_transient(self::PRODUCT_CUSTOMS_COUNT_CACHE_KEY);
        if (is_array($cached)) { return $cached; }

        $counts = [];
        foreach (self::PRODUCT_CUSTOMS_STATUSES as $status) {
            $counts[$status] = $this->count_products_by_customs_status($status);
        }
        set_transient(self::PRODUCT_CUSTOMS_COUNT_CACHE_KEY, $counts, 5 * MINUTE_IN_SECONDS);
        return $counts;
    }

    private function count_products_by_customs_status(string $status): int {
        $q = new WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [$this->build_meta_query_for_customs_status($status)],
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return isset($q->found_posts) ? (int) $q->found_posts : 0;
    }

    private function build_meta_query_for_customs_status(string $status): array {
        $hs_missing = $this->meta_missing_value_clause('_wrd_hs_code');
        $origin_missing = $this->meta_missing_value_clause('_wrd_origin_cc');
        $profile_missing = $this->meta_missing_value_clause('_wrd_profile_id');

        $hs_present = $this->meta_has_value_clause('_wrd_hs_code');
        $origin_present = $this->meta_has_value_clause('_wrd_origin_cc');
        $profile_present = $this->meta_has_value_clause('_wrd_profile_id');
        $desc_present = $this->meta_has_value_clause('_wrd_desc_norm');

        if ($status === 'needs_origin') {
            return $origin_missing;
        }
        if ($status === 'legacy') {
            return [
                'relation' => 'AND',
                $desc_present,
                $origin_present,
                $hs_missing,
            ];
        }
        if ($status === 'missing_profile') {
            return [
                'relation' => 'AND',
                $hs_present,
                $origin_present,
                $profile_missing,
            ];
        }
        if ($status === 'ready') {
            return [
                'relation' => 'AND',
                $hs_present,
                $origin_present,
                $profile_present,
            ];
        }

        return $hs_missing;
    }

    private function meta_has_value_clause(string $meta_key): array {
        return [
            'relation' => 'AND',
            [
                'key' => $meta_key,
                'compare' => 'EXISTS',
            ],
            [
                'key' => $meta_key,
                'value' => '',
                'compare' => '!=',
            ],
        ];
    }

    private function meta_missing_value_clause(string $meta_key): array {
        return [
            'relation' => 'OR',
            [
                'key' => $meta_key,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => $meta_key,
                'value' => '',
                'compare' => '=',
            ],
        ];
    }

    private static function resolve_customs_status(string $hs_code, string $origin, string $desc_norm, int $profile_id): string {
        $hs_code = trim($hs_code);
        $origin = strtoupper(trim($origin));
        $desc_norm = trim($desc_norm);

        if ($hs_code === '') {
            if ($origin !== '' && $desc_norm !== '') {
                return 'legacy';
            }
            return 'needs_hs';
        }

        if ($origin === '') {
            return 'needs_origin';
        }

        if ($profile_id > 0) {
            return 'ready';
        }

        return 'missing_profile';
    }

    private static function resolve_customs_status_rank(string $status): int {
        if ($status === 'needs_hs') { return 10; }
        if ($status === 'needs_origin') { return 20; }
        if ($status === 'legacy') { return 30; }
        if ($status === 'missing_profile') { return 40; }
        if ($status === 'ready') { return 50; }
        return 99;
    }

    private static function sync_customs_status_meta(int $post_id, string $hs_code, string $origin, string $desc_norm, int $profile_id): void {
        $status = self::resolve_customs_status($hs_code, $origin, $desc_norm, $profile_id);
        update_post_meta($post_id, '_wrd_customs_status', $status);
        update_post_meta($post_id, '_wrd_customs_status_rank', self::resolve_customs_status_rank($status));
        delete_transient(self::PRODUCT_CUSTOMS_COUNT_CACHE_KEY);
    }

    // Add "Assign Profile" row action to products
    public function add_assign_profile_row_action($actions, $post) {
        if ($post->post_type !== 'product') {
            return $actions;
        }
        if ($this->is_hs_manager_catalog_mode()) {
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
                    esc_html__('Assign Profile', 'woocommerce-us-duties')
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
                            <legend class="inline-edit-legend"><?php esc_html_e('Assign Profile', 'woocommerce-us-duties'); ?></legend>
                            <div class="inline-edit-col" style="display: flex; gap: 12px; align-items: center;">
                                <label style="flex: 1;">
                                    <span class="title" style="width: auto; display: inline-block; margin-right: 8px;"><?php esc_html_e('Profile', 'woocommerce-us-duties'); ?></span>
                                    <input type="text" class="wrd-profile-lookup" placeholder="<?php esc_attr_e('Search by HS code or description...', 'woocommerce-us-duties'); ?>" style="width: 300px;" />
                                </label>
                                <label>
                                    <span class="title" style="width: auto; display: inline-block; margin-right: 8px;"><?php esc_html_e('HS Code', 'woocommerce-us-duties'); ?></span>
                                    <input type="text" class="wrd-hs-code" placeholder="<?php esc_attr_e('e.g., 8206.00.0000', 'woocommerce-us-duties'); ?>" style="width: 140px;" />
                                </label>
                                <label>
                                    <span class="title" style="width: auto; display: inline-block; margin-right: 8px;"><?php esc_html_e('Country', 'woocommerce-us-duties'); ?></span>
                                    <input type="text" class="wrd-country" placeholder="<?php esc_attr_e('ISO-2', 'woocommerce-us-duties'); ?>" maxlength="2" style="width: 60px;" />
                                </label>
                                <div style="margin-left: auto;">
                                    <button type="button" class="button button-primary wrd-apply-assign"><?php esc_html_e('Apply', 'woocommerce-us-duties'); ?></button>
                                    <button type="button" class="button wrd-cancel-assign"><?php esc_html_e('Cancel', 'woocommerce-us-duties'); ?></button>
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

        self::upsert_product_classification($product_id, [
            'hs_code' => $hs_code,
            'origin' => $country,
        ]);

        // Report whether a profile exists for UI feedback.
        $profile = WRD_DB::get_profile_by_hs_country($hs_code, $country);

        wp_send_json_success([
            'message' => 'Profile assigned successfully',
            'hs_code' => $hs_code,
            'country' => $country,
            'has_profile' => !empty($profile)
        ]);
    }

    /**
     * Canonical write path for product/variation customs data.
     * Supported keys in $changes: hs_code, origin, desc, metal_value_232, metal_mode_232, manufacturer_mid.
     */
    public static function upsert_product_classification(int $product_id, array $changes): bool {
        $product = wc_get_product($product_id);
        if (!$product) { return false; }

        if (array_key_exists('hs_code', $changes)) {
            $product->update_meta_data('_hs_code', trim(sanitize_text_field((string) $changes['hs_code'])));
        }
        if (array_key_exists('origin', $changes)) {
            $product->update_meta_data('_country_of_origin', strtoupper(trim(sanitize_text_field((string) $changes['origin']))));
        }
        if (array_key_exists('desc', $changes)) {
            $desc = trim((string) wp_kses_post($changes['desc']));
            if ($desc === '') {
                $product->delete_meta_data('_customs_description');
            } else {
                $product->update_meta_data('_customs_description', $desc);
            }
        }
        if (array_key_exists('metal_value_232', $changes)) {
            $raw = trim((string)$changes['metal_value_232']);
            if ($raw === '') {
                $product->delete_meta_data('_wrd_232_metal_value_usd');
            } else {
                $value = max(0, (float)$raw);
                $product->update_meta_data('_wrd_232_metal_value_usd', (string)$value);
            }
        }
        if (array_key_exists('metal_mode_232', $changes)) {
            $mode = sanitize_key((string)$changes['metal_mode_232']);
            if (!in_array($mode, ['inherit', 'explicit', 'none'], true)) {
                $mode = 'inherit';
            }
            if ($product->is_type('variation')) {
                $product->update_meta_data('_wrd_232_basis_mode', $mode);
            }
        }
        if (array_key_exists('manufacturer_mid', $changes)) {
            $mid = trim(sanitize_text_field((string)$changes['manufacturer_mid']));
            if ($mid === '') {
                $product->delete_meta_data('_wrd_manufacturer_mid');
            } else {
                $product->update_meta_data('_wrd_manufacturer_mid', $mid);
            }
        }

        $product->save();

        if ($product->is_type('variation')) {
            self::update_normalized_meta_for_variation((int) $product->get_id());
        } else {
            self::update_normalized_meta_for_product((int) $product->get_id());
        }
        return true;
    }

    // --- Quick/Bulk edit fields and handler ---
    public function quick_bulk_edit_box($column_name, $post_type) {
        if ($post_type !== 'product') { return; }
        if (current_filter() === 'bulk_edit_custom_box') { return; }
        static $rendered = [];
        $hook = current_filter();
        if (isset($rendered[$hook])) { return; }
        $rendered[$hook] = true;
        // Render our fields once in the bulk/quick edit panel
        echo '<fieldset class="inline-edit-col-right wrd-customs-fields"><div class="inline-edit-col">';
        echo '<h4>' . esc_html__('Customs', 'woocommerce-us-duties') . '</h4>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Profile (type to search)', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" class="wrd-profile-lookup" placeholder="' . esc_attr__('Search by HS code, country, or description...', 'woocommerce-us-duties') . '" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('HS Code', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_hs_code" value="" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Origin (ISO-2)', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_country_of_origin" value="" maxlength="2" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Description', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_customs_description" value="" /></span>';
        echo '</label>';
        echo '<p class="description">' . esc_html__('Quick Edit values update only this product.', 'woocommerce-us-duties') . '</p>';
        echo '</div></fieldset>';
    }

    public function quick_bulk_edit_panel() {
        $is_bulk = current_filter() === 'woocommerce_product_bulk_edit_start';
        if (!$is_bulk) {
            // Keep quick edit panel lightweight.
            echo '<div class="wrd-customs-inline" style="margin-top:8px;">';
            echo '<strong>' . esc_html__('Customs', 'woocommerce-us-duties') . ':</strong> ';
            echo '<input type="text" class="wrd-profile-lookup" style="min-width:260px" placeholder="' . esc_attr__('Search profile...', 'woocommerce-us-duties') . '" /> ';
            echo '<input type="text" name="wrd_hs_code" placeholder="' . esc_attr__('HS Code', 'woocommerce-us-duties') . '" style="width:120px" /> ';
            echo '<input type="text" name="wrd_country_of_origin" placeholder="' . esc_attr__('ISO-2', 'woocommerce-us-duties') . '" maxlength="2" style="width:60px" /> ';
            echo '<input type="text" name="wrd_customs_description" placeholder="' . esc_attr__('Description', 'woocommerce-us-duties') . '" /> ';
            echo '<input type="number" min="0" step="0.01" name="wrd_232_metal_value_usd" placeholder="' . esc_attr__('232 metal USD', 'woocommerce-us-duties') . '" style="width:130px" /> ';
            echo '<select name="wrd_232_basis_mode" style="width:160px"><option value="">' . esc_html__('232 mode (variation)', 'woocommerce-us-duties') . '</option><option value="inherit">' . esc_html__('inherit', 'woocommerce-us-duties') . '</option><option value="explicit">' . esc_html__('explicit', 'woocommerce-us-duties') . '</option><option value="none">' . esc_html__('none', 'woocommerce-us-duties') . '</option></select> ';
            echo '</div>';
            return;
        }

        // WooCommerce native bulk panel.
        echo '<div class="wrd-customs-bulk-panel">';
        echo '<h4>' . esc_html__('Customs Bulk Actions', 'woocommerce-us-duties') . '</h4>';

        echo '<div class="wrd-bulk-section">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Profile', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" class="wrd-profile-lookup" placeholder="' . esc_attr__('Search by HS code, country, or description...', 'woocommerce-us-duties') . '" /></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wrd-bulk-section">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('232 Metal Value (USD)', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><select name="wrd_232_metal_action" class="wrd-bulk-action">';
        echo '<option value="">' . esc_html__('No change', 'woocommerce-us-duties') . '</option>';
        echo '<option value="set">' . esc_html__('Set to value', 'woocommerce-us-duties') . '</option>';
        echo '<option value="clear">' . esc_html__('Clear', 'woocommerce-us-duties') . '</option>';
        echo '</select></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Value', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="number" min="0" step="0.01" name="wrd_232_metal_value_usd" value="" class="wrd-bulk-value wrd-bulk-value-metal" /></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wrd-bulk-section">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('232 Variation Mode', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><select name="wrd_232_mode_action" class="wrd-bulk-action">';
        echo '<option value="">' . esc_html__('No change', 'woocommerce-us-duties') . '</option>';
        echo '<option value="set">' . esc_html__('Set mode', 'woocommerce-us-duties') . '</option>';
        echo '<option value="clear">' . esc_html__('Reset to inherit', 'woocommerce-us-duties') . '</option>';
        echo '</select></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Mode', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><select name="wrd_232_basis_mode" class="wrd-bulk-value wrd-bulk-value-mode"><option value="inherit">inherit</option><option value="explicit">explicit</option><option value="none">none</option></select></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wrd-bulk-section">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('HS Code', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><select name="wrd_hs_action" class="wrd-bulk-action">';
        echo '<option value="">' . esc_html__('No change', 'woocommerce-us-duties') . '</option>';
        echo '<option value="set">' . esc_html__('Set to value', 'woocommerce-us-duties') . '</option>';
        echo '<option value="clear">' . esc_html__('Clear', 'woocommerce-us-duties') . '</option>';
        echo '<option value="suggest">' . esc_html__('Apply suggested (legacy)', 'woocommerce-us-duties') . '</option>';
        echo '</select></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Value', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_hs_code" value="" class="wrd-bulk-value wrd-bulk-value-hs" /></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wrd-bulk-section">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Origin', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><select name="wrd_origin_action" class="wrd-bulk-action">';
        echo '<option value="">' . esc_html__('No change', 'woocommerce-us-duties') . '</option>';
        echo '<option value="set">' . esc_html__('Set to value', 'woocommerce-us-duties') . '</option>';
        echo '<option value="clear">' . esc_html__('Clear', 'woocommerce-us-duties') . '</option>';
        echo '</select></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Value (ISO-2)', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_country_of_origin" value="" maxlength="2" class="wrd-bulk-value wrd-bulk-value-origin" /></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wrd-bulk-section">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Description', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><select name="wrd_desc_action" class="wrd-bulk-action">';
        echo '<option value="">' . esc_html__('No change', 'woocommerce-us-duties') . '</option>';
        echo '<option value="set">' . esc_html__('Set to value', 'woocommerce-us-duties') . '</option>';
        echo '<option value="clear">' . esc_html__('Clear', 'woocommerce-us-duties') . '</option>';
        echo '</select></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Value', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_customs_description" value="" class="wrd-bulk-value wrd-bulk-value-desc" /></span>';
        echo '</label>';
        echo '</div>';

        echo '<div class="wrd-bulk-section wrd-bulk-safeguard">';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Safeguard', 'woocommerce-us-duties') . '</span>';
        echo '<span class="input-text-wrap"><input type="checkbox" name="wrd_only_empty" value="1" /> ' . esc_html__('Only update empty fields (safe-fill)', 'woocommerce-us-duties') . '</span>';
        echo '</label>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Supports set, clear, and suggested HS actions for selected products.', 'woocommerce-us-duties') . '</p>';
        echo '</div>';
    }

    public function handle_quick_bulk_save($product) {
        if (!$product instanceof WC_Product) { return; }
        $is_bulk = !empty($_REQUEST['bulk_edit']);
        $hs_code = isset($_REQUEST['wrd_hs_code']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['wrd_hs_code']))) : '';
        $desc = isset($_REQUEST['wrd_customs_description']) ? wp_kses_post(wp_unslash($_REQUEST['wrd_customs_description'])) : '';
        $origin = isset($_REQUEST['wrd_country_of_origin']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['wrd_country_of_origin']))) : '';
        $metal_232 = isset($_REQUEST['wrd_232_metal_value_usd']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['wrd_232_metal_value_usd']))) : '';
        $mode_232 = isset($_REQUEST['wrd_232_basis_mode']) ? sanitize_key(wp_unslash($_REQUEST['wrd_232_basis_mode'])) : '';
        $hs_action = isset($_REQUEST['wrd_hs_action']) ? sanitize_key(wp_unslash($_REQUEST['wrd_hs_action'])) : '';
        $origin_action = isset($_REQUEST['wrd_origin_action']) ? sanitize_key(wp_unslash($_REQUEST['wrd_origin_action'])) : '';
        $desc_action = isset($_REQUEST['wrd_desc_action']) ? sanitize_key(wp_unslash($_REQUEST['wrd_desc_action'])) : '';
        $metal_action = isset($_REQUEST['wrd_232_metal_action']) ? sanitize_key(wp_unslash($_REQUEST['wrd_232_metal_action'])) : '';
        $mode_action = isset($_REQUEST['wrd_232_mode_action']) ? sanitize_key(wp_unslash($_REQUEST['wrd_232_mode_action'])) : '';
        $only_empty = !empty($_REQUEST['wrd_only_empty']);

        $changes = [];
        $current_hs = trim((string) $product->get_meta('_hs_code', true));
        $current_origin = strtoupper(trim((string) $product->get_meta('_country_of_origin', true)));
        $current_desc = trim((string) $product->get_meta('_customs_description', true));
        $current_metal = trim((string) $product->get_meta('_wrd_232_metal_value_usd', true));

        if ($is_bulk) {
            if ($hs_action === 'clear') {
                $changes['hs_code'] = '';
            } elseif ($hs_action === 'set' && $hs_code !== '' && (!$only_empty || $current_hs === '')) {
                $changes['hs_code'] = $hs_code;
            } elseif ($hs_action === 'suggest') {
                $suggested_hs = $this->get_suggested_hs_for_product($product);
                if ($suggested_hs !== '' && (!$only_empty || $current_hs === '')) {
                    $changes['hs_code'] = $suggested_hs;
                }
            }

            if ($origin_action === 'clear') {
                $changes['origin'] = '';
            } elseif ($origin_action === 'set' && $origin !== '' && (!$only_empty || $current_origin === '')) {
                $changes['origin'] = $origin;
            }

            if ($desc_action === 'clear') {
                $changes['desc'] = '';
            } elseif ($desc_action === 'set' && $desc !== '' && (!$only_empty || $current_desc === '')) {
                $changes['desc'] = $desc;
            }

            if ($metal_action === 'clear') {
                $changes['metal_value_232'] = '';
            } elseif ($metal_action === 'set' && $metal_232 !== '' && (!$only_empty || $current_metal === '')) {
                $changes['metal_value_232'] = $metal_232;
            }

            if ($product->is_type('variation')) {
                if ($mode_action === 'clear') {
                    $changes['metal_mode_232'] = 'inherit';
                } elseif ($mode_action === 'set' && in_array($mode_232, ['inherit', 'explicit', 'none'], true)) {
                    $changes['metal_mode_232'] = $mode_232;
                }
            }
        } else {
            if ($hs_code !== '') { $changes['hs_code'] = $hs_code; }
            if ($desc !== '') { $changes['desc'] = $desc; }
            if ($origin !== '') { $changes['origin'] = $origin; }
            if ($metal_232 !== '') { $changes['metal_value_232'] = $metal_232; }
            if ($product->is_type('variation') && in_array($mode_232, ['inherit', 'explicit', 'none'], true)) {
                $changes['metal_mode_232'] = $mode_232;
            }
        }

        if ($changes) {
            self::upsert_product_classification((int) $product->get_id(), $changes);
        }
    }

    private function get_suggested_hs_for_product(WC_Product $product): string {
        $effective = WRD_Category_Settings::get_effective_hs_code($product);
        $hs = trim((string) ($effective['hs_code'] ?? ''));
        if ($hs !== '') { return $hs; }
        $origin = strtoupper(trim((string) ($effective['origin'] ?? '')));
        if ($origin === '') { return ''; }
        $desc = $this->get_effective_customs_description($product);
        if ($desc === '') { return ''; }
        $profile = WRD_DB::get_profile($desc, $origin);
        if (!$profile || empty($profile['hs_code'])) { return ''; }
        return trim((string) $profile['hs_code']);
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
                'i18n' => [
                    'no_selection' => __('No products selected for bulk update.', 'woocommerce-us-duties'),
                    'no_actions' => __('No bulk customs actions selected.', 'woocommerce-us-duties'),
                    'preview_prefix' => __('About to update', 'woocommerce-us-duties'),
                    'preview_suffix' => __('products with:', 'woocommerce-us-duties'),
                    'confirm' => __('Continue?', 'woocommerce-us-duties'),
                    'hs_set' => __('HS: set value', 'woocommerce-us-duties'),
                    'hs_clear' => __('HS: clear', 'woocommerce-us-duties'),
                    'hs_suggest' => __('HS: apply suggested (legacy)', 'woocommerce-us-duties'),
                    'origin_set' => __('Origin: set value', 'woocommerce-us-duties'),
                    'origin_clear' => __('Origin: clear', 'woocommerce-us-duties'),
                    'desc_set' => __('Description: set value', 'woocommerce-us-duties'),
                    'desc_clear' => __('Description: clear', 'woocommerce-us-duties'),
                    'only_empty' => __('Only update empty fields', 'woocommerce-us-duties'),
                ],
            ]);

            if ($this->is_hs_manager_catalog_mode()) {
                wp_enqueue_script(
                    'wrd-admin-duty-manager',
                    WRD_US_DUTY_URL . 'assets/admin-duty-manager.js',
                    ['jquery', 'jquery-ui-autocomplete'],
                    WRD_US_DUTY_VERSION,
                    true
                );
                wp_localize_script('wrd-admin-duty-manager', 'WRDDutyManager', [
                    'ajax' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wrd_quick_assign'),
                    'searchNonce' => wp_create_nonce('wrd_search_profiles'),
                    'i18n' => [
                        'missing' => __('HS and Origin are required.', 'woocommerce-us-duties'),
                        'saving' => __('Saving...', 'woocommerce-us-duties'),
                        'saved' => __('Saved', 'woocommerce-us-duties'),
                        'failed' => __('Save failed', 'woocommerce-us-duties'),
                        'linked' => __('Linked', 'woocommerce-us-duties'),
                        'noProfile' => __('No profile', 'woocommerce-us-duties'),
                        'ready' => __('Ready', 'woocommerce-us-duties'),
                        'missingProfile' => __('Missing Profile', 'woocommerce-us-duties'),
                    ],
                ]);
                return;
            }

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
                'i18n' => [
                    'missing_hs_country' => __('Please enter both HS code and country code.', 'woocommerce-us-duties'),
                    'error_prefix' => __('Error:', 'woocommerce-us-duties'),
                    'unknown_error' => __('Unknown error', 'woocommerce-us-duties'),
                    'assign_failed' => __('Failed to assign profile. Please try again.', 'woocommerce-us-duties'),
                ],
            ]);
            return;
        }

        if ($hook === 'woocommerce_page_wrd-duty-manager') {
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_script(
                'wrd-admin-duty-manager',
                WRD_US_DUTY_URL . 'assets/admin-duty-manager.js',
                ['jquery', 'jquery-ui-autocomplete'],
                WRD_US_DUTY_VERSION,
                true
            );
            wp_localize_script('wrd-admin-duty-manager', 'WRDDutyManager', [
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wrd_quick_assign'),
                'searchNonce' => wp_create_nonce('wrd_search_profiles'),
                'i18n' => [
                    'missing' => __('HS and Origin are required.', 'woocommerce-us-duties'),
                    'saving' => __('Saving...', 'woocommerce-us-duties'),
                    'saved' => __('Saved', 'woocommerce-us-duties'),
                    'failed' => __('Save failed', 'woocommerce-us-duties'),
                    'linked' => __('Linked', 'woocommerce-us-duties'),
                    'noProfile' => __('No profile', 'woocommerce-us-duties'),
                    'ready' => __('Ready', 'woocommerce-us-duties'),
                    'missingProfile' => __('Missing Profile', 'woocommerce-us-duties'),
                ],
            ]);
            return;
        }
        
        // Customs hub pages
        if ($hook === 'woocommerce_page_wrd-customs') {
            wp_enqueue_script('jquery');
            // Reconciliation page assets
            if (isset($_GET['tab']) && $_GET['tab'] === 'reconcile') {
                wp_enqueue_style(
                    'wrd-admin-reconcile',
                    WRD_US_DUTY_URL . 'assets/admin-reconcile.css',
                    [],
                    WRD_US_DUTY_VERSION
                );
                wp_enqueue_script(
                    'wrd-admin-reconcile',
                    WRD_US_DUTY_URL . 'assets/admin-reconcile.js',
                    ['jquery'],
                    WRD_US_DUTY_VERSION,
                    true
                );
                wp_localize_script('wrd-admin-reconcile', 'WRDReconcile', [
                    'ajax' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wrd_reconcile_nonce'),
                    'i18n' => [
                        'missing' => __('HS code and origin are required.', 'woocommerce-us-duties'),
                        'missing232' => __('Section 232 metal value is required for this profile.', 'woocommerce-us-duties'),
                        'invalidCountry' => __('Origin must be a 2-letter country code.', 'woocommerce-us-duties'),
                        'saving' => __('Saving...', 'woocommerce-us-duties'),
                        'saved' => __('Saved', 'woocommerce-us-duties'),
                        'bulkSaving' => __('Applying to selected rows...', 'woocommerce-us-duties'),
                        'bulkSaved' => __('Updated %d products.', 'woocommerce-us-duties'),
                        'noSelection' => __('Select at least one row first.', 'woocommerce-us-duties'),
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
        $metal_232 = isset($_POST['metal_value_232']) ? trim(sanitize_text_field(wp_unslash($_POST['metal_value_232']))) : null;
        if ($pid <= 0 || $hs_code === '' || $cc === '') { wp_send_json_error(['message' => 'invalid_params'], 400); }
        $product = wc_get_product($pid);
        if (!$product) { wp_send_json_error(['message' => 'not_found'], 404); }
        $changes = [
            'hs_code' => $hs_code,
            'origin' => $cc,
        ];
        if ($metal_232 !== null) {
            $changes['metal_value_232'] = $metal_232;
        }
        self::upsert_product_classification($pid, $changes);

        // Optionally return matched profile data.
        $profile = WRD_DB::get_profile_by_hs_country($hs_code, $cc);
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

    // Assign HS/origin to multiple products via AJAX (from Reconciliation UI bulk controls)
    public function ajax_reconcile_assign_bulk() {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(['message' => 'forbidden'], 403); }
        check_ajax_referer('wrd_reconcile_nonce', 'nonce');

        $raw_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];
        $product_ids = array_values(array_unique(array_filter(array_map('intval', $raw_ids), function($id) {
            return $id > 0;
        })));
        $hs_code = isset($_POST['hs_code']) ? sanitize_text_field(wp_unslash($_POST['hs_code'])) : '';
        $cc = isset($_POST['cc']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['cc']))) : '';
        $metal_232 = isset($_POST['metal_value_232']) ? trim(sanitize_text_field(wp_unslash($_POST['metal_value_232']))) : null;

        if (empty($product_ids) || $hs_code === '' || $cc === '') {
            wp_send_json_error(['message' => 'invalid_params'], 400);
        }

        $updated = 0;
        $skipped = 0;
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                $skipped++;
                continue;
            }
            $changes = [
                'hs_code' => $hs_code,
                'origin' => $cc,
            ];
            if ($metal_232 !== null && $metal_232 !== '') {
                $changes['metal_value_232'] = $metal_232;
            }
            self::upsert_product_classification($pid, $changes);
            $updated++;
        }

        wp_send_json_success([
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
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
            $counters['error_messages'][] = 'Invalid JSON structure: ' . json_last_error_msg();
            return $counters;
        }

        $mapInput = static function (string $field, string $default = ''): string {
            if (!isset($_POST[$field])) {
                return $default;
            }
            $value = trim(sanitize_text_field(wp_unslash($_POST[$field])));
            return $value !== '' ? $value : $default;
        };

        $mapping = [
            'root' => $mapInput('map_json_root', ''),
            'description' => $mapInput('map_json_description', 'description|zonos_customs_description|customs_description'),
            'country' => $mapInput('map_json_country', 'origin|originCountry|country_of_origin'),
            'hs' => $mapInput('map_json_hs', 'hs_code|hsCode'),
            'postal_rates' => $mapInput('map_json_postal_rates', 'postal.rates'),
            'commercial_rates' => $mapInput('map_json_commercial_rates', 'commercial.rates'),
            'postal_components' => $mapInput('map_json_postal_components', 'postal.components'),
            'commercial_components' => $mapInput('map_json_commercial_components', 'commercial.components'),
            'tariffs' => $mapInput('map_json_tariffs', 'tariffs'),
            'source' => $mapInput('map_json_source', 'source'),
            'fta' => $mapInput('map_json_fta', 'fta_flags'),
            'component_code' => $mapInput('map_json_component_code', 'code|id|key'),
            'component_rate' => $mapInput('map_json_component_rate', 'rate|value'),
            'component_basis' => $mapInput('map_json_component_basis', 'basis|base'),
            'component_order' => $mapInput('map_json_component_order', 'order|priority'),
            'component_label' => 'label|name|description',
            'component_enabled' => 'enabled|active',
        ];
        $ignoreFxRateDuty = !isset($_POST['map_json_ignore_fx_duty']) || !empty($_POST['map_json_ignore_fx_duty']);
        $treatPlaceholderDescBlank = !empty($_POST['map_json_treat_placeholder_desc_blank']);

        $duties = null;
        if ($mapping['root'] !== '') {
            $duties = $this->get_array_path_value($data, $mapping['root']);
        } elseif (isset($data['entries']) && is_array($data['entries'])) {
            $duties = $data['entries'];
        } elseif (isset($data['duties']) && is_array($data['duties'])) {
            $duties = $data['duties'];
        } elseif ($this->is_list_array($data)) {
            $duties = $data;
        }

        if (!is_array($duties)) {
            $counters['errors'] = 1;
            $counters['error_messages'][] = 'Invalid duties JSON: missing duties or entries array.';
            return $counters;
        }

        $effective_from = isset($_POST['effective_from']) ? sanitize_text_field(wp_unslash($_POST['effective_from'])) : date('Y-m-d');
        $replace = !empty($_POST['replace_existing']);
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';
        $sourceRaw = (string)($this->get_first_path_value($data, $mapping['source']) ?? (isset($data['source']) ? $data['source'] : ''));
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

            $description = trim((string)($this->get_first_path_value($entry, $mapping['description']) ?? ''));
            $country = strtoupper(trim((string)($this->get_first_path_value($entry, $mapping['country']) ?? '')));
            $hs = trim((string)($this->get_first_path_value($entry, $mapping['hs']) ?? ''));
            if ($treatPlaceholderDescBlank && $this->is_placeholder_description($description)) {
                $description = '';
            }
            if ($description === '' && $hs !== '') {
                $description = 'HS ' . $hs;
            }

            if ($hs === '' || $country === '') {
                $counters['skipped']++;
                $counters['error_messages'][] = sprintf('Entry %s missing HS code or origin country.', (string)$index);
                continue;
            }

            if (strlen($hs) > 20) { $hs = substr($hs, 0, 20); }

            $entrySourceRaw = (string)($this->get_first_path_value($entry, $mapping['source']) ?? (isset($entry['source']) ? $entry['source'] : ''));
            $entrySource = $entrySourceRaw !== '' ? $this->normalize_profile_source($entrySourceRaw) : $fileSource;

            $postal_rates = [];
            $commercial_rates = [];
            $postal_components = [];
            $commercial_components = [];
            $fta_flags = $this->parse_fta_flags($this->get_first_path_value($entry, $mapping['fta']));

            $postal_rates = $this->parse_rate_map($this->get_first_path_value($entry, $mapping['postal_rates']), $ignoreFxRateDuty);
            $commercial_rates = $this->parse_rate_map($this->get_first_path_value($entry, $mapping['commercial_rates']), $ignoreFxRateDuty);
            if (empty($postal_rates) && isset($entry['postal']['rates'])) {
                $postal_rates = $this->parse_rate_map($entry['postal']['rates'], $ignoreFxRateDuty);
            }
            if (empty($commercial_rates) && isset($entry['commercial']['rates'])) {
                $commercial_rates = $this->parse_rate_map($entry['commercial']['rates'], $ignoreFxRateDuty);
            }

            $postal_components = $this->parse_component_list($this->get_first_path_value($entry, $mapping['postal_components']), $mapping);
            $commercial_components = $this->parse_component_list($this->get_first_path_value($entry, $mapping['commercial_components']), $mapping);
            if (empty($postal_components) && isset($entry['postal']['components'])) {
                $postal_components = $this->parse_component_list($entry['postal']['components'], $mapping);
            }
            if (empty($commercial_components) && isset($entry['commercial']['components'])) {
                $commercial_components = $this->parse_component_list($entry['commercial']['components'], $mapping);
            }

            $tariffs = $this->get_first_path_value($entry, $mapping['tariffs']);
            if (!is_array($tariffs) && isset($entry['tariffs']) && is_array($entry['tariffs'])) {
                $tariffs = $entry['tariffs'];
            }
            if (is_array($tariffs)) {
                $us_duty_rates = $this->parse_tariff_rates($tariffs, $fta_flags);
                if (empty($postal_rates)) {
                    $postal_rates = $us_duty_rates;
                }
                if (empty($commercial_rates)) {
                    $commercial_rates = $us_duty_rates;
                }
            }

            if (empty($postal_components)) {
                $postal_components = $this->derive_components_from_rates($postal_rates);
            }
            if (empty($commercial_components)) {
                $commercial_components = $this->derive_components_from_rates($commercial_rates);
            }

            $us_duty_json_data = [
                'postal' => ['rates' => (object)$postal_rates, 'components' => $postal_components],
                'commercial' => ['rates' => (object)$commercial_rates, 'components' => $commercial_components],
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

    private function is_list_array(array $value): bool {
        if ($value === []) { return true; }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function parse_path_candidates(string $pathSpec): array {
        $parts = preg_split('/[\r\n,|]+/', $pathSpec);
        if (!is_array($parts)) { return []; }
        $out = [];
        foreach ($parts as $part) {
            $path = trim((string)$part);
            if ($path === '') { continue; }
            $out[] = $path;
        }
        return array_values(array_unique($out));
    }

    private function get_array_path_value($data, string $path) {
        $path = trim($path);
        if ($path === '') { return $data; }
        $normalizedPath = str_replace(['[', ']'], ['.', ''], $path);
        $segments = array_values(array_filter(explode('.', $normalizedPath), static function ($segment) {
            return $segment !== '';
        }));
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return null;
            }
            if (array_key_exists($segment, $current)) {
                $current = $current[$segment];
                continue;
            }
            if (ctype_digit($segment)) {
                $index = (int)$segment;
                if (array_key_exists($index, $current)) {
                    $current = $current[$index];
                    continue;
                }
            }
            return null;
        }
        return $current;
    }

    private function get_first_path_value($data, string $pathSpec) {
        $paths = $this->parse_path_candidates($pathSpec);
        foreach ($paths as $path) {
            $value = $this->get_array_path_value($data, $path);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function parse_rate_map($rates, bool $ignoreFxRateDuty = true): array {
        if (is_numeric($rates)) {
            return ['duty' => (float)$rates];
        }
        if (!is_array($rates)) {
            return [];
        }
        $all_rates = [];
        $filtered_rates = [];
        foreach ($rates as $rateKey => $rateValue) {
            $key = sanitize_key((string)$rateKey);
            if ($key === '') {
                $key = 'rate_' . substr(md5((string)$rateKey), 0, 8);
            }

            if (is_numeric($rateValue)) {
                $all_rates[$key] = (float)$rateValue;
                if (!$ignoreFxRateDuty || !$this->is_fx_duty_rate_key($key)) {
                    $filtered_rates[$key] = (float)$rateValue;
                }
            } elseif (is_array($rateValue) && isset($rateValue['duty']) && is_numeric($rateValue['duty'])) {
                $all_rates[$key] = (float)$rateValue['duty'];
                if (!$ignoreFxRateDuty || !$this->is_fx_duty_rate_key($key)) {
                    $filtered_rates[$key] = (float)$rateValue['duty'];
                }
            }
        }
        if (!empty($filtered_rates)) {
            return $filtered_rates;
        }
        return $all_rates;
    }

    private function is_fx_duty_rate_key(string $key): bool {
        if ($key === 'duty') {
            return false;
        }
        return (bool) preg_match('/(^|_)fx(_rate)?_duty($|_)/', $key);
    }

    private function is_placeholder_description(string $description): bool {
        $normalized = strtolower(trim($description));
        if ($normalized === '') {
            return true;
        }
        return in_array($normalized, ['n/a', 'na', 'none', 'null', '-', '--'], true);
    }

    private function parse_component_list($rawComponents, array $mapping): array {
        if (!is_array($rawComponents)) {
            return [];
        }
        $out = [];
        foreach ($rawComponents as $index => $component) {
            if (!is_array($component)) { continue; }
            $code = sanitize_key((string)($this->get_first_path_value($component, $mapping['component_code']) ?? ''));
            if ($code === '') { $code = 'component_' . $index; }
            $rateRaw = $this->get_first_path_value($component, $mapping['component_rate']);
            if (!is_numeric($rateRaw)) { continue; }
            $basisRaw = sanitize_key((string)($this->get_first_path_value($component, $mapping['component_basis']) ?? 'line_value_usd'));
            if (!in_array($basisRaw, ['line_value_usd', 'product_metal_value_usd'], true)) {
                $basisRaw = 'line_value_usd';
            }
            $label = (string)($this->get_first_path_value($component, $mapping['component_label']) ?? $code);
            $order = (int)($this->get_first_path_value($component, $mapping['component_order']) ?? (100 + $index));
            $enabledRaw = $this->get_first_path_value($component, $mapping['component_enabled']);
            $enabled = $enabledRaw === null ? true : (bool)$enabledRaw;

            $out[] = [
                'code' => $code,
                'label' => $label,
                'rate' => (float)$rateRaw,
                'basis' => $basisRaw,
                'order' => $order,
                'enabled' => $enabled,
            ];
        }
        usort($out, static function ($a, $b) {
            return ((int)$a['order']) <=> ((int)$b['order']);
        });
        return $out;
    }

    private function derive_components_from_rates(array $rates): array {
        $out = [];
        foreach ($rates as $key => $value) {
            if (!is_numeric($value)) { continue; }
            $code = sanitize_key((string)$key);
            if ($code === '' || strpos($code, '232') === false) { continue; }
            $out[] = [
                'code' => $code,
                'label' => (string)$key,
                'rate' => (float)$value,
                'basis' => 'product_metal_value_usd',
                'order' => 200,
                'enabled' => true,
            ];
        }
        return $out;
    }

    private function parse_tariff_rates(array $tariffs, array &$fta_flags): array {
        $rates = [];
        foreach ($tariffs as $tariffIndex => $tariff) {
            if (!is_array($tariff) || !isset($tariff['rate']) || !is_numeric($tariff['rate'])) { continue; }
            $rate_value = (float)$tariff['rate'];
            $rate_label = isset($tariff['description']) ? (string)$tariff['description'] : '';
            $fallback_key = isset($tariff['code']) ? (string)$tariff['code'] : '';
            $rate_key_raw = $rate_label !== '' ? $rate_label : $fallback_key;
            $rate_key = sanitize_key($rate_key_raw);
            if ($rate_key === '') {
                $rate_key = 'rate_' . substr(md5($rate_key_raw . $tariffIndex), 0, 8);
            }
            $rates[$rate_key] = $rate_value;

            $tariff_type = isset($tariff['type']) ? strtoupper((string)$tariff['type']) : '';
            $tariff_code = isset($tariff['code']) ? strtoupper((string)$tariff['code']) : '';
            if (($tariff_type === 'CUSMA_ELIGIBLE' || $tariff_code === 'CUSMA') && !in_array('CUSMA', $fta_flags, true)) {
                $fta_flags[] = 'CUSMA';
            }
        }
        return $rates;
    }

    private function parse_fta_flags($flags): array {
        if (is_array($flags)) {
            $out = [];
            foreach ($flags as $flag) {
                $flagText = strtoupper(trim((string)$flag));
                if ($flagText !== '') {
                    $out[] = $flagText;
                }
            }
            return array_values(array_unique($out));
        }
        if (is_string($flags)) {
            $parts = preg_split('/[\s,|]+/', $flags);
            if (!is_array($parts)) { return []; }
            $out = [];
            foreach ($parts as $part) {
                $flagText = strtoupper(trim((string)$part));
                if ($flagText !== '') {
                    $out[] = $flagText;
                }
            }
            return array_values(array_unique($out));
        }
        return [];
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
                self::upsert_product_classification((int) $product_id, ['hs_code' => $hs_code]);
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
            if (!$row) { echo '<p>' . esc_html__('Profile not found.', 'woocommerce-us-duties') . '</p>'; return; }
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
        echo '<tr><th><label>Postal Duty Rate (%)</label></th><td><input type="number" step="0.0001" min="0" max="100" name="simple_postal_pct" value="' . esc_attr($postal_pref) . '" placeholder="e.g., 5.3" /> <span class="description">' . esc_html__('Enter as percentage (e.g., 5.3). Leave blank to manage in Advanced JSON.', 'woocommerce-us-duties') . '</span></td></tr>';
        echo '<tr><th><label>Commercial Duty Rate (%)</label></th><td><input type="number" step="0.0001" min="0" max="100" name="simple_commercial_pct" value="' . esc_attr($comm_pref) . '" placeholder="e.g., 7" /></td></tr>';
        $cusma_checked = in_array('CUSMA', $fta_dec_prefill, true) ? 'checked' : '';
        echo '<tr><th><label>CUSMA</label></th><td><label><input type="checkbox" name="simple_fta_cusma" value="1" ' . $cusma_checked . ' /> ' . esc_html__('Eligible for CUSMA (duty-free into US when applicable)', 'woocommerce-us-duties') . '</label></td></tr>';

        // Advanced JSON editor (collapsible)
        echo '<tr><th><label>Advanced JSON</label></th><td>';
        echo '<details><summary>' . esc_html__('Edit raw US Duty JSON and FTA Flags', 'woocommerce-us-duties') . '</summary>';
        echo '<p><label>FTA Flags (JSON array)</label><br/><textarea name="fta_flags" rows="2" class="large-text code">' . esc_textarea($vals['fta_flags']) . '</textarea></p>';
        echo '<p><label>US Duty JSON</label><br/><textarea name="us_duty_json" rows="8" class="large-text code">' . esc_textarea($vals['us_duty_json']) . '</textarea></p>';
        echo '<p class="description">' . esc_html__('If you modify JSON here, Simple rates will be ignored for those channels.', 'woocommerce-us-duties') . '</p>';
        echo '</details>';
        echo '</td></tr>';
        echo '<tr><th><label>Effective From</label></th><td><input type="date" name="effective_from" value="' . esc_attr($vals['effective_from']) . '" /></td></tr>';
        echo '<tr><th><label>Effective To</label></th><td><input type="date" name="effective_to" value="' . esc_attr($vals['effective_to']) . '" /></td></tr>';
        echo '<tr><th><label>Notes</label></th><td><textarea name="notes" rows="2" class="large-text">' . esc_textarea($vals['notes']) . '</textarea></td></tr>';
        if ($is_edit) { echo '<input type="hidden" name="id" value="' . (int)$vals['id'] . '" />'; }
        submit_button($is_edit ? __('Update', 'woocommerce-us-duties') : __('Create', 'woocommerce-us-duties'));
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
        $header_norm = array_map([self::class, 'normalize_products_csv_header'], $header);

        $map_identifier = strtolower(trim((string)($_POST['map_identifier'] ?? '')));
        $map_hs = strtolower(trim((string)($_POST['map_hs'] ?? '')));
        $map_desc = strtolower(trim((string)($_POST['map_desc'] ?? '')));
        $map_cc = strtolower(trim((string)($_POST['map_cc'] ?? '')));
        $map_s232_applicable = strtolower(trim((string)($_POST['map_s232_applicable'] ?? '')));
        $map_s232_steel = strtolower(trim((string)($_POST['map_s232_steel'] ?? '')));
        $map_s232_aluminum = strtolower(trim((string)($_POST['map_s232_aluminum'] ?? '')));
        $map_s232_copper = strtolower(trim((string)($_POST['map_s232_copper'] ?? '')));
        $map_mid = strtolower(trim((string)($_POST['map_mid'] ?? '')));
        $identifier_type = strtolower(trim((string)($_POST['identifier_type'] ?? 'auto')));
        $dry_run = !empty($_POST['dry_run']);

        $find_idx = function(string $explicit, array $aliases) use ($header_lc, $header_norm): int {
            $candidates = [];
            if ($explicit !== '') {
                $candidates[] = $explicit;
                $candidates[] = self::normalize_products_csv_header($explicit);
            } else {
                foreach ($aliases as $alias) {
                    $candidates[] = strtolower(trim((string)$alias));
                    $candidates[] = self::normalize_products_csv_header((string)$alias);
                }
            }
            foreach (array_values(array_unique($candidates)) as $candidate) {
                if ($candidate === '') { continue; }
                $i = array_search($candidate, $header_lc, true);
                if ($i !== false) { return (int)$i; }
                $i = array_search($candidate, $header_norm, true);
                if ($i !== false) { return (int)$i; }
            }
            return -1;
        };

        $idx_ident = $find_idx($map_identifier, ['product_id','id','sku','product_sku','product sku','item_number','item number']);
        $idx_hs = $find_idx($map_hs, ['hs_code','hs','hscode','tariff_code','tariff code','hs code','hts','harmonized code']);
        $idx_desc = $find_idx($map_desc, ['customs_description','description','customs_desc','customs description','description_for_zonos']);
        $idx_cc = $find_idx($map_cc, ['country_code','country_of_origin','country of origin','origin','country','cc','coo','origin_country']);
        $idx_232_applicable = $find_idx($map_s232_applicable, ['section_232_applicable','section 232 applicable','s232_applicable']);
        $idx_232_steel = $find_idx($map_s232_steel, ['s232_steel_value','s232 steel value','section_232_steel_value']);
        $idx_232_aluminum = $find_idx($map_s232_aluminum, ['s232_aluminum_value','s232 aluminum value','section_232_aluminum_value']);
        $idx_232_copper = $find_idx($map_s232_copper, ['s232_copper_value','s232 copper value','section_232_copper_value']);
        $idx_mid = $find_idx($map_mid, ['manufacturer_id_mid','manufacturer id (mid)','manufacturer_mid','mid']);

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
            $ident = isset($row[$idx_ident]) ? self::normalize_products_csv_identifier((string)$row[$idx_ident]) : '';
            $hs_code = ($idx_hs !== -1 && isset($row[$idx_hs])) ? trim((string)$row[$idx_hs]) : '';
            $desc = ($idx_desc !== -1 && isset($row[$idx_desc])) ? trim((string)$row[$idx_desc]) : '';
            $cc = strtoupper(isset($row[$idx_cc]) ? trim((string)$row[$idx_cc]) : '');
            if ($ident === '') {
                $skipped++;
                $messages[] = "Row {$rows}: missing SKU/Product ID";
                continue;
            }
            if (($hs_code === '' && $desc === '') || $cc === '') {
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
            $changes = ['origin' => $cc];
            if ($hs_code !== '') { $changes['hs_code'] = $hs_code; }
            if ($desc !== '') { $changes['desc'] = $desc; }
            $mid = ($idx_mid !== -1 && isset($row[$idx_mid])) ? trim((string)$row[$idx_mid]) : '';
            if ($mid !== '') {
                $changes['manufacturer_mid'] = $mid;
            }

            $raw_232_applicable = ($idx_232_applicable !== -1 && isset($row[$idx_232_applicable])) ? strtolower(trim((string)$row[$idx_232_applicable])) : '';
            if ($raw_232_applicable !== '') {
                $bool_true = ['1','true','yes','y'];
                $bool_false = ['0','false','no','n'];
                if (in_array($raw_232_applicable, $bool_true, true)) {
                    $s232_total = 0.0;
                    $s232_has_value = false;
                    $value_fields = [
                        'steel' => $idx_232_steel,
                        'aluminum' => $idx_232_aluminum,
                        'copper' => $idx_232_copper,
                    ];
                    foreach ($value_fields as $label => $idx_value) {
                        if ($idx_value === -1 || !isset($row[$idx_value])) { continue; }
                        $raw_value = trim((string)$row[$idx_value]);
                        if ($raw_value === '') { continue; }
                        if (!is_numeric($raw_value)) {
                            $messages[] = "Row {$rows}: invalid S232 {$label} value '{$raw_value}'";
                            continue;
                        }
                        $s232_total += max(0.0, (float)$raw_value);
                        $s232_has_value = true;
                    }
                    if ($s232_has_value) {
                        $changes['metal_value_232'] = (string)$s232_total;
                    } else {
                        $messages[] = "Row {$rows}: Section 232 is TRUE but no valid S232 metal values were provided";
                    }
                    if ($hs_code !== '' && $cc !== '') {
                        $profile = WRD_DB::get_profile_by_hs_country($hs_code, $cc);
                        if (!$profile) {
                            $messages[] = "Row {$rows}: no duty profile found for {$hs_code} ({$cc}); Section 232 metal value imported but no rate component is currently linked";
                        } elseif (!$this->profile_has_section_232($profile)) {
                            $messages[] = "Row {$rows}: profile {$hs_code} ({$cc}) has no Section 232 component/rate; imported metal value will not affect duty until profile rates include 232";
                        }
                    }
                } elseif (in_array($raw_232_applicable, $bool_false, true)) {
                    $changes['metal_value_232'] = '';
                } else {
                    $messages[] = "Row {$rows}: invalid Section 232 Applicable value '{$raw_232_applicable}'";
                }
            }
            self::upsert_product_classification((int) $product->get_id(), $changes);
            $updated++;
        }
        fclose($fh);

        return compact('rows','matched','updated','skipped','errors') + ['dry_run' => $dry_run, 'messages' => $messages];
    }

    private static function normalize_products_csv_header(string $header): string {
        $normalized = strtolower(trim($header));
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        return trim((string)$normalized, '_');
    }

    private static function normalize_products_csv_identifier(string $identifier): string {
        $value = str_replace("\xC2\xA0", ' ', $identifier);
        $value = str_replace(["\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92"], '-', $value);
        $value = preg_replace('/\s+/u', ' ', (string)$value);
        return trim((string)$value);
    }

    // --- Normalized meta helpers and impacted products UI ---

    public function update_normalized_meta_on_save($post_id, $post, $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) { return; }
        if ($post->post_type === 'product') {
            self::update_normalized_meta_for_product((int)$post_id);
        } elseif ($post->post_type === 'product_variation') {
            self::update_normalized_meta_for_variation((int)$post_id);
        }
    }

    public static function update_normalized_meta_for_product(int $product_id): void {
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

        // Keep direct profile links in sync with the canonical HS+origin pair.
        $profile_id = 0;
        if ($hs_code !== '' && $origin !== '') {
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            if ($profile && isset($profile['id'])) {
                $profile_id = (int) $profile['id'];
                update_post_meta($product_id, '_wrd_profile_id', $profile_id);
            } else {
                delete_post_meta($product_id, '_wrd_profile_id');
            }
        } else {
            delete_post_meta($product_id, '_wrd_profile_id');
        }

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
                self::update_normalized_meta_for_variation((int)$vid);
            }
        }

        self::sync_customs_status_meta($product_id, $hs_code, $origin, $descNorm, $profile_id);
    }

    public static function update_normalized_meta_for_variation(int $variation_id): void {
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

        // Sync direct profile link from effective variation values (includes parent fallback).
        $profile_id = 0;
        if ($hs_code !== '' && $origin !== '') {
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            if ($profile && isset($profile['id'])) {
                $profile_id = (int) $profile['id'];
                update_post_meta($variation_id, '_wrd_profile_id', $profile_id);
            } else {
                delete_post_meta($variation_id, '_wrd_profile_id');
            }
        } else {
            delete_post_meta($variation_id, '_wrd_profile_id');
        }

        // If variation has HS + country but no description, pull from profile
        if ($hs_code && $origin && !$desc) {
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            if ($profile && isset($profile['description_raw'])) {
                update_post_meta($variation_id, '_customs_description', $profile['description_raw']);
            }
        }

        self::sync_customs_status_meta($variation_id, $hs_code, $origin, $descNorm, $profile_id);
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
                    self::update_normalized_meta_for_product((int)$pid);
                } else {
                    self::update_normalized_meta_for_variation((int)$pid);
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
        if ($hsCode === '' || $cc === '') { echo '<p>' . esc_html__('Missing filter parameters.', 'woocommerce-us-duties') . '</p>'; return; }

        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-impacted-products-table.php';
        $table = new WRD_Impacted_Products_Table($hsCode, $cc);
        // Handle bulk actions only on POST to avoid conflicting with page query arg 'action=impacted'
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $table->process_bulk_action();
        }
        $table->prepare_items();
        echo '<h2>' . esc_html(sprintf(__('Impacted Products — %s (%s)', 'woocommerce-us-duties'), $hsCode, $cc)) . '</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="page" value="wrd-customs" />';
        echo '<input type="hidden" name="tab" value="profiles" />';
        echo '<input type="hidden" name="hs_code" value="' . esc_attr($hsCode) . '" />';
        echo '<input type="hidden" name="cc" value="' . esc_attr($cc) . '" />';
        $table->search_box(__('Search products', 'woocommerce-us-duties'), 'wrd_impacted');
        $table->display();
        echo '</form>';
    }

    private function render_tab_reconciliation(): void {
        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-reconciliation-table.php';
        echo '<h2>' . esc_html__('Product Reconciliation', 'woocommerce-us-duties') . '</h2>';
        echo '<p class="description">' . esc_html__('Triage product classification quality and resolve missing, unmatched, or warning states from one queue.', 'woocommerce-us-duties') . '</p>';

        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : 'needs_data';
        $type_filter = isset($_GET['rtype']) ? sanitize_key($_GET['rtype']) : 'all';
        $source_filter = isset($_GET['rsource']) ? sanitize_key($_GET['rsource']) : 'all';
        $category_filter = (isset($_GET['rcat']) && is_numeric($_GET['rcat'])) ? (string) max(0, (int) $_GET['rcat']) : 'all';
        $has_active_filters = ($type_filter !== 'all' || $source_filter !== 'all' || $category_filter !== 'all');
        $clear_filters_url = add_query_arg([
            'page' => 'wrd-customs',
            'tab' => 'reconcile',
            'status' => $status,
        ], admin_url('admin.php'));
        $table = new WRD_Reconciliation_Table($status, [
            'type' => $type_filter,
            'source' => $source_filter,
            'category' => $category_filter,
        ]);
        $counts = $table->get_status_counts();

        $tab_defs = [
            'needs_data' => __('Needs Data', 'woocommerce-us-duties'),
            'no_match' => __('No Match', 'woocommerce-us-duties'),
            'warnings' => __('Warnings', 'woocommerce-us-duties'),
            'ready' => __('Ready', 'woocommerce-us-duties'),
            'all' => __('All', 'woocommerce-us-duties'),
        ];

        echo '<h3 class="nav-tab-wrapper" style="margin-top:14px;">';
        foreach ($tab_defs as $key => $label) {
            $url = add_query_arg([
                'page' => 'wrd-customs',
                'tab' => 'reconcile',
                'status' => $key,
                'rtype' => $type_filter,
                'rsource' => $source_filter,
                'rcat' => $category_filter,
            ], admin_url('admin.php'));
            $count = isset($counts[$key]) ? (int) $counts[$key] : 0;
            printf(
                '<a href="%s" class="nav-tab %s">%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $status === $key ? 'nav-tab-active' : '',
                esc_html($label),
                $count
            );
        }
        echo '</h3>';

        $category_options = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        echo '<style>
            .wrd-reconcile-shell { border: 1px solid #dcdcde; border-radius: 4px 4px 0 0; background: #fff; margin: 10px 0 0; }
            .wrd-reconcile-utility-row { display: flex; align-items: center; flex-wrap: nowrap; gap: 8px; padding: 6px 10px; border-bottom: 1px solid #dcdcde; min-height: 0; }
            .wrd-reconcile-utility-row form { margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; width: 100%; min-width: 0; }
            .wrd-reconcile-utility-row--bulk { display: none; }
            .wrd-reconcile-utility-row--bulk.is-active { display: flex; }
            .wrd-reconcile-utility-row--filters.is-hidden { display: none; }
            .wrd-reconcile-utility-row--bulk { flex-wrap: wrap; }
            .wrd-reconcile-inline-fields { display: flex; align-items: center; flex-wrap: nowrap; gap: 8px; min-width: 0; }
            .wrd-reconcile-field { display: inline-flex; align-items: center; gap: 6px; min-width: 0; }
            .wrd-reconcile-field-label { color: #50575e; font-size: 12px; font-weight: 600; white-space: nowrap; }
            .wrd-reconcile-inline-fields select,
            .wrd-reconcile-inline-fields input[type="text"],
            .wrd-reconcile-inline-fields input[type="number"] { min-width: 160px; margin: 0; }
            .wrd-reconcile-inline-actions { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; flex: 0 0 auto; white-space: nowrap; }
            .wrd-reconcile-utility-row--bulk .wrd-reconcile-inline-fields { flex-wrap: wrap; }
            .wrd-reconcile-utility-row--bulk .wrd-reconcile-inline-fields input[type="text"],
            .wrd-reconcile-utility-row--bulk .wrd-reconcile-inline-fields input[type="number"] { min-width: 130px; }
            .wrd-reconcile-utility-row--bulk .wrd-reconcile-inline-actions { margin-left: 0; flex-wrap: wrap; min-width: 0; }
            .wrd-reconcile-clear.button,
            .wrd-reconcile-apply-filters.button,
            #wrd-reconcile-bulk-apply.button { height: 30px; line-height: 28px; margin: 0; }
            .wrd-reconcile-clear.is-hidden { display: none; }
            .wrd-reconcile-selected-count { color: #646970; }
            .wrd-reconcile-bulk-status { min-height: 20px; display: inline-flex; align-items: center; color: #646970; font-size: 12px; min-width: 0; max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .wrd-reconcile-help { margin: 0; color: #646970; font-size: 12px; }
            .wrd-reconcile-table .tablenav.top { margin: 0; padding: 6px 10px; border-bottom: 1px solid #dcdcde; }
            .wrd-reconcile-table .tablenav.bottom { display: none; }
            .wrd-reconcile-table .wp-list-table { margin-top: 0; border-top: 0; }
            @media (max-width: 782px) {
                .wrd-reconcile-utility-row { flex-wrap: wrap; padding: 8px; }
                .wrd-reconcile-utility-row form { flex-wrap: wrap; }
                .wrd-reconcile-inline-fields { flex-wrap: wrap; width: 100%; }
                .wrd-reconcile-field { flex-direction: column; align-items: stretch; width: 100%; }
                .wrd-reconcile-inline-fields select,
                .wrd-reconcile-inline-fields input[type="text"],
                .wrd-reconcile-inline-fields input[type="number"] { min-width: 0; width: 100%; }
                .wrd-reconcile-inline-actions { margin-left: 0; width: 100%; justify-content: flex-start; flex-wrap: wrap; }
            }
        </style>';

        echo '<div class="wrd-reconcile-shell">';
        echo '<div class="wrd-reconcile-utility-row wrd-reconcile-utility-row--filters" id="wrd-reconcile-filter-row">';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" id="wrd-reconcile-filter-form">';
        foreach (['page'=>'wrd-customs','tab'=>'reconcile'] as $k=>$v) { echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '" />'; }
        echo '<input type="hidden" name="status" value="' . esc_attr($status) . '" />';
        echo '<div class="wrd-reconcile-inline-fields">';
        echo '<label class="wrd-reconcile-field"><span class="wrd-reconcile-field-label">' . esc_html__('Type', 'woocommerce-us-duties') . '</span><select name="rtype" id="wrd-rtype">';
        $type_opts = [
            'all' => __('All product types', 'woocommerce-us-duties'),
            'product' => __('Products only', 'woocommerce-us-duties'),
            'variation' => __('Variations only', 'woocommerce-us-duties'),
        ];
        foreach ($type_opts as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($type_filter, $key, false), esc_html($label));
        }
        echo '</select></label>';

        echo '<label class="wrd-reconcile-field"><span class="wrd-reconcile-field-label">' . esc_html__('Source', 'woocommerce-us-duties') . '</span><select name="rsource" id="wrd-rsource">';
        $source_opts = [
            'all' => __('All sources', 'woocommerce-us-duties'),
            'explicit' => __('Explicit values', 'woocommerce-us-duties'),
            'category' => __('Category inherited', 'woocommerce-us-duties'),
            'parent' => __('Parent inherited', 'woocommerce-us-duties'),
            'none' => __('No source values', 'woocommerce-us-duties'),
        ];
        foreach ($source_opts as $key => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($key), selected($source_filter, $key, false), esc_html($label));
        }
        echo '</select></label>';

        echo '<label class="wrd-reconcile-field"><span class="wrd-reconcile-field-label">' . esc_html__('Category', 'woocommerce-us-duties') . '</span><select name="rcat" id="wrd-rcat">';
        echo '<option value="all">' . esc_html__('All categories', 'woocommerce-us-duties') . '</option>';
        if (!is_wp_error($category_options) && is_array($category_options)) {
            foreach ($category_options as $term) {
                if (!$term instanceof WP_Term) { continue; }
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr((string) $term->term_id),
                    selected($category_filter, (string) $term->term_id, false),
                    esc_html($term->name)
                );
            }
        }
        echo '</select></label>';
        echo '<button type="submit" class="button button-secondary wrd-reconcile-apply-filters">' . esc_html__('Apply Filters', 'woocommerce-us-duties') . '</button>';
        echo '<a id="wrd-reconcile-clear-filters" href="' . esc_url($clear_filters_url) . '" class="button wrd-reconcile-clear' . ($has_active_filters ? '' : ' is-hidden') . '">' . esc_html__('Clear Filters', 'woocommerce-us-duties') . '</a>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div class="wrd-reconcile-utility-row wrd-reconcile-utility-row--bulk" id="wrd-reconcile-bulk-row">';
        echo '<div class="wrd-reconcile-inline-fields">';
        echo '<label class="wrd-reconcile-field"><span class="wrd-reconcile-field-label">' . esc_html__('Bulk HS', 'woocommerce-us-duties') . '</span><input type="text" id="wrd-reconcile-bulk-hs" placeholder="' . esc_attr__('HS Code', 'woocommerce-us-duties') . '" /></label>';
        echo '<label class="wrd-reconcile-field"><span class="wrd-reconcile-field-label">' . esc_html__('Bulk Origin', 'woocommerce-us-duties') . '</span><input type="text" id="wrd-reconcile-bulk-cc" maxlength="2" placeholder="' . esc_attr__('ISO-2', 'woocommerce-us-duties') . '" /></label>';
        echo '<label class="wrd-reconcile-field"><span class="wrd-reconcile-field-label">' . esc_html__('Bulk 232 Metal USD', 'woocommerce-us-duties') . '</span><input type="number" id="wrd-reconcile-bulk-metal" min="0" step="0.01" placeholder="' . esc_attr__('Optional', 'woocommerce-us-duties') . '" /></label>';
        echo '</div>';
        echo '<div class="wrd-reconcile-inline-actions"><span class="wrd-reconcile-selected-count">' . sprintf(esc_html__('%s selected', 'woocommerce-us-duties'), '<strong>0</strong>') . '</span><button type="button" id="wrd-reconcile-bulk-apply" class="button button-primary">' . esc_html__('Apply', 'woocommerce-us-duties') . '</button><span class="wrd-reconcile-bulk-status" aria-live="polite" role="status"></span></div>';
        echo '</div>';
        echo '<div class="wrd-reconcile-table">';
        $table->prepare_items();
        $table->display();
        echo '</div>';
        echo '</div>';
    }

    private function profile_has_section_232($profile): bool {
        if (!is_array($profile)) { return false; }
        $udj = isset($profile['us_duty_json']) ? $profile['us_duty_json'] : null;
        if (is_string($udj)) {
            $udj = json_decode($udj, true);
        }
        if (!is_array($udj)) { return false; }
        foreach (['postal', 'commercial'] as $channel) {
            if (!empty($udj[$channel]['components']) && is_array($udj[$channel]['components'])) {
                foreach ($udj[$channel]['components'] as $component) {
                    if (!is_array($component)) { continue; }
                    $code = sanitize_key((string)($component['code'] ?? ''));
                    if (strpos($code, '232') !== false) { return true; }
                }
            }
            if (!empty($udj[$channel]['rates']) && (is_array($udj[$channel]['rates']) || is_object($udj[$channel]['rates']))) {
                $rates = is_object($udj[$channel]['rates']) ? (array)$udj[$channel]['rates'] : $udj[$channel]['rates'];
                foreach ($rates as $rateKey => $value) {
                    if (is_numeric($value) && strpos(sanitize_key((string)$rateKey), '232') !== false) { return true; }
                }
            }
        }
        return false;
    }

    private function get_effective_232_value_for_product(WC_Product $product): ?float {
        if ($product->is_type('variation')) {
            $mode = (string)$product->get_meta('_wrd_232_basis_mode', true);
            if ($mode === 'none') { return 0.0; }
            if ($mode === 'explicit') {
                $val = $product->get_meta('_wrd_232_metal_value_usd', true);
                return is_numeric($val) ? max(0.0, (float)$val) : null;
            }
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof WC_Product) {
                $val = $parent->get_meta('_wrd_232_metal_value_usd', true);
                return is_numeric($val) ? max(0.0, (float)$val) : null;
            }
            return null;
        }
        $val = $product->get_meta('_wrd_232_metal_value_usd', true);
        return is_numeric($val) ? max(0.0, (float)$val) : null;
    }

    private function render_tab_232(): void {
        if (!current_user_can('manage_woocommerce')) { return; }

        $notice = '';
        $notice_class = 'notice-success';
        if (!empty($_POST['wrd_232_bulk_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_232_bulk_nonce'])), 'wrd_232_bulk_update')) {
            $action = isset($_POST['wrd_232_bulk_action']) ? sanitize_key(wp_unslash($_POST['wrd_232_bulk_action'])) : '';
            $mode = isset($_POST['wrd_232_bulk_mode']) ? sanitize_key(wp_unslash($_POST['wrd_232_bulk_mode'])) : 'inherit';
            $value = isset($_POST['wrd_232_bulk_value']) ? trim(sanitize_text_field(wp_unslash($_POST['wrd_232_bulk_value']))) : '';
            $selected = isset($_POST['wrd_232_products']) ? array_map('absint', (array)wp_unslash($_POST['wrd_232_products'])) : [];
            if (!$selected) {
                $notice_class = 'notice-error';
                $notice = __('Select at least one row before applying a bulk update.', 'woocommerce-us-duties');
            } elseif (!in_array($action, ['set_value', 'clear_value', 'set_mode'], true)) {
                $notice_class = 'notice-error';
                $notice = __('Choose a bulk action before applying updates.', 'woocommerce-us-duties');
            } elseif ($action === 'set_value' && $value === '') {
                $notice_class = 'notice-error';
                $notice = __('Enter a metal value before running the “Set metal value” action.', 'woocommerce-us-duties');
            } else {
                $updated = 0;
                foreach ($selected as $pid) {
                    $product = wc_get_product($pid);
                    if (!$product) { continue; }
                    if ($action === 'set_value') {
                        self::upsert_product_classification($pid, ['metal_value_232' => $value]);
                        $updated++;
                    } elseif ($action === 'clear_value') {
                        self::upsert_product_classification($pid, ['metal_value_232' => '']);
                        $updated++;
                    } elseif ($action === 'set_mode' && $product->is_type('variation') && in_array($mode, ['inherit', 'explicit', 'none'], true)) {
                        self::upsert_product_classification($pid, ['metal_mode_232' => $mode]);
                        $updated++;
                    }
                }
                $notice = sprintf(__('Section 232 bulk update completed: %d products updated.', 'woocommerce-us-duties'), (int)$updated);
            }
        }

        $type_filter = isset($_GET['w232_type']) ? sanitize_key(wp_unslash($_GET['w232_type'])) : 'all';
        $status_filter = isset($_GET['w232_status']) ? sanitize_key(wp_unslash($_GET['w232_status'])) : 'all';
        $has_active_filters = ($type_filter !== 'all' || $status_filter !== 'all');
        $clear_filters_url = add_query_arg([
            'page' => 'wrd-customs',
            'tab' => 'section_232',
        ], admin_url('admin.php'));
        $filter_form_action = admin_url('admin.php');
        $post_types = ($type_filter === 'variation') ? ['product_variation'] : (($type_filter === 'product') ? ['product'] : ['product', 'product_variation']);
        $ids = get_posts([
            'post_type' => $post_types,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 500,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'DESC',
        ]);

        $rows = [];
        $counts = ['requires' => 0, 'missing' => 0, 'configured' => 0, 'override' => 0];
        foreach ($ids as $pid) {
            $product = wc_get_product((int)$pid);
            if (!$product) { continue; }
            $effective = WRD_Category_Settings::get_effective_hs_code($product);
            $hs = trim((string)($effective['hs_code'] ?? ''));
            $origin = strtoupper(trim((string)($effective['origin'] ?? '')));
            if ($hs === '' || $origin === '') { continue; }
            $profile = WRD_DB::get_profile_by_hs_country($hs, $origin);
            $requires = $this->profile_has_section_232($profile);
            if (!$requires) { continue; }
            $counts['requires']++;
            $metal = $this->get_effective_232_value_for_product($product);
            $missing = ($metal === null);
            if ($missing) { $counts['missing']++; } else { $counts['configured']++; }
            $mode = $product->is_type('variation') ? (string)$product->get_meta('_wrd_232_basis_mode', true) : 'n/a';
            if ($product->is_type('variation') && $mode === 'explicit') { $counts['override']++; }

            $status = $missing ? 'missing' : 'configured';
            if ($status_filter !== 'all' && $status_filter !== $status) { continue; }
            $rows[] = [
                'id' => (int)$pid,
                'name' => (string)$product->get_name(),
                'type' => $product->is_type('variation') ? 'variation' : 'product',
                'sku' => (string)$product->get_sku(),
                'hs' => $hs,
                'origin' => $origin,
                'metal' => $metal,
                'mode' => $mode !== '' ? $mode : 'inherit',
                'status' => $status,
            ];
        }

        echo '<style>
            .wrd-232-wrap { margin-top: 10px; }
            .wrd-232-intro { margin: 0 0 12px; color: #50575e; }
            .wrd-232-sections { margin: 0 0 12px; }
            .wrd-232-table-shell { border: 1px solid #dcdcde; border-radius: 4px 4px 0 0; background: #fff; }
            .wrd-232-control-row { display: flex; flex-wrap: nowrap; gap: 8px; align-items: center; padding: 6px 10px; border-bottom: 1px solid #dcdcde; min-height: 0; }
            .wrd-232-control-row form { margin: 0; display: flex; align-items: center; gap: 8px; width: 100%; min-width: 0; flex-wrap: nowrap; }
            .wrd-232-control-row--filters { justify-content: space-between; }
            .wrd-232-control-row--bulk { display: none; }
            .wrd-232-control-row--bulk.is-active { display: flex; }
            .wrd-232-control-row--filters.is-hidden { display: none; }
            .wrd-232-inline-fields { display: flex; flex-wrap: nowrap; gap: 8px; align-items: center; min-width: 0; }
            .wrd-232-field { display: inline-flex; align-items: center; gap: 6px; min-width: 0; }
            .wrd-232-field-label { font-size: 12px; color: #50575e; font-weight: 600; white-space: nowrap; }
            .wrd-232-field select, .wrd-232-field input[type="number"] { min-width: 170px; margin: 0; }
            .wrd-232-filters-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; flex: 0 0 auto; }
            .wrd-232-bulk-actions { margin-left: 0; display: inline-flex; align-items: center; gap: 16px; flex: 0 0 auto; white-space: nowrap; }
            .wrd-232-clear-filters.button { height: 30px; line-height: 28px; padding: 0 10px; margin: 0; }
            .wrd-232-apply-filters.button { height: 30px; line-height: 28px; margin: 0; }
            .wrd-232-clear-filters.is-hidden { display: none; }
            .wrd-232-field-label { font-size: 12px; color: #50575e; font-weight: 600; }
            .wrd-232-selected-count { font-size: 12px; color: #50575e; font-weight: 600; }
            .wrd-232-control-row--bulk form,
            .wrd-232-control-row--bulk .wrd-232-inline-fields,
            .wrd-232-control-row--bulk .wrd-232-bulk-actions { gap: 16px; }
            .wrd-232-table { margin-top: 0; border-top: 0; }
            .wrd-232-table th, .wrd-232-table td { vertical-align: middle; }
            .wrd-232-status { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 11px; border: 1px solid; }
            .wrd-232-status--missing { background: #fff1f0; color: #b42318; border-color: #ffc9c5; }
            .wrd-232-status--ok { background: #edf7ed; color: #0f5132; border-color: #b7dfc0; }
            .wrd-232-mode { color: #3c434a; font-size: 12px; }
            .wrd-232-metal-missing { color: #b42318; }
            #wrd-232-apply-bulk:disabled { opacity: .6; cursor: not-allowed; }
            @media (max-width: 782px) {
                .wrd-232-control-row { flex-wrap: wrap; }
                .wrd-232-control-row form { flex-wrap: wrap; }
                .wrd-232-control-row { padding: 8px; }
                .wrd-232-inline-fields { flex-wrap: wrap; width: 100%; }
                .wrd-232-bulk-actions, .wrd-232-filters-actions { width: 100%; }
                .wrd-232-field { flex-direction: column; align-items: stretch; width: 100%; }
                .wrd-232-field select, .wrd-232-field input[type="number"] { min-width: 0; width: 100%; }
                .wrd-232-bulk-actions { margin-left: 0; justify-content: flex-start; }
                .wrd-232-filters-actions { margin-left: 0; justify-content: flex-start; }
            }
        </style>';
        echo '<div class="wrd-232-wrap">';
        echo '<h2>' . esc_html__('Section 232 Management', 'woocommerce-us-duties') . '</h2>';
        echo '<p class="wrd-232-intro">' . esc_html__('Review products that require Section 232 inputs, fix missing metal values, and run controlled bulk updates.', 'woocommerce-us-duties') . '</p>';
        if ($notice !== '') {
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . esc_html($notice) . '</p></div>';
        }
        echo '<div class="wrd-232-sections">';
        echo '<div class="wrd-232-table-shell">';
        echo '<div class="wrd-232-control-row wrd-232-control-row--filters" id="wrd-232-filter-row">';
        echo '<form method="get" action="' . esc_url($filter_form_action) . '" id="wrd-232-filter-form">';
        echo '<input type="hidden" name="page" value="wrd-customs" /><input type="hidden" name="tab" value="section_232" />';
        echo '<div class="wrd-232-inline-fields">';
        echo '<label class="wrd-232-field"><span class="wrd-232-field-label">' . esc_html__('Product type', 'woocommerce-us-duties') . '</span><select id="wrd-232-type-filter" name="w232_type"><option value="all"' . selected($type_filter, 'all', false) . '>' . esc_html__('All types', 'woocommerce-us-duties') . '</option><option value="product"' . selected($type_filter, 'product', false) . '>' . esc_html__('Products', 'woocommerce-us-duties') . '</option><option value="variation"' . selected($type_filter, 'variation', false) . '>' . esc_html__('Variations', 'woocommerce-us-duties') . '</option></select></label>';
        echo '<label class="wrd-232-field"><span class="wrd-232-field-label">' . esc_html__('Status', 'woocommerce-us-duties') . '</span><select id="wrd-232-status-filter" name="w232_status"><option value="all"' . selected($status_filter, 'all', false) . '>' . esc_html__('All statuses', 'woocommerce-us-duties') . '</option><option value="missing"' . selected($status_filter, 'missing', false) . '>' . esc_html__('Missing value', 'woocommerce-us-duties') . '</option><option value="configured"' . selected($status_filter, 'configured', false) . '>' . esc_html__('Configured', 'woocommerce-us-duties') . '</option></select></label>';
        echo '<button type="submit" class="button button-primary wrd-232-apply-filters">' . esc_html__('Apply Filters', 'woocommerce-us-duties') . '</button>';
        echo '<a id="wrd-232-clear-filters" href="' . esc_url($clear_filters_url) . '" class="button wrd-232-clear-filters' . ($has_active_filters ? '' : ' is-hidden') . '">' . esc_html__('Clear Filters', 'woocommerce-us-duties') . '</a>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div class="wrd-232-control-row wrd-232-control-row--bulk" id="wrd-232-bulk-row">';
        echo '<form method="post" id="wrd-232-bulk-form">';
        wp_nonce_field('wrd_232_bulk_update', 'wrd_232_bulk_nonce');
        echo '<input type="hidden" name="page" value="wrd-customs" />';
        echo '<input type="hidden" name="tab" value="section_232" />';
        echo '<div class="wrd-232-inline-fields">';
        echo '<label class="wrd-232-field"><span class="wrd-232-field-label">' . esc_html__('Action', 'woocommerce-us-duties') . '</span><select id="wrd-232-bulk-action" name="wrd_232_bulk_action"><option value="">' . esc_html__('Choose action', 'woocommerce-us-duties') . '</option><option value="set_value">' . esc_html__('Set metal value', 'woocommerce-us-duties') . '</option><option value="clear_value">' . esc_html__('Clear metal value', 'woocommerce-us-duties') . '</option><option value="set_mode">' . esc_html__('Set variation mode', 'woocommerce-us-duties') . '</option></select></label>';
        echo '<label class="wrd-232-field"><span class="wrd-232-field-label">' . esc_html__('Metal value (USD)', 'woocommerce-us-duties') . '</span><input id="wrd-232-bulk-value" type="number" step="0.01" min="0" name="wrd_232_bulk_value" placeholder="' . esc_attr__('Required for “Set metal value”', 'woocommerce-us-duties') . '" /></label>';
        echo '<label class="wrd-232-field"><span class="wrd-232-field-label">' . esc_html__('Variation mode', 'woocommerce-us-duties') . '</span><select id="wrd-232-bulk-mode" name="wrd_232_bulk_mode"><option value="inherit">' . esc_html__('Inherit parent value', 'woocommerce-us-duties') . '</option><option value="explicit">' . esc_html__('Use variation value', 'woocommerce-us-duties') . '</option><option value="none">' . esc_html__('No 232 basis value', 'woocommerce-us-duties') . '</option></select></label>';
        echo '</div>';
        echo '<div class="wrd-232-bulk-actions"><span class="wrd-232-selected-count">0 selected</span><button type="submit" class="button button-primary" name="wrd_232_apply_bulk" id="wrd-232-apply-bulk" disabled="disabled">' . esc_html__('Apply', 'woocommerce-us-duties') . '</button></div>';
        echo '</form>';
        echo '</div>';
        echo '<table class="widefat striped wrd-232-table"><thead><tr><td class="check-column"><input type="checkbox" id="wrd-232-select-all" /></td><th scope="col">' . esc_html__('Product', 'woocommerce-us-duties') . '</th><th scope="col">' . esc_html__('Type', 'woocommerce-us-duties') . '</th><th scope="col">' . esc_html__('SKU', 'woocommerce-us-duties') . '</th><th scope="col">HS</th><th scope="col">' . esc_html__('Origin', 'woocommerce-us-duties') . '</th><th scope="col">' . esc_html__('Metal USD', 'woocommerce-us-duties') . '</th><th scope="col">' . esc_html__('Mode', 'woocommerce-us-duties') . '</th><th scope="col">' . esc_html__('Status', 'woocommerce-us-duties') . '</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="9">' . esc_html__('No Section 232-required records found for the selected filters.', 'woocommerce-us-duties') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                $edit = get_edit_post_link($row['id']);
                echo '<tr>';
                echo '<td><input type="checkbox" class="wrd-232-row" name="wrd_232_products[]" value="' . esc_attr((string)$row['id']) . '" form="wrd-232-bulk-form" /></td>';
                echo '<td><a href="' . esc_url($edit) . '">' . esc_html($row['name']) . '</a></td>';
                echo '<td>' . esc_html($row['type']) . '</td>';
                echo '<td>' . esc_html($row['sku']) . '</td>';
                echo '<td>' . esc_html($row['hs']) . '</td>';
                echo '<td>' . esc_html($row['origin']) . '</td>';
                echo '<td>' . ($row['metal'] === null ? '<span class="wrd-232-metal-missing">—</span>' : esc_html(number_format((float)$row['metal'], 2))) . '</td>';
                echo '<td><span class="wrd-232-mode">' . esc_html((string)$row['mode']) . '</span></td>';
                if ($row['status'] === 'missing') {
                    echo '<td><span class="wrd-232-status wrd-232-status--missing">' . esc_html__('Missing value', 'woocommerce-us-duties') . '</span></td>';
                } else {
                    echo '<td><span class="wrd-232-status wrd-232-status--ok">' . esc_html__('Configured', 'woocommerce-us-duties') . '</span></td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '<script>
            (function($){
                function sync232FilterControls(){
                    var hasFilters = ($("#wrd-232-type-filter").val() || "all") !== "all" || ($("#wrd-232-status-filter").val() || "all") !== "all";
                    $("#wrd-232-clear-filters").toggleClass("is-hidden", !hasFilters);
                }
                function sync232BulkUi(){
                    var selected = $(".wrd-232-row:checked").length;
                    var action = $("#wrd-232-bulk-action").val() || "";
                    var value = $.trim($("#wrd-232-bulk-value").val() || "");
                    var needsValue = (action === "set_value");
                    var validAction = (action === "set_value" || action === "clear_value" || action === "set_mode");
                    var canSubmit = selected > 0 && validAction && (!needsValue || value !== "");
                    var showBulk = selected > 0;
                    $("#wrd-232-apply-bulk").prop("disabled", !canSubmit);
                    $("#wrd-232-bulk-row").toggleClass("is-active", showBulk);
                    $("#wrd-232-filter-row").toggleClass("is-hidden", showBulk);
                    $(".wrd-232-selected-count").text(selected > 0 ? selected + " selected" : "");
                }
                $(document).on("change", "#wrd-232-select-all", function(){
                    $(".wrd-232-row").prop("checked", $(this).is(":checked"));
                    sync232BulkUi();
                });
                $(document).on("change keyup", ".wrd-232-row, #wrd-232-bulk-action, #wrd-232-bulk-value, #wrd-232-bulk-mode", sync232BulkUi);
                $(document).on("change", "#wrd-232-type-filter, #wrd-232-status-filter", sync232FilterControls);
                sync232FilterControls();
                sync232BulkUi();
            })(jQuery);
        </script>';
        echo '</div>';
    }
}
