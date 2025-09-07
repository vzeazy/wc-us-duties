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

    public static function cart_channel_override(): ?string {
        // Decide channel from chosen shipping method per settings mapping
        $settings = get_option(WRD_Settings::OPTION, []);
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
        }
        $hay = strtolower($label . ' ' . $method_id);
        foreach ($rules as [$pattern, $channel]) {
            if ($pattern !== '' && stripos($hay, strtolower($pattern)) !== false) {
                return $channel;
            }
        }
        return null;
    }

    public static function estimate_cart_duties(): array {
        if (!WC()->cart) { return ['total_usd' => 0.0, 'lines' => [], 'scenario' => 'none']; }
        $currency = self::current_currency();
        $overrideChannel = self::cart_channel_override();
        $dest = (WC()->customer) ? strtoupper((string)WC()->customer->get_shipping_country()) : '';
        $settings = get_option(WRD_Settings::OPTION, []);
        $cusmaEnabled = !empty($settings['cusma_auto']);
        $cusmaList = array_filter(array_map('trim', explode(',', strtoupper((string)($settings['cusma_countries'] ?? 'CA,US')))));

        $lines = [];
        $totalUsd = 0.0;
        $missingProfiles = 0;
        $composition = [ 'cusma_value_usd' => 0.0, 'non_cusma_value_usd' => 0.0, 'total_value_usd' => 0.0 ];

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

            $profile = ($desc && $origin) ? WRD_DB::get_profile($desc, $origin) : null;
            if (!$profile) { $missingProfiles++; }
            $channel = $overrideChannel ?: ($origin ? self::decide_channel($origin) : 'commercial');

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

            $lines[] = [
                'product_id' => $product->get_id(),
                'desc' => $desc,
                'origin' => $origin,
                'channel' => $channel,
                'rate_pct' => $ratePct,
                'value_usd' => $valueUsd,
                'duty_usd' => $dutyUsd,
                'cusma' => $isCusma,
            ];
            $totalUsd += $dutyUsd;
        }

        // Basic Case B stub: if mixed and total > 800 USD, don't change duties yet, just tag scenario
        $scenario = 'single';
        $mixed = ($composition['cusma_value_usd'] > 0 && $composition['non_cusma_value_usd'] > 0);
        if ($mixed && $composition['total_value_usd'] > 800) {
            $scenario = 'single'; // placeholder; optimizer can be added in P1
        }

        return [
            'total_usd' => $totalUsd,
            'lines' => $lines,
            'composition' => $composition,
            'scenario' => $scenario,
            'missing_profiles' => $missingProfiles,
        ];
    }
}
