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
        if (empty($us_duty_json[$channel]['rates'])) {
            return 0.0;
        }
        // Rates might be stored as an object or array; normalize to array of numeric values
        $ratesRaw = $us_duty_json[$channel]['rates'];
        if (is_object($ratesRaw)) { $ratesRaw = (array) $ratesRaw; }
        if (!is_array($ratesRaw)) { return 0.0; }

        $values = [];
        foreach ($ratesRaw as $k => $v) {
            if (is_numeric($v)) { $values[] = (float)$v; }
        }
        if (!$values) { return 0.0; }

        $sum = array_sum($values);
        $max = max($values);

        // Auto-detect: if any component >= 1, treat inputs as percentages (e.g., 5.3 means 5.3%).
        // Otherwise treat inputs as fractional (e.g., 0.053 means 5.3%).
        $isPercent = ($max >= 1.0);
        return $isPercent ? $sum : ($sum * 100.0);
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

            // Get HS code and origin from product
            $hs_code = $product->get_meta('_hs_code', true);
            $origin = $product->get_meta('_country_of_origin', true);

            // Fallback to legacy _customs_description for backward compatibility
            $desc = $product->get_meta('_customs_description', true);

            if ($product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if ($parent) {
                    if ($origin === '') {
                        $origin = $parent->get_meta('_country_of_origin', true) ?: $origin;
                    }
                    if ($hs_code === '') {
                        $hs_code = $parent->get_meta('_hs_code', true) ?: $hs_code;
                    }
                    if ($desc === '') {
                        $desc = $parent->get_meta('_customs_description', true) ?: $desc;
                    }
                }
            }

            // Normalize
            $hs_code = is_string($hs_code) ? trim($hs_code) : '';
            $desc = is_string($desc) ? trim($desc) : '';
            $origin = is_string($origin) ? strtoupper(trim($origin)) : '';

            // Lookup profile: prefer HS code + country, fallback to description + country
            $profile = null;
            $profile_id = (int) $product->get_meta('_wrd_profile_id', true);
            if ($profile_id > 0) {
                $profile = WRD_DB::get_profile_by_id($profile_id);
            }
            if (!$profile && $hs_code && $origin) {
                $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
            }
            if (!$profile && $desc && $origin) {
                // Legacy fallback for products still using description
                $profile = WRD_DB::get_profile($desc, $origin);
            }

            // If we found a profile and product doesn't have description, pull it from profile
            if ($profile && !$desc && isset($profile['description_raw'])) {
                $desc = $profile['description_raw'];
            }
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
        // Get HS code and origin from product
        $hs_code = $product->get_meta('_hs_code', true);
        $origin = $product->get_meta('_country_of_origin', true);

        // Fallback to legacy _customs_description for backward compatibility
        $desc = $product->get_meta('_customs_description', true);

        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent) {
                if ($origin === '') {
                    $origin = $parent->get_meta('_country_of_origin', true) ?: $origin;
                }
                if ($hs_code === '') {
                    $hs_code = $parent->get_meta('_hs_code', true) ?: $hs_code;
                }
                if ($desc === '') {
                    $desc = $parent->get_meta('_customs_description', true) ?: $desc;
                }
            }
        }

        $hs_code = is_string($hs_code) ? trim($hs_code) : '';
        $desc = is_string($desc) ? trim($desc) : '';
        $origin = is_string($origin) ? strtoupper(trim($origin)) : '';

        // Need at least origin; HS code is preferred but desc can be fallback
        if ($origin === '' || ($hs_code === '' && $desc === '')) { return null; }

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

        // Profile and rate - prefer HS code + country, fallback to description
        $profile = null;
        $profile_id = (int) $product->get_meta('_wrd_profile_id', true);
        if ($profile_id > 0) {
            $profile = WRD_DB::get_profile_by_id($profile_id);
        }
        if (!$profile && $hs_code && $origin) {
            $profile = WRD_DB::get_profile_by_hs_country($hs_code, $origin);
        }
        if (!$profile && $desc && $origin) {
            // Legacy fallback for products still using description
            $profile = WRD_DB::get_profile($desc, $origin);
        }

        // If we found a profile and product doesn't have description, pull it from profile
        if ($profile && !$desc && isset($profile['description_raw'])) {
            $desc = $profile['description_raw'];
        }
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
