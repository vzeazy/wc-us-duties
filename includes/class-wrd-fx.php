<?php
if (!defined('ABSPATH')) { exit; }

class WRD_FX {
    const OPTION = 'wrd_us_duty_settings';

    public static function get_settings(): array {
        $defaults = [
            'fx_enabled' => 1,
            'fx_provider' => 'exchangerate_host',
            'fx_refresh_hours' => 12,
        ];
        $opt = get_option(self::OPTION, []);
        return wp_parse_args(is_array($opt) ? $opt : [], $defaults);
    }

    public static function current_currency(): string {
        // WPML/WC Multicurrency adjusts get_woocommerce_currency() via filters
        return get_woocommerce_currency();
    }

    public static function get_rate(string $from, string $to): float {
        $from = strtoupper($from); $to = strtoupper($to);
        if ($from === $to) { return 1.0; }

        // Allow override via filter
        $override = apply_filters('wrd_duty_fx_rate', null, $from, $to);
        if (is_numeric($override)) { return (float)$override; }

        $settings = self::get_settings();
        if (empty($settings['fx_enabled'])) { return 1.0; }

        // We fetch a rates table for a base and compute via cross-rate
        $base = 'USD';
        $rates = self::get_rates_table($base);
        if (!$rates) { return 1.0; }

        // If converting from X to USD
        if ($to === $base && !empty($rates[$from])) {
            // rates are quoted as 1 USD = rate[CUR]
            // so 1 CUR = 1 / rate[CUR] USD
            return 1.0 / (float)$rates[$from];
        }

        // If converting from USD to X
        if ($from === $base && !empty($rates[$to])) {
            return (float)$rates[$to];
        }

        // Cross conversion: from -> USD -> to
        if (!empty($rates[$from]) && !empty($rates[$to])) {
            $from_usd = 1.0 / (float)$rates[$from];
            $usd_to = (float)$rates[$to];
            return $from_usd * $usd_to;
        }

        return 1.0;
    }

    public static function convert(float $amount, string $from, string $to): float {
        return $amount * self::get_rate($from, $to);
    }

    public static function get_rates_table(string $base = 'USD'): ?array {
        $provider = 'exchangerate_host';
        $settings = self::get_settings();
        if (!empty($settings['fx_provider'])) { $provider = $settings['fx_provider']; }
        $cache_key = 'wrd_fx_rates_' . $provider . '_' . strtoupper($base);
        $rates = get_transient($cache_key);
        if (is_array($rates)) { return $rates; }

        $rates = self::fetch_rates($provider, $base);
        if (is_array($rates)) {
            $hours = max(1, (int)($settings['fx_refresh_hours'] ?? 12));
            set_transient($cache_key, $rates, HOUR_IN_SECONDS * $hours);
            return $rates;
        }
        return null;
    }

    private static function fetch_rates(string $provider, string $base = 'USD'): ?array {
        $base = strtoupper($base);
        switch ($provider) {
            case 'exchangerate_host':
            default:
                // Free, no API key: https://api.exchangerate.host/latest?base=USD
                $url = add_query_arg(['base' => $base], 'https://api.exchangerate.host/latest');
                $res = wp_remote_get($url, ['timeout' => 10]);
                if (is_wp_error($res)) { return null; }
                $code = wp_remote_retrieve_response_code($res);
                if ($code !== 200) { return null; }
                $body = wp_remote_retrieve_body($res);
                $json = json_decode($body, true);
                if (!is_array($json) || empty($json['rates']) || !is_array($json['rates'])) { return null; }
                return $json['rates'];
        }
    }
}

