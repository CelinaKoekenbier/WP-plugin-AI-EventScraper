<?php
/**
 * Plugin Name: Events to Posts Scraper
 * Plugin URI: https://github.com/your-username/apify-events-to-posts
 * Description: Automatically discovers and imports Dutch events from the web using free methods (Google Custom Search + web scraping). Creates draft posts with event details.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: apify-events-to-posts
 * Domain Path: /languages
 */

/**
 * Bootstrap file.
 *
 * This file is intentionally small; it wires the WordPress lifecycle to the
 * object-oriented code that lives in `/src`.
 *
 * Responsibilities:
 * - Define plugin constants (`APIFY_EVENTS_PLUGIN_DIR`, `APIFY_EVENTS_PLUGIN_URL`, etc.).
 * - Register the PSR-4 autoloader that loads classes stored in `/src`.
 * - Instantiate `ApifyEvents\Plugin`, which further hooks admin UI, cron and AJAX.
 *
 * For a map of the rest of the codebase see the README section “Code Map”,
 * or jump directly into:
 * - `src/Plugin.php` → master service container, activation hooks.
 * - `src/Runner.php` → cron/manual execution flow.
 * - `src/Extractor.php` → HTML/schema parsing helpers.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('APIFY_EVENTS_VERSION', '1.0.0');
define('APIFY_EVENTS_PLUGIN_FILE', __FILE__);
define('APIFY_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('APIFY_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('APIFY_EVENTS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'ApifyEvents\\';
    $base_dir = APIFY_EVENTS_PLUGIN_DIR . 'src/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    error_log("Apify Events: Autoloading class {$class} from file {$file}");
    
    if (file_exists($file)) {
        error_log("Apify Events: Loading file {$file}");
        require $file;
    } else {
        error_log("Apify Events: File not found: {$file}");
    }
});

// Initialize the plugin
add_action('plugins_loaded', function () {
    error_log('Apify Events: plugins_loaded hook called');
    if (class_exists('ApifyEvents\\Plugin')) {
        error_log('Apify Events: Plugin class exists, getting instance');
        ApifyEvents\Plugin::getInstance();
        error_log('Apify Events: Plugin instance created');
    } else {
        error_log('Apify Events: Plugin class does not exist');
    }
});

// Activation hook
register_activation_hook(__FILE__, function () {
    if (class_exists('ApifyEvents\\Plugin')) {
        ApifyEvents\Plugin::activate();
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    if (class_exists('ApifyEvents\\Plugin')) {
        ApifyEvents\Plugin::deactivate();
    }
});
