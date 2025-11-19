<?php
if (!defined('ABSPATH')) { exit; }

class WRD_DB {
    public static function table_profiles(): string {
        global $wpdb;
        return $wpdb->prefix . 'wrd_customs_profiles';
    }

    public static function install_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_profiles();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            description_raw TEXT NOT NULL,
            description_normalized VARCHAR(512) NOT NULL,
            country_code CHAR(2) NOT NULL,
            hs_code VARCHAR(20) NOT NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'legacy',
            last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            us_duty_json JSON NOT NULL,
            fta_flags JSON NOT NULL,
            effective_from DATE NOT NULL DEFAULT (CURRENT_DATE),
            effective_to DATE NULL DEFAULT NULL,
            notes TEXT NULL,
            PRIMARY KEY  (id),
            KEY idx_desc_norm_country_active (description_normalized, country_code, effective_from, effective_to)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    public static function maybe_upgrade(): void {
    $target = '1.3'; // bump when schema changes
        $current = get_option('wrd_us_duty_db_version');
        if ($current !== $target) {
            self::install_tables();
            update_option('wrd_us_duty_db_version', $target);
        }
    }

    public static function normalize_description(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        // Ensure it fits the DB column size (VARCHAR(512)); use mb_substr when available
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s, 'UTF-8') > 512) {
                $s = mb_substr($s, 0, 512, 'UTF-8');
            }
        } else {
            if (strlen($s) > 512) {
                $s = substr($s, 0, 512);
            }
        }
        return $s;
    }

    public static function get_profile(string $descriptionRaw, string $countryCode): ?array {
        global $wpdb;
        $table = self::table_profiles();
        $descNorm = self::normalize_description($descriptionRaw);
        $country = strtoupper($countryCode);
        $now = current_time('mysql');
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE description_normalized = %s AND country_code = %s
               AND (effective_from IS NULL OR effective_from <= DATE(%s))
               AND (effective_to IS NULL OR effective_to >= DATE(%s))
             ORDER BY effective_from DESC
             LIMIT 1",
            $descNorm, $country, $now, $now
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!$row) { return null; }
        // decode JSON columns
        foreach (['us_duty_json','fta_flags'] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $row[$col] = json_decode($row[$col], true);
            }
        }
        return $row;
    }

    public static function get_profile_by_id(int $id): ?array {
        if ($id <= 0) { return null; }
        global $wpdb; $table = self::table_profiles();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
        if (!$row) { return null; }
        foreach (['us_duty_json','fta_flags'] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) { $row[$col] = json_decode($row[$col], true); }
        }
        return $row;
    }

    public static function find_active_profile_id(string $descriptionRaw, string $countryCode): int {
        global $wpdb; $table = self::table_profiles();
        $descNorm = self::normalize_description($descriptionRaw);
        $country = strtoupper($countryCode);
        $now = current_time('mysql');
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE description_normalized=%s AND country_code=%s
               AND (effective_from IS NULL OR effective_from <= DATE(%s))
               AND (effective_to IS NULL OR effective_to >= DATE(%s))
             ORDER BY effective_from DESC
             LIMIT 1",
            $descNorm, $country, $now, $now
        ));
        return $id > 0 ? $id : 0;
    }
}
