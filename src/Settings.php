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
            
            <div class="notice notice-info apify-events-notice" style="margin: 15px 0;">
                <p><strong>🎉 Gratis versie beschikbaar!</strong> Deze plugin werkt ZONDER betaalde abonnementen.</p>
                <p>✅ <strong>Snel starten:</strong> Scrol omlaag naar <strong>"Handmatige URL’s (gratis)"</strong> → voeg event-URL’s toe → sla op → klik op "Run Now"</p>
                <p>ℹ️ Je mag "Apify-token" leeg laten. Alleen nodig voor de optionele betaalde methode ($49+/maand).</p>
            </div>
            
            <div class="apify-events-settings">
                <div class="apify-events-main">
                    <form id="apify-events-settings-form" method="post" action="options.php">
                        <?php
                        settings_fields('apify_events_settings');
                        do_settings_sections('apify_events_settings');
                        echo '<div id="apify-submit-wrap">';
                        submit_button();
                        echo '</div>';
                        ?>
                    </form>
                </div>
                
                <div class="apify-events-sidebar">
                    <div class="apify-events-run-now">
                        <h3><?php _e('Handmatige run', 'apify-events-to-posts'); ?></h3>
                        <p><?php _e('Start nu het event-ontdekkingsproces:', 'apify-events-to-posts'); ?></p>
                        <button type="button" id="apify-run-now" class="button button-primary">
                            <?php _e('Run Now', 'apify-events-to-posts'); ?>
                        </button>
                        <button type="button" id="apify-test-connection" class="button">
                            <?php _e('Test Connection', 'apify-events-to-posts'); ?>
                        </button>
                        <div id="apify-run-status" class="apify-run-status"></div>
                    </div>
                    
                    <div class="apify-events-logs">
                        <h3><?php _e('Laatste runlog', 'apify-events-to-posts'); ?></h3>
                        <?php $this->renderLastRunLog(); ?>
                    </div>
                    
                    <div class="apify-events-info">
                        <h3><?php _e('Plugininfo', 'apify-events-to-posts'); ?></h3>
                        <p><?php _e('Vindt komende events en slaat ze op als WordPress-conceptberichten.', 'apify-events-to-posts'); ?></p>
                        <ul style="margin: 8px 0 12px 18px; list-style: disc;">
                            <li><?php _e('Ontdek URL’s via SerpAPI of gebruik Handmatige URL’s (gratis).', 'apify-events-to-posts'); ?></li>
                            <li><?php _e('Haal datum, plaats en een korte beschrijving uit pagina’s.', 'apify-events-to-posts'); ?></li>
                            <li><?php _e('Voorkom duplicaten, sluit domeinen uit en beperk URL’s per domein.', 'apify-events-to-posts'); ?></li>
                            <li><?php _e('Start handmatig of volgens een wekelijks schema (instelbaar).', 'apify-events-to-posts'); ?></li>
                        </ul>
                        <p><strong><?php _e('Volgende geplande run:', 'apify-events-to-posts'); ?></strong><br>
                        <?php
                        $next_run = wp_next_scheduled('apify_events_weekly') ?: wp_next_scheduled('apify_events_monthly');
                        if ($next_run) {
                            echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run));
                            echo ' <small>(' . (wp_next_scheduled('apify_events_weekly') ? __('wekelijks', 'apify-events-to-posts') : __('maandelijks', 'apify-events-to-posts')) . ')</small>';
                        } else {
                            _e('Niet ingepland', 'apify-events-to-posts');
                        }
                        ?></p>
                        
                        <p><strong><?php _e('Laatste succesvolle run:', 'apify-events-to-posts'); ?></strong><br>
                        <?php
                        $last_run = get_option('apify_events_last_run', 0);
                        if ($last_run) {
                            echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_run));
                        } else {
                            _e('Nooit', 'apify-events-to-posts');
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
        echo '<div class="apify-events-page-header"><img src="' . esc_url($url) . '" alt="' . esc_attr__('Pluginkop', 'apify-events-to-posts') . '" /></div>';
    }

    private function renderFooterImage(): void
    {
        $url = $this->getPluginAssetUrl(self::FOOTER_IMAGE_RELATIVE_PATH);
        if (!$url) {
            return;
        }
        echo '<div class="apify-events-page-footer">';
        echo '<div class="apify-events-footer-actions">';
        echo '<button type="submit" form="apify-events-settings-form" class="button button-primary">' . esc_html__('Wijzigingen opslaan', 'apify-events-to-posts') . '</button>';
        echo '</div>';
        echo '<img src="' . esc_url($url) . '" alt="' . esc_attr__('Pluginvoettekst', 'apify-events-to-posts') . '" />';
        echo '</div>';
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
            echo '<div class="apify-logs empty">' . __('Geen loggegevens beschikbaar', 'apify-events-to-posts') . '</div>';
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
            $formatted_log .= 'Run op: ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $log_data['timestamp'], new \DateTimeZone($timezone_string)) . "\n";
        }
        
        if (isset($log_data['stats'])) {
            $stats = $log_data['stats'];
            $formatted_log .= "\nStatistieken:\n";
            $formatted_log .= "- Ontdekte URL’s: " . ($stats['discovered'] ?? 0) . "\n";
            $formatted_log .= "- Opgehaalde pagina’s: " . ($stats['fetched'] ?? 0) . "\n";
            $formatted_log .= "- Events herkend: " . ($stats['parsed'] ?? 0) . "\n";
            $formatted_log .= "- Posts geïmporteerd: " . ($stats['imported'] ?? 0) . "\n";
            $formatted_log .= "- Posts overgeslagen: " . ($stats['skipped'] ?? 0) . "\n";
            if (!empty($stats['sample_urls'])) {
                $formatted_log .= "\nVerwerkte URL’s (voorbeeld):\n";
                foreach ($stats['sample_urls'] as $u) {
                    if ($u) $formatted_log .= "- " . $u . "\n";
                }
            }
        }
        
        if (isset($log_data['errors']) && !empty($log_data['errors'])) {
            $formatted_log .= "\nFouten:\n";
            foreach ($log_data['errors'] as $error) {
                $formatted_log .= "- " . $error . "\n";
            }
        }
        
        if (isset($log_data['skipped_reasons']) && !empty($log_data['skipped_reasons'])) {
            $formatted_log .= "\nRedenen om over te slaan:\n";
            foreach ($log_data['skipped_reasons'] as $reason => $count) {
                $formatted_log .= "- " . $reason . ": " . $count . "\n";
            }
        }
        
        echo '<div class="apify-logs">' . esc_html($formatted_log) . '</div>';
    }
}
