<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Frontend {
    private $estimate_cache = null;
    public function init(): void {
        // Render details near fees on cart; and on checkout totals panel
        add_action('woocommerce_cart_totals_after_fees', [$this, 'render_details']);
        // Some themes don't call after_fees; also render after order total as fallback
        add_action('woocommerce_cart_totals_after_order_total', [$this, 'render_details']);
        add_action('woocommerce_review_order_after_order_total', [$this, 'render_details']);

        // Add a small details toggle link next to our fee amount
        add_filter('woocommerce_cart_totals_fee_html', [$this, 'filter_fee_amount_html'], 10, 2);

        // Minimal assets for toggle behavior
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Inline per-line duties on cart/checkout item names
        add_filter('woocommerce_cart_item_name', [$this, 'filter_cart_item_name'], 10, 3);

        // Product page hint
        add_action('wp', function(){
            if (!function_exists('is_product') || !is_product()) { return; }
            $opt = get_option(WRD_Settings::OPTION, []);
            if (empty($opt['product_hint_enabled'])) { return; }
            $pos = $opt['product_hint_position'] ?? 'under_price';
            switch ($pos) {
                case 'after_cart':
                    add_action('woocommerce_after_add_to_cart_button', [$this, 'render_product_hint']);
                    break;
                case 'in_meta':
                    add_action('woocommerce_product_meta_end', [$this, 'render_product_hint']);
                    break;
                case 'under_price':
                default:
                    add_action('woocommerce_single_product_summary', [$this, 'render_product_hint'], 25);
                    break;
            }
        });
    }

    public function render_details(): void {
        static $rendered = false;
        if ($rendered) { return; }
        $opt = get_option(WRD_Settings::OPTION, []);
        $mode = $opt['details_mode'] ?? 'inline';
        if ($mode === 'none') { return; }

        $estimate = WRD_Duty_Engine::estimate_cart_duties();
        if (empty($estimate['lines'])) { return; }
        $currency = WRD_Duty_Engine::current_currency();

        $totalStore = WRD_FX::convert((float)$estimate['total_usd'], 'USD', $currency);

        $is_cart = function_exists('is_cart') ? is_cart() : false;
        if ($is_cart) {
            // Theme uses div-based totals; render a sibling list-group item
            echo '<div class="list-group-item border-0 py-2">';
            echo '<div id="wrd-duty-details-cart" class="wrd-duty-details" style="display:none;margin:6px 0 0;">';
        } else {
            echo '<tr class="wrd-duty-details-row"><td colspan="2">';
            echo '<div id="wrd-duty-details" class="wrd-duty-details" style="display:none;margin:6px 0 0;">';
        }
        echo '<table class="shop_table wrd-duty-table" style="margin:0;">';
        echo '<thead><tr>';
        echo '<th style="text-align:left;">' . esc_html__('Item', 'woocommerce-us-duties') . '</th>';
        echo '<th style="text-align:center;">' . esc_html__('Rate', 'woocommerce-us-duties') . '</th>';
        echo '<th style="text-align:right;">' . esc_html__('Duty', 'woocommerce-us-duties') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($estimate['lines'] as $line) {
            $pid = (int)$line['product_id'];
            $product = wc_get_product($pid);
            $name = $product ? $product->get_name() : ('#' . $pid);
            $dutyStore = WRD_FX::convert((float)$line['duty_usd'], 'USD', $currency);
            $rate = number_format_i18n((float)$line['rate_pct'], 2) . '%';
            $badgeHtml = !empty($line['cusma']) ? '<span class="wrd-badge" style="background:#e7f7ed;color:#0a6d2c;border:1px solid #bfe8cf;border-radius:3px;padding:0 4px;font-size:11px;margin-left:4px;">CUSMA</span>' : '';
            echo '<tr>';
            echo '<td>' . esc_html($name) . $badgeHtml . '</td>';
            echo '<td style="text-align:center;">' . esc_html($rate) . '</td>';
            echo '<td style="text-align:right;">' . wp_kses_post(wc_price($dutyStore)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        // Summaries table (align with Woo styles)
        $dutiesStore = $totalStore;
        $feesStore = WRD_FX::convert((float)($estimate['fees_usd'] ?? 0), 'USD', $currency);
        $grand = $dutiesStore + $feesStore;
        echo '<table class="shop_table wrd-summary-table" style="margin-top:8px;">';
        echo '<tbody>';
        // Duties subtotal
        echo '<tr>';
        echo '<th style="text-align:right;">' . esc_html__('Duties subtotal', 'woocommerce-us-duties') . '</th>';
        echo '<td style="text-align:right;">' . wp_kses_post(wc_price($dutiesStore)) . '</td>';
        echo '</tr>';
        // Fees, if any
        if (!empty($estimate['fees']) && is_array($estimate['fees'])) {
            foreach ($estimate['fees'] as $fee) {
                $amt = WRD_FX::convert((float)($fee['amount_usd'] ?? 0), 'USD', $currency);
                echo '<tr>';
                echo '<th style="text-align:right;">' . esc_html($fee['label']) . '</th>';
                echo '<td style="text-align:right;">' . wp_kses_post(wc_price($amt)) . '</td>';
                echo '</tr>';
            }
        }
        // Total
        echo '<tr>';
        echo '<th style="text-align:right;">' . esc_html__('Total estimated duties and fees', 'woocommerce-us-duties') . '</th>';
        echo '<td style="text-align:right;"><strong>' . wp_kses_post(wc_price($grand)) . '</strong></td>';
        echo '</tr>';
        echo '</tbody></table>';
        // Optional debug panel
        $opt = get_option(WRD_Settings::OPTION, []);
        if (!empty($opt['debug_mode'])) {
            echo '<div class="wrd-duty-debug" style="margin-top:8px;">';
            echo '<details><summary style="cursor:pointer;">' . esc_html__('Debug: duty logic flow', 'woocommerce-us-duties') . '</summary>';
            echo '<div style="padding-top:6px;">';
            echo '<ul style="margin:0;padding-left:18px;">';
            foreach ($estimate['lines'] as $line) {
                $product = wc_get_product((int)$line['product_id']);
                $name = $product ? $product->get_name() : ('#' . (int)$line['product_id']);
                $dbg = isset($line['debug']) && is_array($line['debug']) ? $line['debug'] : [];
                $profileTxt = !empty($dbg['profile_found']) ? 'found' : 'missing';
                $source = isset($dbg['channel_source']) ? $dbg['channel_source'] : 'heuristic';
                $method = trim(($dbg['method_id'] ?? '') . ' ' . ($dbg['method_label'] ?? ''));
                $reason = $dbg['cusma_applied'] ? ($dbg['cusma_reason'] ?? 'cusma') : 'none';
                $rate = number_format_i18n((float)($dbg['rate_pct'] ?? 0), 2) . '%';
                $dutyStore = WRD_FX::convert((float)($dbg['duty_usd'] ?? 0), 'USD', $currency);
                printf('<li><strong>%s</strong> — profile: %s; channel: %s%s; CUSMA: %s; rate: %s; duty: %s</li>',
                    esc_html($name),
                    esc_html($profileTxt),
                    esc_html($source),
                    $method !== '' ? ' (' . esc_html($method) . ')' : '',
                    esc_html($reason),
                    esc_html($rate),
                    wp_kses_post(wc_price($dutyStore))
                );
            }
            echo '</ul>';
            echo '</div>';
            echo '</details>';
            echo '</div>';
        }
        echo '</div>';
        if ($is_cart) {
            echo '</div>'; // list-group-item
        } else {
            echo '</td></tr>';
        }
        $rendered = true;
    }

    public function filter_fee_amount_html($fee_html, $fee) {
        $opt = get_option(WRD_Settings::OPTION, []);
        $label = !empty($opt['fee_label']) ? $opt['fee_label'] : __('Estimated US Duties', 'woocommerce-us-duties');
        if ($fee && isset($fee->name) && $fee->name === $label) {
            $toggle = '<a href="#" class="wrd-duty-toggle" style="margin-left:6px;font-size:12px;">' . esc_html__('Details', 'woocommerce-us-duties') . '</a>';
            return $fee_html . ' ' . $toggle;
        }
        return $fee_html;
    }

    public function enqueue_assets(): void {
        if (!is_cart() && !is_checkout()) { return; }
        wp_enqueue_script('jquery');
        $js = '(function(jQuery){jQuery(document).on("click",".wrd-duty-toggle",function(e){e.preventDefault();var $t=jQuery(this);var $scope=$t.closest(".cart_totals, .shop_table, .list-group, .card");var $details=$scope.find(".wrd-duty-details");if(!$details.length){$details=jQuery("#wrd-duty-details, #wrd-duty-details-cart");}if($details.length){$details.slideToggle(150);} });})(jQuery);';
        wp_add_inline_script('jquery', $js);
    }

    public function render_product_hint(): void {
        global $product;
        if (!$product || !$product instanceof WC_Product) { return; }
        $opt = get_option(WRD_Settings::OPTION, []);
        // Only show for US destination (or if not set, indicate US)
        $dest = '';
        if (WC()->customer) {
            $dest = strtoupper((string)WC()->customer->get_shipping_country());
            if ($dest === '' || $dest === 'XX') { $dest = strtoupper((string)WC()->customer->get_billing_country()); }
        }
        $est = WRD_Duty_Engine::estimate_for_product($product, 1);
        if (!$est) { return; }
        // If not US and store set to US-only estimates, skip
        $settings = get_option(WRD_Settings::OPTION, []);
        if (!empty($settings['us_only']) && $dest !== 'US') { return; }

        $label = $est['cusma'] ? __('CUSMA: duty-free to US', 'woocommerce-us-duties') : sprintf(__('Estimated US duties: %s', 'woocommerce-us-duties'), wc_price((float)$est['duty_store']));
        $rate = number_format_i18n((float)$est['rate_pct'], 2) . '%';
        $note = !empty($opt['product_hint_note']) ? ' <span class="wrd-hint-note" style="color:#666;">' . esc_html($opt['product_hint_note']) . '</span>' : '';
        echo '<div class="wrd-product-duty-hint" style="margin:8px 0; padding:8px; background:#f8f9fa; border:1px solid #e2e6ea; border-radius:4px;">';
        echo '<span class="wrd-hint-main" style="font-weight:600;">' . wp_kses_post($label) . '</span>';
        if (!$est['cusma'] && (float)$est['rate_pct'] > 0) {
            echo ' <span class="wrd-hint-rate" style="color:#666;">(' . esc_html($rate) . ')</span>';
        }
        echo $note;
        echo '</div>';
    }

    private function get_estimate(): array {
        if ($this->estimate_cache !== null) { return $this->estimate_cache; }
        $this->estimate_cache = WRD_Duty_Engine::estimate_cart_duties();
        return $this->estimate_cache;
    }

    public function filter_cart_item_name($product_name, $cart_item, $cart_item_key) {
        $opt = get_option(WRD_Settings::OPTION, []);
        $show_cart = !empty($opt['inline_cart_line']);
        $show_checkout = !empty($opt['inline_checkout_line']);
        if ((is_cart() && !$show_cart) || (is_checkout() && !$show_checkout)) {
            return $product_name;
        }
        $est = $this->get_estimate();
        if (empty($est['lines'])) { return $product_name; }
        $line = null;
        foreach ($est['lines'] as $ln) {
            if (!empty($ln['key']) && $ln['key'] === $cart_item_key) { $line = $ln; break; }
        }
        if (!$line) {
            // Fallback by product id
            $pid = isset($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data']->get_id() : 0;
            foreach ($est['lines'] as $ln) { if ((int)$ln['product_id'] === (int)$pid) { $line = $ln; break; } }
            if (!$line) { return $product_name; }
        }
        $currency = WRD_Duty_Engine::current_currency();
        $dutyStore = WRD_FX::convert((float)$line['duty_usd'], 'USD', $currency);
        $badge = !empty($line['cusma']) ? '<span class="wrd-badge" style="background:#e7f7ed;color:#0a6d2c;border:1px solid #bfe8cf;border-radius:3px;padding:0 4px;font-size:11px;margin-left:4px;vertical-align:middle;">CUSMA</span>' : '';
        $hint = '';
        if ($dutyStore > 0.0001) {
            $hint = '<span class="wrd-inline-duty-amt" style="color:#555;">' . esc_html__('Duty est:', 'woocommerce-us-duties') . ' ' . wc_price($dutyStore) . '</span>';
        } elseif (!empty($line['cusma'])) {
            $hint = '<span class="wrd-inline-duty-amt" style="color:#555;">' . esc_html__('Duty:', 'woocommerce-us-duties') . ' ' . esc_html__('CUSMA', 'woocommerce-us-duties') . '</span>';
        } else {
            return $product_name; // nothing to add
        }
        // Place on a new line below the product name for readability
        return $product_name . '<br/><small class="wrd-inline-duty" style="font-size:12px;">' . $hint . ' ' . $badge . '</small>';
    }
}
