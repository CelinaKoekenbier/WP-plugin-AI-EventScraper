<?php

namespace ApifyEvents;

/**
 * Settings page handler.
 *
 * Responsibilities:
 * - Render the admin screen (Settings ▸ Events Scraper).
 * - Output contextual notices, run-now/test buttons and the last run log.
 * - Provide field renderers that `Plugin::registerSettings()` hooks up.
 *
 * Related components:
 * - `Plugin::registerSettings()` wires the fields and sanitisation.
 * - `assets/admin.js` handles AJAX interactions for the buttons.
 * - `Utils::getOptions()` stores/retrieves the underlying option array.
 */
class Settings
{
    private const HEADER_IMAGE_RELATIVE_PATH = 'assets/admin-header.png';
    private const FOOTER_IMAGE_RELATIVE_PATH = 'assets/admin-footer-grass.png';

    /**
     * Render settings page
     */
    public function render()
    {
        ?>
        <div class="wrap">
            <?php $this->renderHeaderImage(); ?>
            <h1 class="apify-events-page-title"><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info" style="margin: 15px 0;">
                <p><strong>🎉 FREE Version Available!</strong> This plugin works WITHOUT any paid subscriptions!</p>
                <p>✅ <strong>Quick Start:</strong> Scroll down to <strong>"Manual URLs (Free)"</strong> → Add event URLs → Save → Click "Run Now"</p>
                <p>ℹ️ You can leave "Apify Token" empty. It's only needed for the optional paid method ($49+/month).</p>
            </div>
            
            <div class="apify-events-settings">
                <div class="apify-events-main">
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('apify_events_settings');
                        do_settings_sections('apify_events_settings');
                        submit_button();
                        ?>
                    </form>
                </div>
                
                <div class="apify-events-sidebar">
                    <div class="apify-events-run-now">
                        <h3><?php _e('Manual Run', 'apify-events-to-posts'); ?></h3>
                        <p><?php _e('Run the event discovery process now:', 'apify-events-to-posts'); ?></p>
                        <button type="button" id="apify-run-now" class="button button-primary">
                            <?php _e('Run Now', 'apify-events-to-posts'); ?>
                        </button>
                        <button type="button" id="apify-test-connection" class="button">
                            <?php _e('Test Connection', 'apify-events-to-posts'); ?>
                        </button>
                        <div id="apify-run-status" class="apify-run-status"></div>
                    </div>
                    
                    <div class="apify-events-logs">
                        <h3><?php _e('Last Run Log', 'apify-events-to-posts'); ?></h3>
                        <?php $this->renderLastRunLog(); ?>
                    </div>
                    
                    <div class="apify-events-info">
                        <h3><?php _e('Plugin Info', 'apify-events-to-posts'); ?></h3>
                        <p><?php _e('This plugin automatically discovers Dutch events using Apify and saves them as draft posts.', 'apify-events-to-posts'); ?></p>
                        <p><strong><?php _e('Next scheduled run:', 'apify-events-to-posts'); ?></strong><br>
                        <?php
                        $next_run = wp_next_scheduled('apify_events_weekly') ?: wp_next_scheduled('apify_events_monthly');
                        if ($next_run) {
                            echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run));
                            echo ' <small>(' . (wp_next_scheduled('apify_events_weekly') ? __('weekly', 'apify-events-to-posts') : __('monthly', 'apify-events-to-posts')) . ')</small>';
                        } else {
                            _e('Not scheduled', 'apify-events-to-posts');
                        }
                        ?></p>
                        
                        <p><strong><?php _e('Last successful run:', 'apify-events-to-posts'); ?></strong><br>
                        <?php
                        $last_run = get_option('apify_events_last_run', 0);
                        if ($last_run) {
                            echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_run));
                        } else {
                            _e('Never', 'apify-events-to-posts');
                        }
                        ?></p>
                    </div>
                </div>
            </div>
            <?php $this->renderFooterImage(); ?>
        </div>
        <?php
    }

    private function renderHeaderImage(): void
    {
        $url = $this->getPluginAssetUrl(self::HEADER_IMAGE_RELATIVE_PATH);
        if (!$url) {
            return;
        }
        echo '<div class="apify-events-page-header"><img src="' . esc_url($url) . '" alt="' . esc_attr__('Plugin header', 'apify-events-to-posts') . '" /></div>';
    }

    private function renderFooterImage(): void
    {
        $url = $this->getPluginAssetUrl(self::FOOTER_IMAGE_RELATIVE_PATH);
        if (!$url) {
            return;
        }
        echo '<div class="apify-events-page-footer"><img src="' . esc_url($url) . '" alt="' . esc_attr__('Plugin footer', 'apify-events-to-posts') . '" /></div>';
    }

    private function getPluginAssetUrl(string $relativePath): ?string
    {
        if (!defined('APIFY_EVENTS_PLUGIN_FILE')) {
            return null;
        }
        $absPath = plugin_dir_path(APIFY_EVENTS_PLUGIN_FILE) . ltrim($relativePath, '/');
        if (!file_exists($absPath)) {
            return null;
        }
        return plugins_url(ltrim($relativePath, '/'), APIFY_EVENTS_PLUGIN_FILE);
    }

    /**
     * Render last run log
     */
    private function renderLastRunLog()
    {
        $log = get_option('apify_events_last_run_log', '');
        
        if (empty($log)) {
            echo '<div class="apify-logs empty">' . __('No logs available', 'apify-events-to-posts') . '</div>';
            return;
        }
        
        // Parse and format log
        $log_data = json_decode($log, true);
        if (!$log_data) {
            echo '<div class="apify-logs">' . esc_html($log) . '</div>';
            return;
        }
        
        $formatted_log = '';
        if (isset($log_data['timestamp'])) {
            $timezone_string = get_option('timezone_string') ?: 'Europe/Amsterdam';
            $formatted_log .= 'Run: ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $log_data['timestamp'], new \DateTimeZone($timezone_string)) . "\n";
        }
        
        if (isset($log_data['stats'])) {
            $stats = $log_data['stats'];
            $formatted_log .= "\nStatistics:\n";
            $formatted_log .= "- URLs discovered: " . ($stats['discovered'] ?? 0) . "\n";
            $formatted_log .= "- Pages fetched: " . ($stats['fetched'] ?? 0) . "\n";
            $formatted_log .= "- Events parsed: " . ($stats['parsed'] ?? 0) . "\n";
            $formatted_log .= "- Posts imported: " . ($stats['imported'] ?? 0) . "\n";
            $formatted_log .= "- Posts skipped: " . ($stats['skipped'] ?? 0) . "\n";
            if (!empty($stats['sample_urls'])) {
                $formatted_log .= "\nURLs procesadas (muestra):\n";
                foreach ($stats['sample_urls'] as $u) {
                    if ($u) $formatted_log .= "- " . $u . "\n";
                }
            }
        }
        
        if (isset($log_data['errors']) && !empty($log_data['errors'])) {
            $formatted_log .= "\nErrors:\n";
            foreach ($log_data['errors'] as $error) {
                $formatted_log .= "- " . $error . "\n";
            }
        }
        
        if (isset($log_data['skipped_reasons']) && !empty($log_data['skipped_reasons'])) {
            $formatted_log .= "\nSkip reasons:\n";
            foreach ($log_data['skipped_reasons'] as $reason => $count) {
                $formatted_log .= "- " . $reason . ": " . $count . "\n";
            }
        }
        
        echo '<div class="apify-logs">' . esc_html($formatted_log) . '</div>';
    }
}
