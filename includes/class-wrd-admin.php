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

        // Admin menu
        add_action('admin_menu', [$this, 'admin_menu']);

        // Products list: customs status column
        add_filter('manage_edit-product_columns', [$this, 'add_product_customs_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_product_customs_column'], 10, 2);

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

        // Redirect legacy admin page slugs
        add_action('admin_init', [$this, 'redirect_legacy_pages']);
    }

    public function product_fields(): void {
        echo '<div class="options_group">';
        woocommerce_wp_text_input([
            'id' => '_customs_description',
            'label' => __('Customs Description', 'woocommerce-us-duties'),
            'desc_tip' => true,
            'description' => __('Commercial description used for customs profile lookup.', 'woocommerce-us-duties'),
        ]);
        woocommerce_wp_text_input([
            'id' => '_country_of_origin',
            'label' => __('Country of Origin (ISO-2)', 'woocommerce-us-duties'),
            'desc_tip' => true,
            'description' => __('ISO-2 country code, e.g., CA, CN, TW.', 'woocommerce-us-duties'),
            'placeholder' => 'CA',
            'maxlength' => 2,
        ]);
        echo '</div>';
    }

    public function save_product_fields($product): void {
        $desc = isset($_POST['_customs_description']) ? wp_kses_post(wp_unslash($_POST['_customs_description'])) : '';
        $origin = isset($_POST['_country_of_origin']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['_country_of_origin']))) : '';
        $product->update_meta_data('_customs_description', $desc);
        $product->update_meta_data('_country_of_origin', $origin);
        $product->save();
        // Update normalized meta on product and inheriting variations
        $this->update_normalized_meta_for_product((int)$product->get_id());
    }

    public function variation_fields($loop, $variation_data, $variation): void {
        woocommerce_wp_text_input([
            'id' => "_customs_description[{$loop}]",
            'label' => __('Customs Description', 'woocommerce-us-duties'),
            'value' => get_post_meta($variation->ID, '_customs_description', true),
        ]);
        woocommerce_wp_text_input([
            'id' => "_country_of_origin[{$loop}]",
            'label' => __('Country of Origin (ISO-2)', 'woocommerce-us-duties'),
            'value' => get_post_meta($variation->ID, '_country_of_origin', true),
            'maxlength' => 2,
        ]);
    }

    public function save_variation_fields($variation_id, $i): void {
        if (isset($_POST['_customs_description'][$i])) {
            update_post_meta($variation_id, '_customs_description', wp_kses_post(wp_unslash($_POST['_customs_description'][$i])));
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

    public function admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Customs & Duties', 'wrd-us-duty'),
            __('Customs & Duties', 'wrd-us-duty'),
            'manage_woocommerce',
            'wrd-customs',
            [$this, 'render_customs_hub']
        );
    }


    public function render_customs_hub(): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        // Early export handling
        if (isset($_GET['action']) && $_GET['action'] === 'export') {
            $this->export_csv();
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Customs & Duties', 'woocommerce-us-duties') . '</h1>';
        $active = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'profiles';
        $tabs = [
            'profiles' => __('Profiles', 'woocommerce-us-duties'),
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
        echo '<form method="post">';
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
        echo '<input type="file" name="wrd_csv" accept=".csv" required /> ';
        submit_button(__('Import', 'woocommerce-us-duties'));
        echo '</form>';

        echo '<h2>' . esc_html__('Import JSON (Zonos dump)', 'woocommerce-us-duties') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-top:8px;">';
        wp_nonce_field('wrd_import_json', 'wrd_import_json_nonce');
        echo '<p><input type="file" name="wrd_json" accept="application/json,.json" required /></p>';
        echo '<p><label>Effective From <input type="date" name="effective_from" value="' . esc_attr(date('Y-m-d')) . '" required /></label></p>';
        echo '<p><label><input type="checkbox" name="replace_existing" value="1" /> ' . esc_html__('Update if profile exists with same description|country and date', 'woocommerce-us-duties') . '</label></p>';
        echo '<p><label>Notes (optional)<br/><input type="text" name="notes" class="regular-text" placeholder="Imported from Zonos" /></label></p>';
        submit_button(__('Import JSON', 'woocommerce-us-duties'));
        echo '</form>';

        // Handle Profiles CSV import
        if (!empty($_POST['wrd_import_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_import_nonce'])), 'wrd_import_csv')) {
            $this->handle_csv_import();
            echo '<div class="updated"><p>' . esc_html__('Profiles CSV import completed.', 'woocommerce-us-duties') . '</p></div>';
        }

        // Handle Zonos JSON import
        if (!empty($_POST['wrd_import_json_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_import_json_nonce'])), 'wrd_import_json')) {
            $json_summary = $this->handle_zonos_json_import();
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
        echo '<p>' . esc_html__('Upload a CSV with columns for product ID or SKU, customs description, and country code. Map field names as needed. Supports dry run.', 'woocommerce-us-duties') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-top:8px;">';
        wp_nonce_field('wrd_products_import', 'wrd_products_import_nonce');
        echo '<p><input type="file" name="wrd_products_csv" accept=".csv" required /></p>';
        echo '<p><label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__('Dry run only (no changes)', 'woocommerce-us-duties') . '</label></p>';
        echo '<p><strong>' . esc_html__('Header Mapping (optional)', 'woocommerce-us-duties') . '</strong><br/>';
        echo esc_html__('Leave blank to autodetect common names like product_id, sku, customs_description, country_code.', 'woocommerce-us-duties') . '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('Product Identifier Header', 'woocommerce-us-duties') . ' <input type="text" name="map_identifier" placeholder="product_id or sku" /></label> ';
        echo '<label>' . esc_html__('Identifier Type', 'woocommerce-us-duties') . ' <select name="identifier_type"><option value="auto">' . esc_html__('Auto', 'woocommerce-us-duties') . '</option><option value="id">' . esc_html__('Product ID', 'woocommerce-us-duties') . '</option><option value="sku">' . esc_html__('SKU', 'woocommerce-us-duties') . '</option></select></label>';
        echo '</p>';
        echo '<p>';
        echo '<label>' . esc_html__('Customs Description Header', 'woocommerce-us-duties') . ' <input type="text" name="map_desc" placeholder="customs_description" /></label> ';
        echo '<label>' . esc_html__('Country Code Header', 'woocommerce-us-duties') . ' <input type="text" name="map_cc" placeholder="country_code" /></label>';
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
                $new['wrd_customs'] = __('US Duty (est.)', 'wrd-us-duty');
            }
        }
        if (!isset($new['wrd_customs'])) {
            $new['wrd_customs'] = __('US Duty (est.)', 'wrd-us-duty');
        }
        return $new;
    }

    public function render_product_customs_column($column, $post_id) {
        if ($column !== 'wrd_customs') { return; }
        // Try product-level first
        $desc = get_post_meta($post_id, '_customs_description', true);
        $origin = strtoupper(trim((string) get_post_meta($post_id, '_country_of_origin', true)));

        // Helper to echo statuses consistently
        $echo_missing = function() {
            echo '<span style="color:#a00;">' . esc_html__('Missing', 'wrd-us-duty') . '</span>';
        };
        $echo_rate = function($ratePct, $channel) {
            // Format like 5.3% (commercial)
            $rate = is_numeric($ratePct) ? round((float)$ratePct, 1) : 0.0;
            // strip trailing .0
            $rate_str = (abs($rate - (int)$rate) < 0.05) ? (string)((int)$rate) : number_format($rate, 1);
            $channel = $channel ? strtolower((string)$channel) : '';
            $suffix = $channel !== '' ? ' (' . esc_html($channel) . ')' : '';
            echo '<span style="color:#008a00;">' . esc_html($rate_str . '%') . $suffix . '</span>';
        };
        $echo_no_profile = function() {
            echo '<span style="color:#d98300;">' . esc_html__('No profile', 'wrd-us-duty') . '</span>';
        };

        // Simple per-request cache for profiles by normalized key
        static $profile_cache = [];
        $get_profile = function($raw_desc, $cc) use (&$profile_cache) {
            $norm = WRD_DB::normalize_description((string)$raw_desc);
            $ccU = strtoupper(trim((string)$cc));
            $key = $norm . '|' . $ccU;
            if (!array_key_exists($key, $profile_cache)) {
                $profile_cache[$key] = WRD_DB::get_profile((string)$raw_desc, $ccU);
            }
            return $profile_cache[$key];
        };

        $product_match = null; // null = no data, true/false = matched/not
        if ($desc && $origin) {
            $product_match = (bool) $get_profile($desc, $origin);
        }

        // Check variations (for variable products) regardless, to surface a match if any variant is valid
        $post_type = get_post_type($post_id);
        if ($post_type === 'product') {
            $children = get_children([
                'post_parent' => (int) $post_id,
                'post_type' => 'product_variation',
                'post_status' => 'any',
                'fields' => 'ids',
            ]);
            if ($children) {
                $found_any = false; $any_matched = false; $cc_seen = '';
                foreach ($children as $vid) {
                    $vdesc = get_post_meta($vid, '_customs_description', true);
                    $vorigin = strtoupper(trim((string) get_post_meta($vid, '_country_of_origin', true)));
                    // Inherit from parent if missing
                    if ($vdesc === '' && $desc !== '') { $vdesc = $desc; }
                    if ($vorigin === '' && $origin !== '') { $vorigin = $origin; }
                    if ($vdesc && $vorigin) {
                        $found_any = true; $cc_seen = $cc_seen ?: $vorigin;
                        $prof = $get_profile($vdesc, $vorigin);
                        if ($prof) { $any_matched = true; break; }
                    }
                }
                if ($any_matched) {
                    $cc_out = $cc_seen ?: $origin;
                    $prof = $get_profile($vdesc, $cc_out);
                    $channel = WRD_Duty_Engine::decide_channel($cc_out);
                    $ratePct = is_array($prof) ? WRD_Duty_Engine::compute_rate_percent((array)$prof['us_duty_json'], $channel) : 0.0;
                    $echo_rate($ratePct, $channel);
                    return;
                }
                // If we didn't find a variant match but we had variant data, remember that
                if ($found_any) {
                    // If product-level is matched, we'll prefer matched below; otherwise show no profile
                    if ($product_match === null) { $echo_no_profile(); return; }
                }
            }
        }
        // Prefer product-level if it exists
        if ($product_match === true) {
            $prof = $get_profile($desc, $origin);
            $channel = WRD_Duty_Engine::decide_channel($origin);
            $ratePct = is_array($prof) ? WRD_Duty_Engine::compute_rate_percent((array)$prof['us_duty_json'], $channel) : 0.0;
            $echo_rate($ratePct, $channel);
            return;
        }
        if ($product_match === false) { $echo_no_profile(); return; }
        $echo_missing();
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
        echo '<span class="input-text-wrap"><input type="text" class="wrd-profile-lookup" placeholder="e.g., Vulcanized rubber suction pad (TW)" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Description', 'wrd-us-duty') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_customs_description" value="" /></span>';
        echo '</label>';
        echo '<label class="inline-edit-group">';
        echo '<span class="title">' . esc_html__('Origin (ISO-2)', 'wrd-us-duty') . '</span>';
        echo '<span class="input-text-wrap"><input type="text" name="wrd_country_of_origin" value="" maxlength="2" /></span>';
        echo '</label>';
        echo '<p class="description">' . esc_html__('Leave blank to keep existing values. Applies to selected items for Bulk Edit.', 'wrd-us-duty') . '</p>';
        echo '</div></fieldset>';
    }

    public function quick_bulk_edit_panel() {
        // Secondary render inside WC panels for robustness (bulk editor UI)
        echo '<div class="wrd-customs-inline" style="margin-top:8px;">';
        echo '<strong>' . esc_html__('Customs', 'wrd-us-duty') . ':</strong> ';
        echo '<input type="text" class="wrd-profile-lookup" style="min-width:260px" placeholder="Search profile..." /> ';
        echo '<input type="text" name="wrd_customs_description" placeholder="Description" /> ';
        echo '<input type="text" name="wrd_country_of_origin" placeholder="CC" maxlength="2" style="width:60px" /> ';
        echo '</div>';
    }

    public function handle_quick_bulk_save($product) {
        if (!$product instanceof WC_Product) { return; }
        // Use same fields for both quick and bulk edit
        $desc = isset($_REQUEST['wrd_customs_description']) ? wp_kses_post(wp_unslash($_REQUEST['wrd_customs_description'])) : '';
        $origin = isset($_REQUEST['wrd_country_of_origin']) ? strtoupper(sanitize_text_field(wp_unslash($_REQUEST['wrd_country_of_origin']))) : '';

        $changed = false;
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
            return;
        }
        
        // Customs hub pages
        if ($hook === 'woocommerce_page_wrd-customs') {
            wp_enqueue_script('jquery');
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
             WHERE description_raw LIKE %s OR description_normalized LIKE %s OR country_code LIKE %s
             GROUP BY description_raw, country_code, hs_code
             ORDER BY description_raw ASC
             LIMIT 20",
            $like, $like, $like
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $text = $r['description_raw'] . ' (' . strtoupper($r['country_code']) . ')';
            if (!empty($r['hs_code'])) { $text .= ' — HS ' . $r['hs_code']; }
            $out[] = [
                'id' => $r['description_raw'] . '|' . strtoupper($r['country_code']),
                'label' => $text,
                'value' => $text,
                'desc' => $r['description_raw'],
                'cc' => strtoupper($r['country_code']),
            ];
        }
        wp_send_json($out);
    }

    private function redirect_legacy_pages(): void {
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

    private function handle_csv_import(): void {
        if (empty($_FILES['wrd_csv']) || !is_uploaded_file($_FILES['wrd_csv']['tmp_name'])) { return; }
        $fh = fopen($_FILES['wrd_csv']['tmp_name'], 'r');
        if (!$fh) { return; }
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return; }
        $map = array_flip($header);
        global $wpdb;
        $table = WRD_DB::table_profiles();

        while (($row = fgetcsv($fh)) !== false) {
            $description = $row[$map['description']] ?? '';
            $country = strtoupper(trim($row[$map['country_code']] ?? ''));
            $hs = $row[$map['hs_code']] ?? '';
            $fta = $row[$map['fta_flags']] ?? '[]';
            $udj = $row[$map['us_duty_json']] ?? '{}';
            $from = $row[$map['effective_from']] ?? date('Y-m-d');
            $to = $row[$map['effective_to']] ?? null;
            $notes = $row[$map['notes']] ?? null;

            $descNorm = WRD_DB::normalize_description($description);

            $wpdb->insert(
                $table,
                [
                    'description_raw' => $description,
                    'description_normalized' => $descNorm,
                    'country_code' => $country,
                    'hs_code' => $hs,
                    'us_duty_json' => $udj,
                    'fta_flags' => $fta,
                    'effective_from' => $from,
                    'effective_to' => $to ?: null,
                    'notes' => $notes,
                ],
                ['%s','%s','%s','%s','%s','%s','%s','%s','%s']
            );
        }
        fclose($fh);
    }

    private function handle_zonos_json_import(): ?array {
        if (empty($_FILES['wrd_json']) || !is_uploaded_file($_FILES['wrd_json']['tmp_name'])) { return null; }
        $json = file_get_contents($_FILES['wrd_json']['tmp_name']);
        if ($json === false) { return null; }
        $data = json_decode($json, true);
        if (!is_array($data)) { return ['inserted'=>0,'updated'=>0,'skipped'=>0,'errors'=>1]; }

        $effective_from = isset($_POST['effective_from']) ? sanitize_text_field(wp_unslash($_POST['effective_from'])) : date('Y-m-d');
        $replace = !empty($_POST['replace_existing']);
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : 'Imported from Zonos';

        global $wpdb; $table = WRD_DB::table_profiles();
        $counters = ['inserted'=>0,'updated'=>0,'skipped'=>0,'errors'=>0, 'error_messages'=>[]];

        foreach ($data as $key => $entry) {
            // Expect key format: Description|CC
            $parts = explode('|', (string)$key, 2);
            if (count($parts) !== 2) { $counters['skipped']++; continue; }
            [$description, $country] = $parts;
            $description = trim((string)$description);
            $country = strtoupper(trim((string)$country));
            if ($description === '' || $country === '') { $counters['skipped']++; continue; }

            $hs = (string)($entry['hs_code'] ?? '');
            // Accept dotted HS (e.g., 5607.49.1500). Keep raw, but also ensure length fits schema.
            if (strlen($hs) > 20) { $hs = substr($hs, 0, 20); }

            // Build us_duty_json preserving channels present
            $udj = [];
            foreach (['postal','commercial'] as $ch) {
                if (!empty($entry[$ch]['rates']) && is_array($entry[$ch]['rates'])) {
                    $udj[$ch] = [ 'rates' => (object)$entry[$ch]['rates'] ];
                }
            }

            $descNorm = WRD_DB::normalize_description($description);
            $fta = [];
            $dataRow = [
                'description_raw' => $description,
                'description_normalized' => $descNorm,
                'country_code' => $country,
                'hs_code' => $hs,
                'us_duty_json' => wp_json_encode($udj, JSON_UNESCAPED_SLASHES),
                'fta_flags' => wp_json_encode($fta),
                'effective_from' => $effective_from,
                'effective_to' => null,
                'notes' => $notes,
            ];

            // Upsert logic on (desc_norm, country, effective_from)
            $existing_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE description_normalized=%s AND country_code=%s AND effective_from=%s LIMIT 1",
                $descNorm, $country, $effective_from
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

    private function render_profile_form(): void {
        global $wpdb; $table = WRD_DB::table_profiles();
        $is_edit = (isset($_GET['action']) && $_GET['action'] === 'edit');
        $row = null;
        if ($is_edit) {
            $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
            if ($id) { $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A); }
            if (!$row) { echo '<p>' . esc_html__('Profile not found.', 'wrd-us-duty') . '</p>'; return; }
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
        echo '<tr><th><label>FTA Flags (JSON array)</label></th><td><textarea name="fta_flags" rows="2" class="large-text code">' . esc_textarea($vals['fta_flags']) . '</textarea></td></tr>';
        echo '<tr><th><label>US Duty JSON</label></th><td><textarea name="us_duty_json" rows="8" class="large-text code">' . esc_textarea($vals['us_duty_json']) . '</textarea></td></tr>';
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
        $udj = isset($_POST['us_duty_json']) ? wp_unslash($_POST['us_duty_json']) : '{}';
        $from = isset($_POST['effective_from']) ? sanitize_text_field(wp_unslash($_POST['effective_from'])) : date('Y-m-d');
        $to = isset($_POST['effective_to']) ? sanitize_text_field(wp_unslash($_POST['effective_to'])) : '';
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';

        // Validate JSON columns
        $fta_dec = json_decode($fta, true);
        if ($fta_dec === null && json_last_error() !== JSON_ERROR_NONE) {
            $fta_dec = [];
        }
        $udj_dec = json_decode($udj, true);
        if ($udj_dec === null && json_last_error() !== JSON_ERROR_NONE) {
            $udj_dec = ['postal' => ['rates' => new stdClass()], 'commercial' => ['rates' => new stdClass()]];
        }

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

    private function handle_products_csv_import(): ?array {
        if (empty($_FILES['wrd_products_csv']) || !is_uploaded_file($_FILES['wrd_products_csv']['tmp_name'])) { return null; }
        $fh = fopen($_FILES['wrd_products_csv']['tmp_name'], 'r');
        if (!$fh) { return null; }
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return null; }
        $header_lc = array_map(function($h){ return strtolower(trim((string)$h)); }, $header);

        $map_identifier = strtolower(trim((string)($_POST['map_identifier'] ?? '')));
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
        $idx_desc = $map_desc !== '' ? array_search($map_desc, $header_lc, true) : $auto_idx(['customs_description','description','customs_desc']);
        $idx_cc = $map_cc !== '' ? array_search($map_cc, $header_lc, true) : $auto_idx(['country_code','origin','country','cc']);

        $messages = [];
        if ($idx_ident === -1) { $messages[] = 'Identifier header not found.'; }
        if ($idx_desc === -1) { $messages[] = 'Customs description header not found.'; }
        if ($idx_cc === -1) { $messages[] = 'Country code header not found.'; }
        if ($idx_ident === -1 || $idx_desc === -1 || $idx_cc === -1) {
            fclose($fh);
            return ['rows'=>0,'matched'=>0,'updated'=>0,'skipped'=>0,'errors'=>1,'messages'=>$messages];
        }

        $rows = $matched = $updated = $skipped = $errors = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $rows++;
            $ident = isset($row[$idx_ident]) ? trim((string)$row[$idx_ident]) : '';
            $desc = isset($row[$idx_desc]) ? trim((string)$row[$idx_desc]) : '';
            $cc = strtoupper(isset($row[$idx_cc]) ? trim((string)$row[$idx_cc]) : '');
            if ($ident === '' || $desc === '' || $cc === '') { $skipped++; $messages[] = "Row {$rows}: missing field(s)"; continue; }

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
            $product->update_meta_data('_customs_description', wp_kses_post($desc));
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
        $desc = get_post_meta($product_id, '_customs_description', true);
        $origin = strtoupper((string)get_post_meta($product_id, '_country_of_origin', true));
        $descNorm = $desc ? WRD_DB::normalize_description($desc) : '';
        update_post_meta($product_id, '_wrd_desc_norm', $descNorm);
        update_post_meta($product_id, '_wrd_origin_cc', $origin);

        // Update variations that inherit
        $children = get_children([
            'post_parent' => $product_id,
            'post_type' => 'product_variation',
            'post_status' => 'any',
            'fields' => 'ids',
        ]);
        foreach ($children as $vid) {
            $vdesc = get_post_meta($vid, '_customs_description', true);
            $vorigin = strtoupper((string)get_post_meta($vid, '_country_of_origin', true));
            if ($vdesc === '' || $vorigin === '') {
                $this->update_normalized_meta_for_variation((int)$vid);
            }
        }
    }

    private function update_normalized_meta_for_variation(int $variation_id): void {
        $parent_id = (int) get_post_field('post_parent', $variation_id);
        $desc = get_post_meta($variation_id, '_customs_description', true);
        $origin = strtoupper((string)get_post_meta($variation_id, '_country_of_origin', true));
        if ($desc === '' && $parent_id) { $desc = get_post_meta($parent_id, '_customs_description', true); }
        if ($origin === '' && $parent_id) { $origin = strtoupper((string)get_post_meta($parent_id, '_country_of_origin', true)); }
        $descNorm = $desc ? WRD_DB::normalize_description($desc) : '';
        update_post_meta($variation_id, '_wrd_desc_norm', $descNorm);
        update_post_meta($variation_id, '_wrd_origin_cc', $origin);
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
        $descNorm = isset($_GET['desc_norm']) ? sanitize_text_field(wp_unslash($_GET['desc_norm'])) : '';
        $cc = isset($_GET['cc']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['cc']))) : '';
        if ($descNorm === '' || $cc === '') { echo '<p>' . esc_html__('Missing filter parameters.', 'wrd-us-duty') . '</p>'; return; }

        require_once WRD_US_DUTY_DIR . 'includes/admin/class-wrd-impacted-products-table.php';
        $table = new WRD_Impacted_Products_Table($descNorm, $cc);
        // Handle bulk actions only on POST to avoid conflicting with page query arg 'action=impacted'
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $table->process_bulk_action();
        }
        $table->prepare_items();
        echo '<h2>' . esc_html(sprintf(__('Impacted Products — %s (%s)', 'wrd-us-duty'), $descNorm, $cc)) . '</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="page" value="wrd-customs" />';
        echo '<input type="hidden" name="tab" value="profiles" />';
        echo '<input type="hidden" name="desc_norm" value="' . esc_attr($descNorm) . '" />';
        echo '<input type="hidden" name="cc" value="' . esc_attr($cc) . '" />';
        $table->search_box(__('Search products', 'wrd-us-duty'), 'wrd_impacted');
        $table->display();
        echo '</form>';
    }
}
