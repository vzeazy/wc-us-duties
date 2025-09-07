<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Developer-facing template tags and shortcodes to render US duty estimates
 * in themes and custom templates.
 *
 * Exposed helpers:
 * - wrd_us_duty_estimate($product = null, array $args = []): ?array
 * - wrd_us_duty_rate($product = null, array $args = []): ?float
 * - wrd_us_duty_hint($product = null, array $args = []): string
 * - wrd_the_us_duty_hint($product = null, array $args = []): void
 *
 * Shortcode:
 *   [wrd_duty_hint product_id="123" qty="1" show_rate="yes" respect_us_only="yes" show_zero="no" class=""]
 */

if (!function_exists('wrd__resolve_product')) {
    /**
     * @param mixed $product A WC_Product, product/variation ID or null (falls back to global $product)
     * @return WC_Product|null
     */
    function wrd__resolve_product($product) {
        if ($product instanceof WC_Product) { return $product; }
        if (is_numeric($product)) {
            $p = wc_get_product((int)$product);
            return $p instanceof WC_Product ? $p : null;
        }
        // Fallback to the global WooCommerce product if available
        if (isset($GLOBALS['product']) && $GLOBALS['product'] instanceof WC_Product) {
            return $GLOBALS['product'];
        }
        return null;
    }
}

if (!function_exists('wrd_us_duty_estimate')) {
    /**
     * Get an estimate for a single product/variation.
     *
     * @param mixed $product WC_Product or product/variation ID or null for current global product
     * @param array $args { Optional args
     *   @type int    $qty               Quantity (default 1)
     *   @type bool   $respect_us_only   Respect plugin's "US Only" setting and return null if dest != US (default true)
     * }
     * @return array|null { rate_pct, duty_usd, duty_store, channel, cusma, origin, dest }
     */
    function wrd_us_duty_estimate($product = null, array $args = []) {
        $defaults = [
            'qty' => 1,
            'respect_us_only' => true,
        ];
        $args = apply_filters('wrd_us_duty_estimate_args', wp_parse_args($args, $defaults), $product);
        $wc_product = wrd__resolve_product($product);
        if (!$wc_product) { return null; }

        $est = WRD_Duty_Engine::estimate_for_product($wc_product, (int)max(1, (int)$args['qty']));
        if (!$est) { return null; }

        // Respect store-level setting to show estimates only for US destination
        if (!empty($args['respect_us_only'])) {
            $settings = get_option(WRD_Settings::OPTION, []);
            $us_only = !empty($settings['us_only']);
            if ($us_only && isset($est['dest']) && strtoupper((string)$est['dest']) !== 'US') {
                return null;
            }
        }

        return apply_filters('wrd_us_duty_estimate_result', $est, $args, $wc_product);
    }
}

if (!function_exists('wrd_us_duty_rate')) {
    /**
     * Convenience: get only the duty rate percentage for a product (after CUSMA etc.).
     * Returns null if estimate not available.
     */
    function wrd_us_duty_rate($product = null, array $args = []) {
        $est = wrd_us_duty_estimate($product, $args);
        if (!$est) { return null; }
        return (float)($est['rate_pct'] ?? 0.0);
    }
}

if (!function_exists('wrd_us_duty_hint')) {
    /**
     * Build a small HTML hint similar to the built-in product page hint.
     *
     * @param mixed $product WC_Product, product/variation ID, or null for current product
     * @param array $args { Optional controls
     *   @type int     $qty               Quantity (default 1)
     *   @type bool    $respect_us_only   Respect plugin's "US Only" setting (default true)
     *   @type bool    $show_rate         Append (rate%) for non-CUSMA lines (default true)
     *   @type bool    $inline_style      Include minimal inline styles (default true)
     *   @type string  $class             Extra container class (default '')
     *   @type bool    $show_zero         Show when amount is zero and not CUSMA (default false)
     *   @type string  $zero_text         Text when zero and show_zero=true (default '')
     *   @type string  $note              Footer note (default comes from settings product_hint_note)
     * }
     * @return string HTML
     */
    function wrd_us_duty_hint($product = null, array $args = []) {
        $settings = get_option(WRD_Settings::OPTION, []);
        $defaults = [
            'qty' => 1,
            'respect_us_only' => true,
            'show_rate' => true,
            'inline_style' => true,
            'class' => '',
            'show_zero' => false,
            'zero_text' => '',
            'note' => isset($settings['product_hint_note']) ? (string)$settings['product_hint_note'] : '',
        ];
        $args = apply_filters('wrd_us_duty_hint_args', wp_parse_args($args, $defaults), $product);

        $wc_product = wrd__resolve_product($product);
        if (!$wc_product) { return ''; }
        $est = wrd_us_duty_estimate($wc_product, [
            'qty' => (int)$args['qty'],
            'respect_us_only' => (bool)$args['respect_us_only'],
        ]);
        if (!$est) { return ''; }

        $classes = 'wrd-product-duty-hint';
        if (!empty($args['class'])) { $classes .= ' ' . sanitize_html_class($args['class']); }
        $style = $args['inline_style'] ? 'margin:8px 0; padding:8px; background:#f8f9fa; border:1px solid #e2e6ea; border-radius:4px;' : '';

        $currency_price = function ($amount) {
            return wc_price((float)$amount);
        };

        if (!empty($est['cusma'])) {
            $label = __('CUSMA: duty-free to US', 'woocommerce-us-duties');
            $rate_html = '';
            $amount_html = '';
        } else {
            $amount_html = $currency_price($est['duty_store'] ?? 0);
            $label = sprintf(__('Estimated US duties: %s', 'woocommerce-us-duties'), $amount_html);
            $rate_html = (!empty($args['show_rate']) && (float)$est['rate_pct'] > 0) ? ' <span class="wrd-hint-rate" style="color:#666;">(' . esc_html(number_format_i18n((float)$est['rate_pct'], 2)) . '%)</span>' : '';
        }

        $note = (string)($args['note'] ?? '');
        $note_html = $note !== '' ? ' <span class="wrd-hint-note" style="color:#666;">' . esc_html($note) . '</span>' : '';

        // Handle zero case when not CUSMA
        if (empty($est['cusma']) && (float)($est['duty_store'] ?? 0) <= 0.0001) {
            if (empty($args['show_zero'])) {
                return '';
            }
            $label = $args['zero_text'] !== '' ? esc_html($args['zero_text']) : $label;
        }

        $html = '<div class="' . esc_attr($classes) . '" style="' . esc_attr($style) . '">'
              . '<span class="wrd-hint-main" style="font-weight:600;">' . wp_kses_post($label) . '</span>'
              . $rate_html
              . $note_html
              . '</div>';

        return apply_filters('wrd_us_duty_hint_html', $html, $est, $args, $wc_product);
    }
}

if (!function_exists('wrd_the_us_duty_hint')) {
    /** Echo the hint HTML. */
    function wrd_the_us_duty_hint($product = null, array $args = []) { echo wrd_us_duty_hint($product, $args); }
}

// Shortcode: [wrd_duty_hint]
add_action('init', function(){
    add_shortcode('wrd_duty_hint', function ($atts) {
        $atts = shortcode_atts([
            'product_id' => '',
            'qty' => '1',
            'respect_us_only' => 'yes',
            'show_rate' => 'yes',
            'inline_style' => 'yes',
            'class' => '',
            'show_zero' => 'no',
            'zero_text' => '',
            'note' => '',
        ], $atts, 'wrd_duty_hint');
        $product = $atts['product_id'] !== '' ? (int)$atts['product_id'] : null;
        $args = [
            'qty' => (int)$atts['qty'],
            'respect_us_only' => in_array(strtolower($atts['respect_us_only']), ['1','true','yes','y'], true),
            'show_rate' => in_array(strtolower($atts['show_rate']), ['1','true','yes','y'], true),
            'inline_style' => in_array(strtolower($atts['inline_style']), ['1','true','yes','y'], true),
            'class' => sanitize_text_field($atts['class']),
            'show_zero' => in_array(strtolower($atts['show_zero']), ['1','true','yes','y'], true),
            'zero_text' => sanitize_text_field($atts['zero_text']),
            'note' => $atts['note'] !== '' ? sanitize_text_field($atts['note']) : null,
        ];
        return wrd_us_duty_hint($product, $args);
    });
});
