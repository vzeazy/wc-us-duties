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
        ]);

        if ($wrap) { echo '<div class="wrap">'; echo '<h1>' . esc_html__('Routing & Fees', 'woocommerce-us-duties') . '</h1>'; }
        if ($saved) { echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'woocommerce-us-duties') . '</p></div>'; }
        echo '<form method="post">';
        wp_nonce_field('wrd_save_settings', 'wrd_settings_nonce');

        echo '<h2>' . esc_html__('General', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Checkout Mode', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<select name="ddp_mode">';
        $modes = [ 'charge' => __('Charge duties as a fee (DDP)', 'woocommerce-us-duties'), 'info' => __('Show info notice only (DAP)', 'woocommerce-us-duties') ];
        foreach ($modes as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['ddp_mode'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Fee Label', 'woocommerce-us-duties') . '</label></th><td><input type="text" name="fee_label" class="regular-text" value="' . esc_attr($opt['fee_label']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('US Only', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="us_only" value="1" ' . checked(1, (int)$opt['us_only'], false) . ' /> ' . esc_html__('Estimate for US destinations only', 'woocommerce-us-duties') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Missing Profile Behavior', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<select name="missing_profile_behavior">';
        foreach ([ 'fallback' => __('Fallback (estimate 0 duty)', 'woocommerce-us-duties'), 'block' => __('Block checkout', 'woocommerce-us-duties') ] as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['missing_profile_behavior'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Min Split Savings (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="min_split_savings" value="' . esc_attr($opt['min_split_savings']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('Postal Informal Threshold (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="postal_informal_threshold_usd" value="' . esc_attr($opt['postal_informal_threshold_usd']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Calculation', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('CUSMA duty-free', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="cusma_auto" value="1" ' . checked(1, (int)$opt['cusma_auto'], false) . ' /> ' . esc_html__('Treat origins in list as duty-free into US (line-item)', 'woocommerce-us-duties') . '</label><br/>';
        echo '<input type="text" name="cusma_countries" class="regular-text" value="' . esc_attr($opt['cusma_countries']) . '" /> <span class="description">' . esc_html__('Comma-separated ISO-2 list, e.g., CA,US[,MX]', 'woocommerce-us-duties') . '</span></td></tr>';
        echo '<tr><th><label>' . esc_html__('Min Split Savings (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="min_split_savings" value="' . esc_attr($opt['min_split_savings']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('Postal Informal Threshold (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="postal_informal_threshold_usd" value="' . esc_attr($opt['postal_informal_threshold_usd']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Fees', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Postal Clearance Fee (USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="postal_clearance_fee_usd" value="' . esc_attr($opt['postal_clearance_fee_usd']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('Commercial Brokerage (flat, USD)', 'woocommerce-us-duties') . '</label></th><td><input type="number" step="0.01" name="commercial_brokerage_flat_usd" value="' . esc_attr($opt['commercial_brokerage_flat_usd']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('FX', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Enable FX', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="fx_enabled" value="1" ' . checked(1, (int)$opt['fx_enabled'], false) . ' /> ' . esc_html__('Fetch rates from a free API', 'woocommerce-us-duties') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Provider', 'woocommerce-us-duties') . '</label></th><td><select name="fx_provider">';
        $providers = [ 'exchangerate_host' => 'exchangerate.host (free)' ];
        foreach ($providers as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['fx_provider'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Refresh Interval (hours)', 'woocommerce-us-duties') . '</label></th><td><input type="number" min="1" step="1" name="fx_refresh_hours" value="' . esc_attr($opt['fx_refresh_hours']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Shipping Channels', 'woocommerce-us-duties') . '</h2>';
        echo '<p>' . esc_html__('Select channels for your enabled shipping methods. Exact matches override the keyword rules. Keywords are still supported below.', 'woocommerce-us-duties') . '</p>';
        // Discovered methods table
        $discovered = self::discover_shipping_methods();
        echo '<table class="widefat striped" style="max-width:840px;margin-bottom:8px;"><thead><tr>';
        echo '<th>' . esc_html__('Zone', 'woocommerce-us-duties') . '</th>';
        echo '<th>' . esc_html__('Method', 'woocommerce-us-duties') . '</th>';
        echo '<th>' . esc_html__('Key', 'woocommerce-us-duties') . '</th>';
        echo '<th style="width:160px;">' . esc_html__('Channel', 'woocommerce-us-duties') . '</th>';
        echo '</tr></thead><tbody>';
        if (empty($discovered)) {
            echo '<tr><td colspan="4">' . esc_html__('No active shipping methods found.', 'woocommerce-us-duties') . '</td></tr>';
        } else {
            foreach ($discovered as $row) {
                $key = $row['key'];
                $current = $opt['shipping_channel_map'][$key] ?? '';
                echo '<tr>';
                echo '<td>' . esc_html($row['zone']) . '</td>';
                echo '<td>' . esc_html($row['title']) . '</td>';
                echo '<td><code>' . esc_html($key) . '</code><input type="hidden" name="sc_key[]" value="' . esc_attr($key) . '" /></td>';
                echo '<td><select name="sc_channel[]">';
                $opts = [ '' => __('Ignore', 'woocommerce-us-duties'), 'postal' => __('Postal', 'woocommerce-us-duties'), 'commercial' => __('Commercial', 'woocommerce-us-duties') ];
                foreach ($opts as $val => $label2) {
                    printf('<option value="%s" %s>%s</option>', esc_attr($val), selected($current, $val, false), esc_html($label2));
                }
                echo '</select></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        echo '<p style="margin-top:8px;"><label>' . esc_html__('Default channel for unmapped methods', 'woocommerce-us-duties') . ' </label> ';
        echo '<select name="default_shipping_channel">';
        $defopts = [ 'auto' => __('Auto (use origin heuristic)', 'woocommerce-us-duties'), 'postal' => __('Postal', 'woocommerce-us-duties'), 'commercial' => __('Commercial', 'woocommerce-us-duties') ];
        foreach ($defopts as $k => $label2) { printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['default_shipping_channel'], $k, false), esc_html($label2)); }
        echo '</select></p>';

        echo '<p>' . esc_html__('Keyword rules (fallback): one per line: keyword|postal or keyword|commercial. Match is case-insensitive and checks chosen rate label and method ID.', 'woocommerce-us-duties') . '</p>';
        echo '<textarea name="shipping_channel_rules" rows="6" class="large-text code">' . esc_textarea($opt['shipping_channel_rules']) . '</textarea>';

        echo '<h2>' . esc_html__('Display', 'woocommerce-us-duties') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Duty Details', 'woocommerce-us-duties') . '</label></th><td><select name="details_mode">';
        $dm = [ 'none' => __('None', 'woocommerce-us-duties'), 'inline' => __('Inline summary (collapsible)', 'woocommerce-us-duties') ];
        foreach ($dm as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['details_mode'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Inline Duties (Cart)', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="inline_cart_line" value="1" ' . checked(1, (int)$opt['inline_cart_line'], false) . ' /> ' . esc_html__('Show a small per-line duty hint on the cart page', 'woocommerce-us-duties') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Inline Duties (Checkout)', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="inline_checkout_line" value="1" ' . checked(1, (int)$opt['inline_checkout_line'], false) . ' /> ' . esc_html__('Show a small per-line duty hint in the checkout order summary', 'woocommerce-us-duties') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Debug Mode', 'woocommerce-us-duties') . '</label></th><td><label><input type="checkbox" name="debug_mode" value="1" ' . checked(1, (int)$opt['debug_mode'], false) . ' /> ' . esc_html__('Show detailed logic in the duties details area (admin/testing only)', 'woocommerce-us-duties') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Product Page Hint', 'woocommerce-us-duties') . '</label></th><td>';
        echo '<label><input type="checkbox" name="product_hint_enabled" value="1" ' . checked(1, (int)$opt['product_hint_enabled'], false) . ' /> ' . esc_html__('Show estimated US duties on the product page', 'woocommerce-us-duties') . '</label><br/>';
        echo '<label>' . esc_html__('Position', 'woocommerce-us-duties') . ' <select name="product_hint_position">';
        $pos = [ 'under_price' => __('Under price (recommended)', 'woocommerce-us-duties'), 'after_cart' => __('Below Add to cart', 'woocommerce-us-duties'), 'in_meta' => __('In product meta', 'woocommerce-us-duties') ];
        foreach ($pos as $k => $label) { printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['product_hint_position'], $k, false), esc_html($label)); }
        echo '</select></label><br/>';
        echo '<label>' . esc_html__('Note (optional)', 'woocommerce-us-duties') . ' <input type="text" name="product_hint_note" class="regular-text" value="' . esc_attr($opt['product_hint_note']) . '" placeholder="e.g., Fees added at checkout" /></label>';
        echo '</td></tr>';
        echo '</tbody></table>';

        submit_button(__('Save Changes', 'woocommerce-us-duties'));
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
            'postal_clearance_fee_usd' => (float) ($src['postal_clearance_fee_usd'] ?? 0),
            'commercial_brokerage_flat_usd' => (float) ($src['commercial_brokerage_flat_usd'] ?? 0),
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
