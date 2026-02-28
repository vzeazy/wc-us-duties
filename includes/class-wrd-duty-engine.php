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

    private static function normalize_rate_to_percent($rate): float {
        if (!is_numeric($rate)) { return 0.0; }
        $value = (float)$rate;
        return ($value >= 1.0) ? $value : ($value * 100.0);
    }

    private static function is_section_232_code(string $code): bool {
        return strpos(sanitize_key($code), '232') !== false;
    }

    private static function resolve_product_metal_value_usd(\WC_Product $product, float $qty = 1.0): ?float {
        $qty = max(1.0, $qty);
        $mode = 'inherit';
        if ($product->is_type('variation')) {
            $modeRaw = (string)$product->get_meta('_wrd_232_basis_mode', true);
            $mode = in_array($modeRaw, ['inherit', 'explicit', 'none'], true) ? $modeRaw : 'inherit';
            if ($mode === 'none') {
                return 0.0;
            }
            if ($mode === 'explicit') {
                $val = $product->get_meta('_wrd_232_metal_value_usd', true);
                return is_numeric($val) ? max(0.0, (float)$val) * $qty : null;
            }
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                $val = $parent->get_meta('_wrd_232_metal_value_usd', true);
                return is_numeric($val) ? max(0.0, (float)$val) * $qty : null;
            }
            return null;
        }

        $val = $product->get_meta('_wrd_232_metal_value_usd', true);
        return is_numeric($val) ? max(0.0, (float)$val) * $qty : null;
    }

    private static function normalize_channel_components(array $us_duty_json, string $channel): array {
        $channel = strtolower($channel);
        if (empty($us_duty_json[$channel]) || !is_array($us_duty_json[$channel])) {
            return [];
        }
        $node = $us_duty_json[$channel];
        $components = [];

        if (!empty($node['components']) && is_array($node['components'])) {
            foreach ($node['components'] as $idx => $component) {
                if (!is_array($component)) { continue; }
                $ratePct = self::normalize_rate_to_percent($component['rate'] ?? null);
                if ($ratePct <= 0) { continue; }
                $code = sanitize_key((string)($component['code'] ?? 'component_' . $idx));
                if ($code === '') { $code = 'component_' . $idx; }
                $basis = sanitize_key((string)($component['basis'] ?? 'line_value_usd'));
                if (!in_array($basis, ['line_value_usd', 'product_metal_value_usd'], true)) {
                    $basis = 'line_value_usd';
                }
                $components[] = [
                    'code' => $code,
                    'label' => (string)($component['label'] ?? $code),
                    'rate_pct' => $ratePct,
                    'basis' => $basis,
                    'order' => isset($component['order']) ? (int)$component['order'] : (100 + $idx),
                    'enabled' => !isset($component['enabled']) || (bool)$component['enabled'],
                ];
            }
        }

        if (isset($node['rates']) && (is_array($node['rates']) || is_object($node['rates']))) {
            $rates = is_object($node['rates']) ? (array)$node['rates'] : $node['rates'];
            foreach ($rates as $rateKey => $rateValue) {
                if (!is_numeric($rateValue)) { continue; }
                $code = sanitize_key((string)$rateKey);
                if ($code === '') { $code = 'base'; }
                $exists = false;
                foreach ($components as $component) {
                    if ($component['code'] === $code) { $exists = true; break; }
                }
                if ($exists) { continue; }
                $components[] = [
                    'code' => $code,
                    'label' => (string)$rateKey,
                    'rate_pct' => self::normalize_rate_to_percent($rateValue),
                    'basis' => 'line_value_usd',
                    'order' => 50,
                    'enabled' => true,
                ];
            }
        }

        usort($components, static function ($a, $b) {
            $ao = (int)($a['order'] ?? 999);
            $bo = (int)($b['order'] ?? 999);
            if ($ao === $bo) { return strcmp((string)$a['code'], (string)$b['code']); }
            return $ao <=> $bo;
        });
        return $components;
    }

    private static function compute_line_duty_breakdown(\WC_Product $product, array $profile, string $channel, float $lineValueUsd, float $qty, bool $isCusma): array {
        $components = self::normalize_channel_components($profile['us_duty_json'] ?? [], $channel);
        if (!$components) {
            $ratePct = self::compute_rate_percent($profile['us_duty_json'] ?? [], $channel);
            if ($isCusma) { $ratePct = 0.0; }
            return [
                'total_rate_pct' => $ratePct,
                'total_duty_usd' => ($ratePct / 100.0) * $lineValueUsd,
                'components' => [],
                'missing_232_basis' => false,
            ];
        }

        $outComponents = [];
        $totalDuty = 0.0;
        $missing232Basis = false;
        foreach ($components as $component) {
            if (empty($component['enabled'])) { continue; }
            $code = (string)$component['code'];
            $basis = (string)$component['basis'];
            $ratePct = (float)$component['rate_pct'];

            if ($isCusma) {
                $outComponents[] = [
                    'code' => $code,
                    'label' => (string)$component['label'],
                    'rate_pct' => $ratePct,
                    'basis' => $basis,
                    'basis_value_usd' => 0.0,
                    'duty_usd' => 0.0,
                    'applied' => false,
                    'reason' => 'cusma_exempt',
                ];
                continue;
            }

            $basisValue = 0.0;
            $applied = true;
            $reason = 'applied';
            if ($basis === 'product_metal_value_usd') {
                $metalValue = self::resolve_product_metal_value_usd($product, $qty);
                if ($metalValue === null) {
                    $applied = false;
                    $reason = 'missing_product_metal_value_usd';
                    $missing232Basis = $missing232Basis || self::is_section_232_code($code);
                } else {
                    $basisValue = max(0.0, (float)$metalValue);
                }
            } else {
                $basisValue = max(0.0, $lineValueUsd);
            }

            $dutyUsd = $applied ? (($ratePct / 100.0) * $basisValue) : 0.0;
            $totalDuty += $dutyUsd;
            $outComponents[] = [
                'code' => $code,
                'label' => (string)$component['label'],
                'rate_pct' => $ratePct,
                'basis' => $basis,
                'basis_value_usd' => $basisValue,
                'duty_usd' => $dutyUsd,
                'applied' => $applied,
                'reason' => $reason,
            ];
        }

        $totalRatePct = $lineValueUsd > 0 ? (($totalDuty / $lineValueUsd) * 100.0) : 0.0;
        return [
            'total_rate_pct' => $totalRatePct,
            'total_duty_usd' => $totalDuty,
            'components' => $outComponents,
            'missing_232_basis' => $missing232Basis,
        ];
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

            // Get HS code and origin with category fallback
            $effective = WRD_Category_Settings::get_effective_hs_code($product);
            $hs_code = $effective['hs_code'];
            $origin = $effective['origin'];

            // Fallback to legacy _customs_description for backward compatibility
            $desc = $product->get_meta('_customs_description', true);
            if ($product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
                if ($parent && $desc === '') {
                    $desc = $parent->get_meta('_customs_description', true) ?: $desc;
                }
            }
            $desc = is_string($desc) ? trim($desc) : '';

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
            } elseif ($dest === 'US' && $profile && !empty($profile['fta_flags']) && is_array($profile['fta_flags'])) {
                $isCusma = in_array('CUSMA', $profile['fta_flags'], true) && in_array(strtoupper((string)$origin), ['CA','US','MX'], true);
            }

            $breakdown = $profile
                ? self::compute_line_duty_breakdown($product, $profile, $channel, $valueUsd, $qty, $isCusma)
                : ['total_rate_pct' => 0.0, 'total_duty_usd' => 0.0, 'components' => [], 'missing_232_basis' => false];
            $ratePct = (float)$breakdown['total_rate_pct'];
            $dutyUsd = (float)$breakdown['total_duty_usd'];

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
                'components' => $breakdown['components'],
                'missing_232_basis' => !empty($breakdown['missing_232_basis']),
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
                'components' => $breakdown['components'],
                'missing_232_basis' => !empty($breakdown['missing_232_basis']),
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
        // Get HS code and origin with category fallback
        $effective = WRD_Category_Settings::get_effective_hs_code($product);
        $hs_code = $effective['hs_code'];
        $origin = $effective['origin'];

        // Fallback to legacy _customs_description for backward compatibility
        $desc = $product->get_meta('_customs_description', true);
        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent && $desc === '') {
                $desc = $parent->get_meta('_customs_description', true) ?: $desc;
            }
        }
        $desc = is_string($desc) ? trim($desc) : '';

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
        // CUSMA
        $isCusma = false;
        $cusmaEnabled = !empty($settings['cusma_auto']);
        $cusmaList = array_filter(array_map('trim', explode(',', strtoupper((string)($settings['cusma_countries'] ?? 'CA,US')))));
        if ($dest === 'US' && $cusmaEnabled && in_array($origin, $cusmaList, true)) {
            $isCusma = true;
        } elseif ($dest === 'US' && $profile && !empty($profile['fta_flags']) && is_array($profile['fta_flags'])) {
            $isCusma = in_array('CUSMA', $profile['fta_flags'], true) && in_array($origin, ['CA','US','MX'], true);
        }
        $valueStore = (float)$product->get_price() * max(1, $qty);
        $valueUsd = self::to_usd($valueStore, $currency);
        $breakdown = $profile
            ? self::compute_line_duty_breakdown($product, $profile, $channel, $valueUsd, (float)max(1, $qty), $isCusma)
            : ['total_rate_pct' => 0.0, 'total_duty_usd' => 0.0, 'components' => [], 'missing_232_basis' => false];
        $ratePct = (float)$breakdown['total_rate_pct'];
        $dutyUsd = (float)$breakdown['total_duty_usd'];

        return [
            'rate_pct' => $ratePct,
            'duty_usd' => $dutyUsd,
            'duty_store' => WRD_FX::convert($dutyUsd, 'USD', $currency),
            'channel' => $channel,
            'cusma' => $isCusma,
            'origin' => $origin,
            'dest' => $dest,
            'components' => $breakdown['components'],
            'missing_232_basis' => !empty($breakdown['missing_232_basis']),
        ];
    }
}
