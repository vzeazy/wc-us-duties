<?php
if (!defined('ABSPATH')) { exit; }

class WRD_US_Duty_Plugin {
    private static $instance;
    private $admin;

    public static function instance(): self {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    public function init(): void {
        // Ensure DB is up to date
        WRD_DB::maybe_upgrade();
        // Category settings
        (new WRD_Category_Settings())->init();
        // Admin features
        $this->admin = new WRD_Admin();
        $this->admin->init();
        // Settings menu
        (new WRD_Settings())->init();
        // Frontend output
        (new WRD_Frontend())->init();
    }

    public static function activate(): void {
        // Install DB tables
        WRD_DB::install_tables();
        // Maybe schedule events later
    }

    public static function deactivate(): void {
        // No-op for now
    }
}
