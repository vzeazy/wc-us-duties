<?php
if (!defined('ABSPATH')) { exit; }

class WRD_Duty_Engine {
    public static function current_currency(): string {
        return WRD_FX::current_currency();
    }

    public static function to_usd(float $amount, string $currency = null): float {
        $currency = $currency ?: self::current_currency();
        return WRD_FX::convert($amount, $currency, 'USD');
    }

    public static function compute_rate_percent(array $us_duty_json, string $channel): float {
        $channel = strtolower($channel);
        if (empty($us_duty_json[$channel]['rates']) || !is_array($us_duty_json[$channel]['rates'])) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($us_duty_json[$channel]['rates'] as $k => $v) {
            $sum += (float)$v;
        }
        // If rates are given as fractions (0.053) convert to %; if given as 5.3 treat as % directly
        if ($sum <= 1.0) {
            return $sum * 100.0;
        }
        return $sum * 100.0; // our JSON appears fractional; keep as percentage points
    }

    public static function decide_channel(string $countryCode): string {
        $cc = strtoupper($countryCode);
        // Basic preferences per docs
        if ($cc === 'TW') return 'postal';
        if ($cc === 'CN') return 'postal';
        if ($cc === 'CA') return 'commercial';
        return 'commercial';
    }

    public static function cart_channel_override(): ?array {
        // Decide channel from chosen shipping method per settings mapping
        $settings = get_option(WRD_Settings::OPTION, []);
        // 1) Explicit map from settings (method_id:instance_id or method_id)
        $map = isset($settings['shipping_channel_map']) && is_array($settings['shipping_channel_map']) ? $settings['shipping_channel_map'] : [];
        $rules_raw = isset($settings['shipping_channel_rules']) ? (string)$settings['shipping_channel_rules'] : '';
        $rules = [];
        foreach (preg_split('/\r?\n/', $rules_raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) { continue; }
            [$pattern, $channel] = array_map('trim', explode('|', $line, 2));
            $channel = strtolower($channel);
            if (!in_array($channel, ['postal','commercial'], true)) { continue; }
            $rules[] = [$pattern, $channel];
        }
        if (!$rules) { return null; }

        $chosen = WC()->session ? (array) WC()->session->get('chosen_shipping_methods') : [];
        $label = '';
        $method_id = '';
        if (!empty($chosen)) {
            $chosen_id = $chosen[0]; // first package
            $method_id = (string)$chosen_id;
            if (WC()->shipping()) {
                $packages = WC()->shipping()->get_packages();
                if (!empty($packages[0]['rates'])) {
                    $rates = $packages[0]['rates'];
                    if (isset($rates[$chosen_id])) {
                        $label = $rates[$chosen_id]->get_label();
                    }
                }
            }
            // exact id match (e.g., flat_rate:3)
            if (!empty($map[$method_id]) && in_array($map[$method_id], ['postal','commercial'], true)) {
                return ['channel' => $map[$method_id], 'source' => 'map_exact', 'method_id' => $method_id, 'label' => $label];
            }
            // method family match (e.g., flat_rate)
            $family = strpos($method_id, ':') !== false ? strstr($method_id, ':', true) : $method_id;
            if (!empty($map[$family]) && in_array($map[$family], ['postal','commercial'], true)) {
                return ['channel' => $map[$family], 'source' => 'map_family', 'method_id' => $method_id, 'label' => $label];
            }
        }
        $hay = strtolower($label . ' ' . $method_id);
        foreach ($rules as [$pattern, $channel]) {
            if ($pattern !== '' && stripos($hay, strtolower($pattern)) !== false) {
                return ['channel' => $channel, 'source' => 'keyword', 'pattern' => $pattern, 'method_id' => $method_id, 'label' => $label];
            }
        }
        // Default from settings
        $def = $settings['default_shipping_channel'] ?? 'auto';
        if (in_array($def, ['postal','commercial'], true)) {
            return ['channel' => $def, 'source' => 'default', 'method_id' => $method_id, 'label' => $label];
        }
        return null;
    }

    public static function estimate_cart_duties(): array {
        if (!WC()->cart) { return ['total_usd' => 0.0, 'lines' => [], 'scenario' => 'none']; }
        $currency = self::current_currency();
        $overrideData = self::cart_channel_override();
        $dest = '';
        if (WC()->customer) {
            $dest = strtoupper((string)WC()->customer->get_shipping_country());
            if ($dest === '' || $dest === 'XX') {
                $dest = strtoupper((string)WC()->customer->get_billing_country());
            }
        }
        $settings = get_option(WRD_Settings::OPTION, []);
        $cusmaEnabled = !empty($settings['cusma_auto']);
        $cusmaList = array_filter(array_map('trim', explode(',', strtoupper((string)($settings['cusma_countries'] ?? 'CA,US')))));

        $lines = [];
        $totalUsd = 0.0;
        $missingProfiles = 0;
        $composition = [ 'cusma_value_usd' => 0.0, 'non_cusma_value_usd' => 0.0, 'total_value_usd' => 0.0 ];
        $channelsUsed = [];

        foreach (WC()->cart->get_cart() as $cart_item_key => $item) {
            $product = $item['data'];
            if (!$product) { continue; }
            $qty = (float) $item['quantity'];
            $value = (float) $product->get_price() * $qty; // store currency
            $valueUsd = self::to_usd($value, $currency);

            $desc = $product->get_meta('_customs_description', true);
            $origin = $product->get_meta('_country_of_origin', true);
            if ($origin === '' && $product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if ($parent) {
                    $origin = $parent->get_meta('_country_of_origin', true) ?: $origin;
                    $desc = $desc ?: $parent->get_meta('_customs_description', true);
                }
            }
            // Normalize
            $desc = is_string($desc) ? trim($desc) : '';
            $origin = is_string($origin) ? strtoupper(trim($origin)) : '';

            $profile = ($desc && $origin) ? WRD_DB::get_profile($desc, $origin) : null;
            if (!$profile) { $missingProfiles++; }
            $channel = $overrideData ? $overrideData['channel'] : ($origin ? self::decide_channel($origin) : 'commercial');

            // Determine CUSMA eligibility first (line-item)
            $isCusma = false;
            if ($dest === 'US' && $cusmaEnabled && in_array(strtoupper((string)$origin), $cusmaList, true)) {
                $isCusma = true;
            } elseif ($profile && !empty($profile['fta_flags']) && is_array($profile['fta_flags'])) {
                $isCusma = in_array('CUSMA', $profile['fta_flags'], true) && in_array(strtoupper((string)$origin), ['CA','US','MX'], true);
            }

            $ratePct = $profile ? self::compute_rate_percent($profile['us_duty_json'], $channel) : 0.0;
            if ($isCusma) { $ratePct = 0.0; }
            $dutyUsd = ($ratePct / 100.0) * $valueUsd;

            $composition['total_value_usd'] += $valueUsd;
            if ($isCusma) { $composition['cusma_value_usd'] += $valueUsd; } else { $composition['non_cusma_value_usd'] += $valueUsd; }

            // Debug info per line
            $debug = [
                'dest' => $dest,
                'cusma_enabled' => (bool)$cusmaEnabled,
                'cusma_list' => $cusmaList,
                'cusma_applied' => $isCusma,
                'cusma_reason' => $isCusma ? (($dest === 'US' && in_array($origin, $cusmaList, true)) ? 'dest_in_list' : 'fta_flag') : 'none',
                'profile_found' => (bool)$profile,
                'channel_source' => $overrideData ? ($overrideData['source'] ?? 'map') : 'heuristic',
                'method_id' => $overrideData['method_id'] ?? '',
                'method_label' => $overrideData['label'] ?? '',
                'keyword_pattern' => $overrideData['pattern'] ?? '',
                'rate_pct' => $ratePct,
                'value_usd' => $valueUsd,
                'duty_usd' => $dutyUsd,
            ];

            $lines[] = [
                'key' => $cart_item_key,
                'product_id' => $product->get_id(),
                'desc' => $desc,
                'origin' => $origin,
                'channel' => $channel,
                'rate_pct' => $ratePct,
                'value_usd' => $valueUsd,
                'duty_usd' => $dutyUsd,
                'cusma' => $isCusma,
                'debug' => $debug,
            ];
            $totalUsd += $dutyUsd;
            $channelsUsed[$channel] = true;
        }

        // Basic Case B stub: if mixed and total > 800 USD, don't change duties yet, just tag scenario
        $scenario = 'single';
        $mixed = ($composition['cusma_value_usd'] > 0 && $composition['non_cusma_value_usd'] > 0);
        if ($mixed && $composition['total_value_usd'] > 800) {
            $scenario = 'single'; // placeholder; optimizer can be added in P1
        }

        // Per-order channel fees
        $settings = get_option(WRD_Settings::OPTION, []);
        $feesUsd = 0.0;
        $fees = [];
        if (!empty($channelsUsed['commercial'])) {
            $fee = (float)($settings['commercial_brokerage_flat_usd'] ?? 0);
            if ($fee > 0) { $feesUsd += $fee; $fees[] = ['label' => 'Commercial brokerage', 'channel' => 'commercial', 'amount_usd' => $fee]; }
        }
        if (!empty($channelsUsed['postal'])) {
            $fee = (float)($settings['postal_clearance_fee_usd'] ?? 0);
            if ($fee > 0) { $feesUsd += $fee; $fees[] = ['label' => 'Postal clearance', 'channel' => 'postal', 'amount_usd' => $fee]; }
        }

        return [
            'total_usd' => $totalUsd,
            'fees_usd' => $feesUsd,
            'fees' => $fees,
            'lines' => $lines,
            'composition' => $composition,
            'scenario' => $scenario,
            'missing_profiles' => $missingProfiles,
        ];
    }

    public static function estimate_for_product($product, int $qty = 1): ?array {
        if (!$product instanceof \WC_Product) { return null; }
        $settings = get_option(WRD_Settings::OPTION, []);
        $currency = self::current_currency();
        $desc = $product->get_meta('_customs_description', true);
        $origin = $product->get_meta('_country_of_origin', true);
        if ($origin === '' && $product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                $origin = $parent->get_meta('_country_of_origin', true) ?: $origin;
                $desc = $desc ?: $parent->get_meta('_customs_description', true);
            }
        }
        $desc = is_string($desc) ? trim($desc) : '';
        $origin = is_string($origin) ? strtoupper(trim($origin)) : '';
        if ($desc === '' || $origin === '') { return null; }

        // Destination
        $dest = '';
        if (WC()->customer) {
            $dest = strtoupper((string)WC()->customer->get_shipping_country());
            if ($dest === '' || $dest === 'XX') {
                $dest = strtoupper((string)WC()->customer->get_billing_country());
            }
        }

        // Channel: prefer default mapping if set, else heuristic
        $def = $settings['default_shipping_channel'] ?? 'auto';
        if (in_array($def, ['postal','commercial'], true)) {
            $channel = $def;
        } else {
            $channel = self::decide_channel($origin);
        }

        // Profile and rate
        $profile = WRD_DB::get_profile($desc, $origin);
        $ratePct = $profile ? self::compute_rate_percent($profile['us_duty_json'], $channel) : 0.0;

        // CUSMA
        $isCusma = false;
        $cusmaEnabled = !empty($settings['cusma_auto']);
        $cusmaList = array_filter(array_map('trim', explode(',', strtoupper((string)($settings['cusma_countries'] ?? 'CA,US')))));
        if ($dest === 'US' && $cusmaEnabled && in_array($origin, $cusmaList, true)) {
            $isCusma = true;
        } elseif ($profile && !empty($profile['fta_flags']) && is_array($profile['fta_flags'])) {
            $isCusma = in_array('CUSMA', $profile['fta_flags'], true) && in_array($origin, ['CA','US','MX'], true);
        }
        if ($isCusma) { $ratePct = 0.0; }

        $valueStore = (float)$product->get_price() * max(1, $qty);
        $valueUsd = self::to_usd($valueStore, $currency);
        $dutyUsd = ($ratePct / 100.0) * $valueUsd;

        return [
            'rate_pct' => $ratePct,
            'duty_usd' => $dutyUsd,
            'duty_store' => WRD_FX::convert($dutyUsd, 'USD', $currency),
            'channel' => $channel,
            'cusma' => $isCusma,
            'origin' => $origin,
            'dest' => $dest,
        ];
    }
}
