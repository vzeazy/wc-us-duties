<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Settings {
    const OPTION = 'wrd_us_duty_settings';

    public function init(): void {
        // No top-level menu; settings render within WRD Admin tab UI
    }

    public function render_settings_fields(bool $wrap = false): void {
        if (!current_user_can('manage_woocommerce')) { return; }
        $saved = false;
        if (!empty($_POST['wrd_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wrd_settings_nonce'])), 'wrd_save_settings')) {
            $opt = self::sanitize_settings($_POST);
            update_option(self::OPTION, $opt);
            $saved = true;
            delete_transient('wrd_fx_rates_' . ($opt['fx_provider'] ?? 'exchangerate_host') . '_USD');
        }

        $opt = get_option(self::OPTION, []);
        $opt = wp_parse_args(is_array($opt) ? $opt : [], [
            'ddp_mode' => 'charge', // charge | info
            'fee_label' => 'Estimated US Duties',
            'us_only' => 1,
            'missing_profile_behavior' => 'fallback',
            'cusma_auto' => 1,
            'cusma_countries' => 'CA,US',
            'min_split_savings' => 0,
            'postal_informal_threshold_usd' => 2500,
            'postal_clearance_fee_usd' => 0,
            'commercial_brokerage_flat_usd' => 0,
            'preferred_duty_source' => 'zonos_first',
            'fx_enabled' => 1,
            'fx_provider' => 'exchangerate_host',
            'fx_refresh_hours' => 12,
            'shipping_channel_rules' => "USPS|postal\nCanada Post|postal\nPurolator|commercial\nUPS|commercial\nFedEx|commercial\nStallion|commercial",
            'details_mode' => 'inline', // none | inline
            'shipping_channel_map' => [],
            'inline_cart_line' => 0,
            'inline_checkout_line' => 0,
            'debug_mode' => 0,
            'default_shipping_channel' => 'auto',
            'product_hint_enabled' => 0,
            'product_hint_position' => 'under_price',
            'product_hint_note' => '',
            'duty_padding_pct' => 0,
            'duty_padding_rules_json' => '',
        ]);

        if ($wrap) { echo '<div class="wrap">'; echo '<h1>' . esc_html__('Routing & Fees', 'woocommerce-us-duties') . '</h1>'; }
        if ($saved) { echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'woocommerce-us-duties') . '</p></div>'; }
        
        echo '<style>
            .wrd-settings-wrap { display: flex; gap: 24px; margin-top: 16px; align-items: flex-start; }
            .wrd-settings-sidebar { width: 220px; flex-shrink: 0; position: sticky; top: 40px; }
            .wrd-settings-nav { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 8px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
            .wrd-settings-nav a { display: block; padding: 10px 16px; text-decoration: none; color: #1d2327; font-weight: 500; border-left: 4px solid transparent; transition: all 0.15s ease; }
            .wrd-settings-nav a:hover { background: #f6f7f7; color: #2271b1; }
            .wrd-settings-nav a.active { border-left-color: #2271b1; background: #f0f6fc; color: #2271b1; }
            .wrd-settings-content { flex-grow: 1; max-width: 900px; }
            .wrd-settings-section { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 1px rgba(0,0,0,.04); display: none; }
            .wrd-settings-section.active { display: block; }
            .wrd-settings-section h2 { margin-top: 0; padding-bottom: 16px; border-bottom: 1px solid #f0f0f1; font-size: 1.3em; font-weight: 600; margin-bottom: 16px; }
            .wrd-settings-section .form-table { margin-top: 0; }
            .wrd-settings-section .form-table th { padding: 16px 24px 16px 0; width: 240px; font-weight: 600; }
            .wrd-settings-section .form-table td { padding: 16px 0; }
            .wrd-settings-section p.description { color: #646970; font-style: normal; margin-top: 6px; font-size: 13px; }
            .wrd-inline-group { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 8px; }
            .wrd-inline-group label { display: flex; align-items: center; gap: 6px; font-weight: 500; }
            .wrd-save-bar { margin-top: 24px; padding-top: 16px; }
        </style>';

        echo '<script>
            jQuery(function($){
                $(".wrd-settings-nav a").on("click", function(e){
                    e.preventDefault();
                    $(".wrd-settings-nav a").removeClass("active");
                    $(this).addClass("active");
                    $(".wrd-settings-section").removeClass("active");
                    $($(this).attr("href")).addClass("active");
                    localStorage.setItem("wrd_active_settings_tab", $(this).attr("href"));
                });
                var activeTab = localStorage.getItem("wrd_active_settings_tab");
                if(activeTab && $(activeTab).length) {
                    $(".wrd-settings-nav a[href=\'"+activeTab+"\']").click();
                } else {
                    $(".wrd-settings-nav a").first().click();
                }
            });
        </script>';

        echo '<form method="post">';
        wp_nonce_field('wrd_save_settings', 'wrd_settings_nonce');

        echo '<div class="wrd-settings-wrap">';
        
        // Sidebar Navigation
        echo '<div class="wrd-settings-sidebar">';
        echo '<div class="wrd-settings-nav">';
        echo '<a href="#wrd-sec-general">' . esc_html__('General', 'woocommerce-us-duties') . '</a>';
        echo '<a href="#wrd-sec-calc">' . esc_html__('Calculation', 'woocommerce-us-duties') . '</a>';
        echo '<a href="#wrd-sec-fees">' . esc_html__('Fees & Padding', 'woocommerce-us-duties') . '</a>';
        echo '<a href="#wrd-sec-shipping">' . esc_html__('Shipping Channels', 'woocommerce-us-duties') . '</a>';
        echo '<a href="#wrd-sec-display">' . esc_html__('Display Options', 'woocommerce-us-duties') . '</a>';
        echo '<a href="#wrd-sec-fx">' . esc_html__('Currency (FX)', 'woocommerce-us-duties') . '</a>';
        echo '</div>';
        echo '</div>'; // end sidebar

        echo '<div class="wrd-settings-content">';

        // GENERAL SECTION
        echo '<div id="wrd-sec-general" class="wrd-settings-section">';
        echo '<h2>' . esc_html__('General Settings', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Checkout Mode', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<select name="ddp_mode">';
        foreach ([ 'charge' => __('Charge duties as a fee (DDP)', 'woocommerce-us-duties'), 'info' => __('Show info notice only (DAP)', 'woocommerce-us-duties') ] as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['ddp_mode'], $k, false), esc_html($label));
        }
        echo '</select><p class="description">' . esc_html__('DDP collects estimated duties at checkout. DAP shows an estimate and charges duties on delivery.', 'woocommerce-us-duties') . '</p></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Fee Label', 'woocommerce-us-duties') . '</label></th><td><input type="text" name="fee_label" class="regular-text" value="' . esc_attr($opt['fee_label']) . '" /></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('US Destinations Only', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="us_only" value="1" ' . checked(1, (int)$opt['us_only'], false) . ' /> ' . esc_html__('Only run estimates when the shipping destination is the US', 'woocommerce-us-duties') . '</label></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Missing Duty Rule Behavior', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<select name="missing_profile_behavior">';
        foreach ([ 'fallback' => __('Fallback (estimate $0 duty)', 'woocommerce-us-duties'), 'block' => __('Block checkout', 'woocommerce-us-duties') ] as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['missing_profile_behavior'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        // CALCULATION SECTION
        echo '<div id="wrd-sec-calc" class="wrd-settings-section">';
        echo '<h2>' . esc_html__('Calculation Rules', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('CUSMA / USMCA Duty-Free', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<label style="margin-bottom:8px; display:block;"><input type="checkbox" name="cusma_auto" value="1" ' . checked(1, (int)$opt['cusma_auto'], false) . ' /> ' . esc_html__('Treat listed origin countries as duty-free for US imports when eligible', 'woocommerce-us-duties') . '</label>';
        echo '<input type="text" name="cusma_countries" class="regular-text" value="' . esc_attr($opt['cusma_countries']) . '" /> <p class="description">' . esc_html__('Comma-separated ISO-2 list of participating countries, e.g., CA,US,MX', 'woocommerce-us-duties') . '</p></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Postal Informal Threshold (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="postal_informal_threshold_usd" value="' . esc_attr($opt['postal_informal_threshold_usd']) . '" /></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Min Split Savings (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="min_split_savings" value="' . esc_attr($opt['min_split_savings']) . '" /></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Preferred Duty Source', 'woocommerce-us-duties') . '</label></th><td><select name="preferred_duty_source">';
        foreach ([
            'zonos_first' => __('Zonos first (fallback to others)', 'woocommerce-us-duties'),
            'stallion_first' => __('Stallion first (fallback to others)', 'woocommerce-us-duties'),
            'lowest_rate' => __('Use the lowest total rate', 'woocommerce-us-duties'),
            'newest_data' => __('Use the newest data available', 'woocommerce-us-duties'),
        ] as $val => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($opt['preferred_duty_source'], $val, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        // FEES & PADDING SECTION
        echo '<div id="wrd-sec-fees" class="wrd-settings-section">';
        echo '<h2>' . esc_html__('Fees & Padding', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('Configure fixed clearance fees, variable disbursement fees, and duty padding markups to cover unexpected broker differences.', 'woocommerce-us-duties') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';
        
        echo '<tr><th><label>' . esc_html__('Postal Clearance', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<div class="wrd-inline-group"><label>' . esc_html__('Base Fee: $', 'woocommerce-us-duties') . ' <input type="number" step="0.01" name="postal_clearance_fee_usd" value="' . esc_attr($opt['postal_clearance_fee_usd']) . '" style="width:100px;" /></label></div>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Postal Disbursement', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<div class="wrd-inline-group">';
        echo '<label>' . esc_html__('Flat: $', 'woocommerce-us-duties') . ' <input type="number" step="0.01" name="postal_disbursement_flat_usd" value="' . esc_attr($opt['postal_disbursement_flat_usd'] ?? 0) . '" style="width:100px;" /></label> <span><strong>OR</strong></span> ';
        echo '<label>' . esc_html__('Percentage: ', 'woocommerce-us-duties') . ' <input type="number" step="0.01" name="postal_disbursement_pct" value="' . esc_attr($opt['postal_disbursement_pct'] ?? 0) . '" style="width:100px;" /> %</label>';
        echo '</div><p class="description">' . esc_html__('Added only if duties > $0. Charges the greater of the flat fee or percentage.', 'woocommerce-us-duties') . '</p>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Commercial Brokerage', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<div class="wrd-inline-group"><label>' . esc_html__('Base Fee: $', 'woocommerce-us-duties') . ' <input type="number" step="0.01" name="commercial_brokerage_flat_usd" value="' . esc_attr($opt['commercial_brokerage_flat_usd']) . '" style="width:100px;" /></label></div>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Commercial Disbursement', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<div class="wrd-inline-group">';
        echo '<label>' . esc_html__('Flat: $', 'woocommerce-us-duties') . ' <input type="number" step="0.01" name="commercial_disbursement_flat_usd" value="' . esc_attr($opt['commercial_disbursement_flat_usd'] ?? 0) . '" style="width:100px;" /></label> <span><strong>OR</strong></span> ';
        echo '<label>' . esc_html__('Percentage: ', 'woocommerce-us-duties') . ' <input type="number" step="0.01" name="commercial_disbursement_pct" value="' . esc_attr($opt['commercial_disbursement_pct'] ?? 0) . '" style="width:100px;" /> %</label>';
        echo '</div><p class="description">' . esc_html__('Added only if duties > $0. Charges the greater of the flat fee or percentage.', 'woocommerce-us-duties') . '</p>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Global Duty Padding (%)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="duty_padding_pct" value="' . esc_attr($opt['duty_padding_pct']) . '" style="width:100px;" /> <p class="description">' . esc_html__('A fallback/default percentage to mark up the calculated duties.', 'woocommerce-us-duties') . '</p></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Advanced Duty Padding Rules (JSON)', 'woocommerce-us-duties') . '</label></th><td><textarea name="duty_padding_rules_json" rows="6" class="large-text code">' . esc_textarea($opt['duty_padding_rules_json'] ?? '') . '</textarea><p class="description">' . esc_html__('Advanced rules for padding. Format: [{"hs_prefix": "61", "country": "CN", "padding_pct": 10}]. Highest specificity wins (country match + longest HS prefix). Rules here override the global padding above.', 'woocommerce-us-duties') . '</p></td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        // SHIPPING CHANNELS SECTION
        echo '<div id="wrd-sec-shipping" class="wrd-settings-section">';
        echo '<h2>' . esc_html__('Shipping Channels', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('Map your active shipping methods to either the Postal or Commercial duty estimation channels.', 'woocommerce-us-duties') . '</p>';
        
        $discovered = self::discover_shipping_methods();
        echo '<table class="widefat striped" style="margin: 12px 0 24px;"><thead><tr>';
        echo '<th>' . esc_html__('Zone', 'woocommerce-us-duties') . '</th>';
        echo '<th>' . esc_html__('Method', 'woocommerce-us-duties') . '</th>';
        echo '<th>' . esc_html__('Key', 'woocommerce-us-duties') . '</th>';
        echo '<th style="width:180px;">' . esc_html__('Channel', 'woocommerce-us-duties') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($discovered)) {
            echo '<tr><td colspan="4">' . esc_html__('No active shipping methods found.', 'woocommerce-us-duties') . '</td></tr>';
        } else {
            foreach ($discovered as $row) {
                $key = $row['key'];
                $current = $opt['shipping_channel_map'][$key] ?? '';
                echo '<tr>';
                echo '<td>' . esc_html($row['zone']) . '</td>';
                echo '<td><strong>' . esc_html($row['title']) . '</strong></td>';
                echo '<td><code>' . esc_html($key) . '</code><input type="hidden" name="sc_key[]" value="' . esc_attr($key) . '" /></td>';
                echo '<td><select name="sc_channel[]" style="width:100%;">';
                foreach ([ '' => __('Ignore', 'woocommerce-us-duties'), 'postal' => __('Postal', 'woocommerce-us-duties'), 'commercial' => __('Commercial', 'woocommerce-us-duties') ] as $val => $label2) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($current, $val, false), esc_html($label2));
                }
                echo '</select></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Default Fallback Channel', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<select name="default_shipping_channel">';
        foreach ([ 'auto' => __('Auto (use origin heuristic)', 'woocommerce-us-duties'), 'postal' => __('Postal', 'woocommerce-us-duties'), 'commercial' => __('Commercial', 'woocommerce-us-duties') ] as $k => $label2) { 
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['default_shipping_channel'], $k, false), esc_html($label2)); 
        }
        echo '</select><p class="description">' . esc_html__('Used if a method is unmapped or unknown.', 'woocommerce-us-duties') . '</p></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Keyword Rules (Advanced)', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<textarea name="shipping_channel_rules" rows="5" class="large-text code">' . esc_textarea($opt['shipping_channel_rules']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Fallback keyword rules. Format: keyword|postal or keyword|commercial. Matches against shipping label and ID.', 'woocommerce-us-duties') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';

        // DISPLAY OPTIONS SECTION
        echo '<div id="wrd-sec-display" class="wrd-settings-section">';
        echo '<h2>' . esc_html__('Display Options', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        
        echo '<tr><th><label>' . esc_html__('Totals Summary', 'woocommerce-us-duties') . '</label></th><td><select name="details_mode">';
        foreach ([ 'none' => __('None', 'woocommerce-us-duties'), 'inline' => __('Inline summary (collapsible)', 'woocommerce-us-duties') ] as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['details_mode'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Inline Duty Hints', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<label style="display:block; margin-bottom:8px;"><input type="checkbox" name="inline_cart_line" value="1" ' . checked(1, (int)$opt['inline_cart_line'], false) . ' /> ' . esc_html__('Show small per-line duty hint on the Cart page', 'woocommerce-us-duties') . '</label>';
        echo '<label style="display:block;"><input type="checkbox" name="inline_checkout_line" value="1" ' . checked(1, (int)$opt['inline_checkout_line'], false) . ' /> ' . esc_html__('Show small per-line duty hint in the Checkout summary', 'woocommerce-us-duties') . '</label>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Product Page Hint', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<label style="display:block; margin-bottom:12px; font-weight: 500;"><input type="checkbox" name="product_hint_enabled" value="1" ' . checked(1, (int)$opt['product_hint_enabled'], false) . ' /> ' . esc_html__('Enable estimated US duties on the single product page', 'woocommerce-us-duties') . '</label>';
        echo '<div style="background: #f8f9fa; border: 1px solid #ccd0d4; padding: 16px; border-radius: 4px;">';
        echo '<label style="display:block; margin-bottom:8px;"><strong>' . esc_html__('Position:', 'woocommerce-us-duties') . '</strong> <select name="product_hint_position" style="margin-left:8px;">';
        foreach ([ 'under_price' => __('Under price (recommended)', 'woocommerce-us-duties'), 'after_cart' => __('Below "Add to cart"', 'woocommerce-us-duties'), 'in_meta' => __('In product meta block', 'woocommerce-us-duties') ] as $k => $label) { 
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['product_hint_position'], $k, false), esc_html($label)); 
        }
        echo '</select></label>';
        echo '<label style="display:block;"><strong>' . esc_html__('Extra Note:', 'woocommerce-us-duties') . '</strong> <input type="text" name="product_hint_note" class="regular-text" style="margin-left:8px;" value="' . esc_attr($opt['product_hint_note']) . '" placeholder="e.g., Fees added at checkout" /></label>';
        echo '</div>';
        echo '</td></tr>';
        
        echo '<tr><th><label>' . esc_html__('Debug Mode', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="debug_mode" value="1" ' . checked(1, (int)$opt['debug_mode'], false) . ' /> ' . esc_html__('Show detailed duty calculation logic in the checkout details area (for admins/testing)', 'woocommerce-us-duties') . '</label></td></tr>';
        
        echo '</tbody></table>';
        echo '</div>';

        // FX SECTION
        echo '<div id="wrd-sec-fx" class="wrd-settings-section">';
        echo '<h2>' . esc_html__('Currency Conversion', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Enable FX', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="fx_enabled" value="1" ' . checked(1, (int)$opt['fx_enabled'], false) . ' /> ' . esc_html__('Fetch live rates from a free API', 'woocommerce-us-duties') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Provider', 'woocommerce-us-duties') . '</label></th><td><select name="fx_provider">';
        foreach ([ 'exchangerate_host' => 'exchangerate.host (free)' ] as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['fx_provider'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Refresh Interval (hours)', 'woocommerce-us-duties') . '</label></th><td><input type="number" min="1" step="1" name="fx_refresh_hours" value="' . esc_attr($opt['fx_refresh_hours']) . '" /></td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        
        echo '<div class="wrd-save-bar">';
        submit_button(__('Save Changes', 'woocommerce-us-duties'), 'primary', 'submit', false);
        echo '</div>';

        echo '</div>'; // end content
        echo '</div>'; // end wrap

        echo '</form>';
        if ($wrap) { echo '</div>'; }
    }

    private static function sanitize_settings(array $src): array {
        $out = [
            'ddp_mode' => in_array(($src['ddp_mode'] ?? 'charge'), ['charge','info'], true) ? $src['ddp_mode'] : 'charge',
            'fee_label' => sanitize_text_field($src['fee_label'] ?? 'Estimated US Duties'),
            'us_only' => !empty($src['us_only']) ? 1 : 0,
            'missing_profile_behavior' => in_array(($src['missing_profile_behavior'] ?? 'fallback'), ['fallback','block'], true) ? $src['missing_profile_behavior'] : 'fallback',
            'cusma_auto' => !empty($src['cusma_auto']) ? 1 : 0,
            'cusma_countries' => strtoupper(preg_replace('/\s+/', '', (string)($src['cusma_countries'] ?? 'CA,US'))),
            'min_split_savings' => (float) ($src['min_split_savings'] ?? 0),
            'postal_informal_threshold_usd' => (float) ($src['postal_informal_threshold_usd'] ?? 2500),
            'preferred_duty_source' => in_array(($src['preferred_duty_source'] ?? 'zonos_first'), ['zonos_first','stallion_first','lowest_rate','newest_data'], true) ? $src['preferred_duty_source'] : 'zonos_first',
            'postal_clearance_fee_usd' => (float) ($src['postal_clearance_fee_usd'] ?? 0),
            'postal_disbursement_flat_usd' => (float) ($src['postal_disbursement_flat_usd'] ?? 0),
            'postal_disbursement_pct' => (float) ($src['postal_disbursement_pct'] ?? 0),
            'commercial_brokerage_flat_usd' => (float) ($src['commercial_brokerage_flat_usd'] ?? 0),
            'commercial_disbursement_flat_usd' => (float) ($src['commercial_disbursement_flat_usd'] ?? 0),
            'commercial_disbursement_pct' => (float) ($src['commercial_disbursement_pct'] ?? 0),
            'duty_padding_pct' => (float) ($src['duty_padding_pct'] ?? 0),
            'duty_padding_rules_json' => trim((string)($src['duty_padding_rules_json'] ?? '')),
            'fx_enabled' => !empty($src['fx_enabled']) ? 1 : 0,
            'fx_provider' => sanitize_text_field($src['fx_provider'] ?? 'exchangerate_host'),
            'fx_refresh_hours' => max(1, (int)($src['fx_refresh_hours'] ?? 12)),
            'shipping_channel_rules' => trim((string)($src['shipping_channel_rules'] ?? '')),
            'details_mode' => in_array(($src['details_mode'] ?? 'inline'), ['none','inline'], true) ? $src['details_mode'] : 'inline',
            'inline_cart_line' => !empty($src['inline_cart_line']) ? 1 : 0,
            'inline_checkout_line' => !empty($src['inline_checkout_line']) ? 1 : 0,
            'debug_mode' => !empty($src['debug_mode']) ? 1 : 0,
        ];
        // Build explicit shipping channel map
        $map = [];
        if (!empty($src['sc_key']) && !empty($src['sc_channel']) && is_array($src['sc_key']) && is_array($src['sc_channel'])) {
            $count = min(count($src['sc_key']), count($src['sc_channel']));
            for ($i = 0; $i < $count; $i++) {
                $key = sanitize_text_field($src['sc_key'][$i]);
                $ch = strtolower(sanitize_text_field($src['sc_channel'][$i]));
                if (in_array($ch, ['postal','commercial'], true) && $key !== '') {
                    $map[$key] = $ch;
                }
            }
        }
        $out['shipping_channel_map'] = $map;
        $dsc = strtolower(sanitize_text_field($src['default_shipping_channel'] ?? 'auto'));
        $out['default_shipping_channel'] = in_array($dsc, ['auto','postal','commercial'], true) ? $dsc : 'auto';
        $out['product_hint_enabled'] = !empty($src['product_hint_enabled']) ? 1 : 0;
        $php = sanitize_text_field($src['product_hint_position'] ?? 'under_price');
        $out['product_hint_position'] = in_array($php, ['under_price','after_cart','in_meta'], true) ? $php : 'under_price';
        $out['product_hint_note'] = sanitize_text_field($src['product_hint_note'] ?? '');
        return $out;
    }

    private static function discover_shipping_methods(): array {
        if (!class_exists('WC_Shipping_Zones')) { return []; }
        $rows = [];
        // All defined zones
        $zones = \WC_Shipping_Zones::get_zones();
        foreach ($zones as $z) {
            $zone = new \WC_Shipping_Zone($z['id']);
            $methods = $zone->get_shipping_methods(true);
            foreach ($methods as $m) {
                $rows[] = [
                    'zone' => $zone->get_zone_name(),
                    'title' => $m->get_title(),
                    'key' => $m->id . ':' . $m->instance_id,
                ];
            }
        }
        // Locations not covered by your other zones (zone 0)
        $default = new \WC_Shipping_Zone(0);
        $methods = $default->get_shipping_methods(true);
        foreach ($methods as $m) {
            $rows[] = [
                'zone' => $default->get_zone_name(),
                'title' => $m->get_title(),
                'key' => $m->id . ':' . $m->instance_id,
            ];
        }
        return $rows;
    }
}
