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
        ]);

        if ($wrap) { echo '<div class="wrap">'; echo '<h1>' . esc_html__('Routing & Fees', 'wrd-us-duty') . '</h1>'; }
        if ($saved) { echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'wrd-us-duty') . '</p></div>'; }
        echo '<form method="post">';
        wp_nonce_field('wrd_save_settings', 'wrd_settings_nonce');

        echo '<h2>' . esc_html__('General', 'wrd-us-duty') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Checkout Mode', 'wrd-us-duty') . '</label></th><td>';
        echo '<select name="ddp_mode">';
        $modes = [ 'charge' => __('Charge duties as a fee (DDP)', 'wrd-us-duty'), 'info' => __('Show info notice only (DAP)', 'wrd-us-duty') ];
        foreach ($modes as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['ddp_mode'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Fee Label', 'wrd-us-duty') . '</label></th><td><input type="text" name="fee_label" class="regular-text" value="' . esc_attr($opt['fee_label']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('US Only', 'wrd-us-duty') . '</label></th><td><label><input type="checkbox" name="us_only" value="1" ' . checked(1, (int)$opt['us_only'], false) . ' /> ' . esc_html__('Estimate for US destinations only', 'wrd-us-duty') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Missing Profile Behavior', 'wrd-us-duty') . '</label></th><td>';
        echo '<select name="missing_profile_behavior">';
        foreach ([ 'fallback' => 'Fallback (estimate 0 duty)', 'block' => 'Block checkout' ] as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['missing_profile_behavior'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Min Split Savings (USD)', 'wrd-us-duty') . '</label></th><td><input type="number" step="0.01" name="min_split_savings" value="' . esc_attr($opt['min_split_savings']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('Postal Informal Threshold (USD)', 'wrd-us-duty') . '</label></th><td><input type="number" step="0.01" name="postal_informal_threshold_usd" value="' . esc_attr($opt['postal_informal_threshold_usd']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Calculation', 'wrd-us-duty') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('CUSMA duty-free', 'wrd-us-duty') . '</label></th><td><label><input type="checkbox" name="cusma_auto" value="1" ' . checked(1, (int)$opt['cusma_auto'], false) . ' /> ' . esc_html__('Treat origins in list as duty-free into US (line-item)', 'wrd-us-duty') . '</label><br/>';
        echo '<input type="text" name="cusma_countries" class="regular-text" value="' . esc_attr($opt['cusma_countries']) . '" /> <span class="description">' . esc_html__('Comma-separated ISO-2 list, e.g., CA,US[,MX]', 'wrd-us-duty') . '</span></td></tr>';
        echo '<tr><th><label>' . esc_html__('Min Split Savings (USD)', 'wrd-us-duty') . '</label></th><td><input type="number" step="0.01" name="min_split_savings" value="' . esc_attr($opt['min_split_savings']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('Postal Informal Threshold (USD)', 'wrd-us-duty') . '</label></th><td><input type="number" step="0.01" name="postal_informal_threshold_usd" value="' . esc_attr($opt['postal_informal_threshold_usd']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Fees', 'wrd-us-duty') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Postal Clearance Fee (USD)', 'wrd-us-duty') . '</label></th><td><input type="number" step="0.01" name="postal_clearance_fee_usd" value="' . esc_attr($opt['postal_clearance_fee_usd']) . '" /></td></tr>';
        echo '<tr><th><label>' . esc_html__('Commercial Brokerage (flat, USD)', 'wrd-us-duty') . '</label></th><td><input type="number" step="0.01" name="commercial_brokerage_flat_usd" value="' . esc_attr($opt['commercial_brokerage_flat_usd']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('FX', 'wrd-us-duty') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Enable FX', 'wrd-us-duty') . '</label></th><td><label><input type="checkbox" name="fx_enabled" value="1" ' . checked(1, (int)$opt['fx_enabled'], false) . ' /> ' . esc_html__('Fetch rates from a free API', 'wrd-us-duty') . '</label></td></tr>';
        echo '<tr><th><label>' . esc_html__('Provider', 'wrd-us-duty') . '</label></th><td><select name="fx_provider">';
        $providers = [ 'exchangerate_host' => 'exchangerate.host (free)' ];
        foreach ($providers as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['fx_provider'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '<tr><th><label>' . esc_html__('Refresh Interval (hours)', 'wrd-us-duty') . '</label></th><td><input type="number" min="1" step="1" name="fx_refresh_hours" value="' . esc_attr($opt['fx_refresh_hours']) . '" /></td></tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Shipping Channels', 'wrd-us-duty') . '</h2>';
        echo '<p>' . esc_html__('Map shipping service names or method IDs to a channel. One per line: keyword|postal or keyword|commercial. Match is case-insensitive and checks chosen rate label and method ID.', 'wrd-us-duty') . '</p>';
        echo '<textarea name="shipping_channel_rules" rows="6" class="large-text code">' . esc_textarea($opt['shipping_channel_rules']) . '</textarea>';

        echo '<h2>' . esc_html__('Display', 'wrd-us-duty') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th><label>' . esc_html__('Duty Details', 'wrd-us-duty') . '</label></th><td><select name="details_mode">';
        $dm = [ 'none' => __('None', 'wrd-us-duty'), 'inline' => __('Inline summary (collapsible)', 'wrd-us-duty') ];
        foreach ($dm as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($opt['details_mode'], $k, false), esc_html($label));
        }
        echo '</select></td></tr>';
        echo '</tbody></table>';

        submit_button(__('Save Changes', 'wrd-us-duty'));
        echo '</form>';
        if ($wrap) { echo '</div>'; }
    }

    private static function sanitize_settings(array $src): array {
        return [
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
        ];
    }
}
