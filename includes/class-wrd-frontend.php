<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Frontend {
    public function init(): void {
        add_action('woocommerce_cart_totals_after_order_total', [$this, 'render_details']);
        add_action('woocommerce_review_order_after_order_total', [$this, 'render_details']);
    }

    public function render_details(): void {
        $opt = get_option(WRD_Settings::OPTION, []);
        $mode = $opt['details_mode'] ?? 'inline';
        if ($mode === 'none') { return; }

        $estimate = WRD_Duty_Engine::estimate_cart_duties();
        if (empty($estimate['lines'])) { return; }
        $currency = WRD_Duty_Engine::current_currency();

        echo '<tr class="wrd-duty-details"><td colspan="2" style="padding-top:6px;">';
        echo '<details><summary style="cursor:pointer;">' . esc_html__('Import Duty Details', 'wrd-us-duty') . '</summary>';
        echo '<div style="margin-top:8px;">';
        echo '<ul style="margin:0; padding-left:18px;">';
        foreach ($estimate['lines'] as $line) {
            $pid = (int)$line['product_id'];
            $product = wc_get_product($pid);
            $name = $product ? $product->get_name() : ('#' . $pid);
            $dutyStore = WRD_FX::convert((float)$line['duty_usd'], 'USD', $currency);
            $rate = number_format_i18n((float)$line['rate_pct'], 2) . '%';
            $tags = [];
            if (!empty($line['cusma'])) { $tags[] = 'CUSMA'; }
            $tagStr = $tags ? (' [' . implode(', ', $tags) . ']') : '';
            printf('<li>%s — %s, %s, %s%s: %s</li>', esc_html($name), esc_html(strtoupper((string)$line['origin'])), esc_html($line['channel']), esc_html($rate), esc_html($tagStr), wp_kses_post(wc_price($dutyStore)));
        }
        echo '</ul>';
        echo '</div>';
        echo '</details>';
        echo '</td></tr>';
    }
}
