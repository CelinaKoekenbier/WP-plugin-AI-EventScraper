<?php

namespace ApifyEvents;

/**
 * Main plugin orchestrator.
 *
 * Responsibilities:
 * - Acts as the singleton accessed from the bootstrap file.
 * - Registers admin menus, settings sections and asset loading.
 * - Hooks AJAX handlers for “Run now” / “Test connection”.
 * - Wires activation/deactivation and cron scheduling.
 *
 * See also:
 * - `Settings` for the form rendering and last-run log output.
 * - `Runner` for cron/manual execution logic.
 * - `Utils` for shared helpers (options, logging, hashing).
 */
class Plugin
{
    /**
     * Plugin instance
     */
    private static $instance = null;

    /**
     * Get plugin instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->initHooks();
    }

    /**
     * Initialize hooks
     */
    private function initHooks()
    {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'addAdminMenu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
            add_action('wp_ajax_apify_events_run_now', [$this, 'handleRunNow']);
            add_action('wp_ajax_apify_events_test', [$this, 'handleTest']);
            add_action('admin_notices', [$this, 'showAdminNotices']);
            
            // Add settings link on plugins page
            add_filter('plugin_action_links_' . plugin_basename(APIFY_EVENTS_PLUGIN_FILE), [$this, 'addPluginActionLinks']);
        }

        // Cron hooks — weekly run (target week = 4 weeks ahead)
        add_action('apify_events_weekly', [$this, 'runWeeklyEvent']);
        add_action('apify_events_monthly', [$this, 'runMonthlyEvent']); // legacy, kept for backward compat
        
        // Settings hooks
        add_action('admin_init', [$this, 'registerSettings']);
    }

    /**
     * Add admin menu
     */
    public function addAdminMenu()
    {
        // Top-level menu (sidebar)
        add_menu_page(
            __('Event Scraper DvdA', 'apify-events-to-posts'),
            __('Event Scraper DvdA', 'apify-events-to-posts'),
            'manage_options',
            'apify-events',
            [$this, 'renderSettingsPage'],
            'dashicons-calendar-alt',
            58
        );

        // Also keep the Settings → submenu entry for convenience
        add_options_page(
            __('Events naar Posts Scraper', 'apify-events-to-posts'),
            __('Event Scraper', 'apify-events-to-posts'),
            'manage_options',
            'apify-events',
            [$this, 'renderSettingsPage']
        );
    }
    
    /**
     * Add settings link on plugins page
     */
    public function addPluginActionLinks($links)
    {
        $settings_link = '<a href="' . admin_url('options-general.php?page=apify-events') . '">' . __('Instellingen', 'apify-events-to-posts') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueAdminScripts($hook)
    {
        if ($hook !== 'settings_page_apify-events' && $hook !== 'toplevel_page_apify-events') {
            return;
        }

        wp_enqueue_style(
            'apify-events-admin',
            APIFY_EVENTS_PLUGIN_URL . 'assets/admin.css',
            [],
            APIFY_EVENTS_VERSION
        );

        wp_enqueue_script(
            'apify-events-admin',
            APIFY_EVENTS_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            APIFY_EVENTS_VERSION,
            true
        );

        wp_localize_script('apify-events-admin', 'apifyEvents', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('apify_events_nonce'),
            'strings' => [
                'running' => __('Bezig...', 'apify-events-to-posts'),
                'success' => __('Gelukt!', 'apify-events-to-posts'),
                'error' => __('Er is een fout opgetreden', 'apify-events-to-posts'),
            ]
        ]);
    }

    /**
     * Register settings
     */
    public function registerSettings()
    {
        register_setting('apify_events_settings', 'apify_events_options', [
            'sanitize_callback' => [$this, 'sanitizeOptions']
        ]);

        // General settings section
        add_settings_section(
            'apify_events_general',
            __('Algemene instellingen', 'apify-events-to-posts'),
            [$this, 'renderGeneralSectionDescription'],
            'apify_events_settings'
        );

        // Manual URLs (place first for quick start)
        add_settings_field(
            'manual_urls',
            __('Handmatige URL’s (gratis)', 'apify-events-to-posts'),
            [$this, 'renderManualUrlsField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Apify Token
        add_settings_field(
            'apify_token',
            __('Apify-token', 'apify-events-to-posts'),
            [$this, 'renderTokenField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Queries
        add_settings_field(
            'queries',
            __('Zoekopdrachten', 'apify-events-to-posts'),
            [$this, 'renderQueriesField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Language Code
        add_settings_field(
            'language_code',
            __('Taalcode', 'apify-events-to-posts'),
            [$this, 'renderLanguageCodeField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Country Code
        add_settings_field(
            'country_code',
            __('Landcode', 'apify-events-to-posts'),
            [$this, 'renderCountryCodeField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Max results per query
        add_settings_field(
            'max_results_per_query',
            __('Max. resultaten per zoekopdracht', 'apify-events-to-posts'),
            [$this, 'renderMaxResultsField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Max URLs per domain (diversify sources)
        add_settings_field(
            'max_urls_per_domain',
            __('Max. URL’s per domein', 'apify-events-to-posts'),
            [$this, 'renderMaxUrlsPerDomainField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Excluded domains
        add_settings_field(
            'excluded_domains',
            __('Uitgesloten domeinen', 'apify-events-to-posts'),
            [$this, 'renderExcludedDomainsField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Image rules
        add_settings_field(
            'image_rules',
            __('Afbeeldingsregels', 'apify-events-to-posts'),
            [$this, 'renderImageRulesField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // SerpAPI Key (web search — 100 free/month)
        add_settings_field(
            'serpapi_api_key',
            __('SerpAPI-sleutel (webzoekopdrachten)', 'apify-events-to-posts'),
            [$this, 'renderSerpApiKeyField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // SerpAPI monthly budget cap
        add_settings_field(
            'serpapi_monthly_budget',
            __('SerpAPI maandbudget', 'apify-events-to-posts'),
            [$this, 'renderSerpApiMonthlyBudgetField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Test mode
        add_settings_field(
            'test_mode',
            __('Testmodus', 'apify-events-to-posts'),
            [$this, 'renderTestModeField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Restrict to target week
        add_settings_field(
            'restrict_to_target_week',
            __('Beperk tot doelweek', 'apify-events-to-posts'),
            [$this, 'renderRestrictToTargetWeekField'],
            'apify_events_settings',
            'apify_events_general'
        );

        // Weekly schedule (WP-Cron)
        add_settings_field(
            'weekly_schedule',
            __('Automatisch runschema', 'apify-events-to-posts'),
            [$this, 'renderWeeklyScheduleField'],
            'apify_events_settings',
            'apify_events_general'
        );
    }

    /**
     * Sanitize options
     */
    public function sanitizeOptions($input)
    {
        $sanitized = [];
        
        if (isset($input['apify_token'])) {
            $sanitized['apify_token'] = sanitize_text_field($input['apify_token']);
        }
        
        if (isset($input['serpapi_api_key'])) {
            $sanitized['serpapi_api_key'] = sanitize_text_field($input['serpapi_api_key']);
        }

        if (isset($input['serpapi_monthly_budget'])) {
            $sanitized['serpapi_monthly_budget'] = max(0, absint($input['serpapi_monthly_budget']));
        }

        if (isset($input['manual_urls'])) {
            $sanitized['manual_urls'] = sanitize_textarea_field($input['manual_urls']);
        }

        if (isset($input['queries'])) {
            // Preserve our placeholder tokens like <VOLGEND_MAAND_JAAR> which sanitize_textarea_field() would strip as HTML.
            $raw_queries = (string) $input['queries'];
            $placeholders = [
                '<VOLGEND_MAAND_JAAR>',
                '<VOLGENDE_MAAND_JAAR>',
                '<DEZE_MAAND_JAAR>',
                '<MAAND_DAARNA_JAAR>',
                '<TARGET_WEEK>',
            ];
            $sentinels = [];
            foreach ($placeholders as $i => $ph) {
                $sentinels[$ph] = "___APIFY_PH_{$i}___";
            }
            $protected = strtr($raw_queries, $sentinels);
            $clean = sanitize_textarea_field($protected);
            $sanitized['queries'] = strtr($clean, array_flip($sentinels));
        }
        
        if (isset($input['language_code'])) {
            $sanitized['language_code'] = sanitize_text_field($input['language_code']);
        }
        
        if (isset($input['country_code'])) {
            $sanitized['country_code'] = sanitize_text_field($input['country_code']);
        }
        
        if (isset($input['max_results_per_query'])) {
            $sanitized['max_results_per_query'] = absint($input['max_results_per_query']);
        }
        
        if (isset($input['max_urls_per_domain'])) {
            $sanitized['max_urls_per_domain'] = max(1, absint($input['max_urls_per_domain']));
        }
        
        if (isset($input['excluded_domains'])) {
            $sanitized['excluded_domains'] = sanitize_textarea_field($input['excluded_domains']);
        }
        
        if (isset($input['min_image_width'])) {
            $sanitized['min_image_width'] = absint($input['min_image_width']);
        }
        
        if (isset($input['min_image_height'])) {
            $sanitized['min_image_height'] = absint($input['min_image_height']);
        }
        
        if (isset($input['test_mode'])) {
            $sanitized['test_mode'] = (bool) $input['test_mode'];
        }
        
        if (isset($input['restrict_to_target_week'])) {
            $sanitized['restrict_to_target_week'] = (bool) $input['restrict_to_target_week'];
        }

        if (isset($input['weekly_day'])) {
            $day = absint($input['weekly_day']);
            $sanitized['weekly_day'] = ($day >= 1 && $day <= 7) ? $day : 1;
        }

        if (isset($input['weekly_time'])) {
            $t = sanitize_text_field($input['weekly_time']);
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t)) {
                $sanitized['weekly_time'] = $t;
            } else {
                $sanitized['weekly_time'] = '09:00';
            }
        }

        // Re-schedule weekly cron when settings are saved
        $merged = array_merge(Utils::getOptions(), $sanitized);
        self::rescheduleWeeklyCron(
            isset($merged['weekly_day']) ? (int) $merged['weekly_day'] : 1,
            isset($merged['weekly_time']) ? (string) $merged['weekly_time'] : '09:00'
        );
        
        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage()
    {
        $settings = new Settings();
        $settings->render();
    }

    /**
     * General section description (timezone hint for "Last modified" on posts).
     */
    public function renderGeneralSectionDescription()
    {
        echo '<p class="description">';
        echo esc_html__('Als "Laatste wijziging" op geïmporteerde posts 1 uur achter loopt op je lokale tijd, zet dan bij Instellingen → Algemeen → Tijdzone de optie op "Amsterdam" (Europa/Amsterdam).', 'apify-events-to-posts');
        echo '</p>';
    }

    /**
     * Render token field
     */
    public function renderTokenField()
    {
        $options = get_option('apify_events_options', []);
        $token = $options['apify_token'] ?? '';
        $masked_token = $token ? substr($token, 0, 8) . str_repeat('*', max(0, strlen($token) - 8)) : '';
        
        echo '<input type="password" name="apify_events_options[apify_token]" value="' . esc_attr($token) . '" class="regular-text" />';
        echo '<p class="description" style="color: #999;">' . __('⚠️ OPTIONEEL - Alleen nodig voor de betaalde Apify-methode ($49+/maand). Laat leeg om gratis methodes te gebruiken.', 'apify-events-to-posts') . '</p>';
        echo '<p class="description apify-accent-text"><strong>✅ Gebruik hieronder "Handmatige URL’s" in plaats daarvan (volledig gratis, geen token nodig)</strong></p>';
        if ($masked_token) {
            echo '<p class="description">' . sprintf(__('Huidige token: %s', 'apify-events-to-posts'), esc_html($masked_token)) . '</p>';
        }
    }

    /**
     * Render queries field
     */
    public function renderQueriesField()
    {
        $options = get_option('apify_events_options', []);
        $queries = $options['queries'] ?? implode("\n", [
            'site:.nl evenement <VOLGEND_MAAND_JAAR> natuur',
            'site:.nl agenda <VOLGEND_MAAND_JAAR> duurzaamheid',
            'site:.nl <VOLGEND_MAAND_JAAR> biodiversiteit',
            'site:.nl <VOLGEND_MAAND_JAAR> "biologisch" evenement',
            'site:.nl <VOLGEND_MAAND_JAAR> planten evenement'
        ]);
        
        echo '<textarea name="apify_events_options[queries]" rows="5" cols="50" class="large-text">' . esc_textarea($queries) . '</textarea>';
        echo '<p class="description">' . __('Eén zoekopdracht per regel. Placeholders: <code>&lt;VOLGEND_MAAND_JAAR&gt;</code> = volgende maand/jaar, <code>&lt;TARGET_WEEK&gt;</code> = doelweek (bijv. 6-12 april 2026, 4 weken vooruit).', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render language code field
     */
    public function renderLanguageCodeField()
    {
        $options = get_option('apify_events_options', []);
        $language_code = $options['language_code'] ?? 'nl';
        
        echo '<input type="text" name="apify_events_options[language_code]" value="' . esc_attr($language_code) . '" class="small-text" />';
        echo '<p class="description">' . __('Taalcode voor zoekresultaten (bijv. nl, en).', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render country code field
     */
    public function renderCountryCodeField()
    {
        $options = get_option('apify_events_options', []);
        $country_code = $options['country_code'] ?? 'nl';
        
        echo '<input type="text" name="apify_events_options[country_code]" value="' . esc_attr($country_code) . '" class="small-text" />';
        echo '<p class="description">' . __('Landcode voor zoekresultaten (bijv. nl, us).', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render max results field
     */
    public function renderMaxResultsField()
    {
        $options = get_option('apify_events_options', []);
        $max_results = $options['max_results_per_query'] ?? 20;
        
        echo '<input type="number" name="apify_events_options[max_results_per_query]" value="' . esc_attr($max_results) . '" class="small-text" min="1" max="100" />';
        echo '<p class="description">' . __('Maximale resultaten per zoekopdracht.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render max URLs per domain field
     */
    public function renderMaxUrlsPerDomainField()
    {
        $options = get_option('apify_events_options', []);
        $max = $options['max_urls_per_domain'] ?? 10;
        echo '<input type="number" name="apify_events_options[max_urls_per_domain]" value="' . esc_attr($max) . '" class="small-text" min="1" max="50" />';
        echo '<p class="description">' . __('Beperk URL’s per domein zodat één site (bijv. groeneagenda.nl) niet domineert. Handmatige URL’s worden altijd meegenomen.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render excluded domains field
     */
    public function renderExcludedDomainsField()
    {
        $options = get_option('apify_events_options', []);
        $excluded_domains = $options['excluded_domains'] ?? '';
        
        echo '<textarea name="apify_events_options[excluded_domains]" rows="3" cols="50" class="large-text">' . esc_textarea($excluded_domains) . '</textarea>';
        echo '<p class="description">' . __('Komma-gescheiden lijst van domeinen om uit de resultaten te negeren.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render image rules field
     */
    public function renderImageRulesField()
    {
        $options = get_option('apify_events_options', []);
        $min_width = $options['min_image_width'] ?? 300;
        $min_height = $options['min_image_height'] ?? 200;
        
        echo '<label>' . __('Min. breedte:', 'apify-events-to-posts') . ' <input type="number" name="apify_events_options[min_image_width]" value="' . esc_attr($min_width) . '" class="small-text" min="100" /></label> ';
        echo '<label>' . __('Min. hoogte:', 'apify-events-to-posts') . ' <input type="number" name="apify_events_options[min_image_height]" value="' . esc_attr($min_height) . '" class="small-text" min="100" /></label>';
        echo '<p class="description">' . __('Minimale afbeeldingsafmetingen voor uitgelichte afbeeldingen.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render SerpAPI key field
     */
    public function renderSerpApiKeyField()
    {
        $options = get_option('apify_events_options', []);
        $key = $options['serpapi_api_key'] ?? '';
        echo '<input type="text" name="apify_events_options[serpapi_api_key]" value="' . esc_attr($key) . '" class="regular-text" />';
        echo '<p class="description">' . __('SerpAPI-sleutel voor webzoekopdrachten (Google-resultaten via SerpAPI). <a href="https://serpapi.com/users/sign_up" target="_blank">Registreren</a> — 100 gratis zoekopdrachten/maand.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render SerpAPI monthly budget field
     */
    public function renderSerpApiMonthlyBudgetField()
    {
        $options = Utils::getOptions();
        $budget = (int) ($options['serpapi_monthly_budget'] ?? 200);
        $used = Utils::getSerpApiUsedThisMonth();
        $remaining = Utils::getSerpApiRemainingThisMonth();

        echo '<input type="number" name="apify_events_options[serpapi_monthly_budget]" value="' . esc_attr($budget) . '" class="small-text" min="0" max="100000" /> ';

        echo '<span class="description">' . sprintf(
            esc_html__('Deze maand gebruikt: %d. Resterend: %d.', 'apify-events-to-posts'),
            (int) $used,
            (int) $remaining
        ) . '</span>';

        echo '<p class="description">' . esc_html__('0 schakelt SerpAPI uit (alleen Handmatige URL’s). Standaard budget: 200.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render manual URLs field
     */
    public function renderManualUrlsField()
    {
        $options = get_option('apify_events_options', []);
        $manual_urls = $options['manual_urls'] ?? '';
        
        echo '<div class="apify-start-here">';
        echo '<strong>🎯 START HIER - Makkelijkste methode!</strong><br>';
        echo esc_html__('Voeg hieronder event-URL’s toe. Geen API-tokens nodig, werkt meteen!', 'apify-events-to-posts');
        echo '</div>';
        
        echo '<textarea name="apify_events_options[manual_urls]" rows="8" cols="50" class="large-text" placeholder="https://www.natuurmonumenten.nl/agenda&#10;https://www.ivn.nl/agenda&#10;https://www.staatsbosbeheer.nl/evenementen">' . esc_textarea($manual_urls) . '</textarea>';
        echo '<p class="description">' . __('✅ Eén URL per regel. Als leeg, gebruikt de plugin standaard agenda-URL’s (natuurmonumenten, ivn, staatsbosbeheer).', 'apify-events-to-posts') . '</p>';
        echo '<p class="description"><strong>' . __('Voorbeeld-URL’s om te proberen:', 'apify-events-to-posts') . '</strong><br>';
        echo 'https://www.natuurmonumenten.nl/agenda<br>';
        echo 'https://www.ivn.nl/agenda<br>';
        echo 'https://www.staatsbosbeheer.nl/evenementen</p>';
    }

    /**
     * Render test mode field
     */
    public function renderTestModeField()
    {
        $options = get_option('apify_events_options', []);
        $test_mode = $options['test_mode'] ?? false;
        
        echo '<label><input type="checkbox" name="apify_events_options[test_mode]" value="1" ' . checked($test_mode, true, false) . ' /> ' . __('Testmodus inschakelen (posts niet opslaan)', 'apify-events-to-posts') . '</label>';
        echo '<p class="description">' . __('Als uitgeschakeld, worden gevonden events opgeslagen in posts maar niet gepubliceerd. Je moet ze handmatig publiceren.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render restrict to target week field
     */
    public function renderRestrictToTargetWeekField()
    {
        $options = get_option('apify_events_options', []);
        $restrict = $options['restrict_to_target_week'] ?? false;
        echo '<label><input type="checkbox" name="apify_events_options[restrict_to_target_week]" value="1" ' . checked($restrict, true, false) . ' /> ';
        echo __('Importeer alleen events uit de doelweek (4 weken vooruit)', 'apify-events-to-posts') . '</label>';
        echo '<p class="description">' . __('Als uitgeschakeld importeert de plugin alle geldige events (toekomstige data). Zet dit aan om te beperken tot de specifieke doelweek.', 'apify-events-to-posts') . '</p>';
    }

    /**
     * Render weekly schedule fields (day + time)
     */
    public function renderWeeklyScheduleField()
    {
        $options = Utils::getOptions();
        $day = (int) ($options['weekly_day'] ?? 1);
        if ($day < 1 || $day > 7) {
            $day = 1;
        }
        $time = (string) ($options['weekly_time'] ?? '09:00');
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            $time = '09:00';
        }

        $days = [
            1 => __('Maandag', 'apify-events-to-posts'),
            2 => __('Dinsdag', 'apify-events-to-posts'),
            3 => __('Woensdag', 'apify-events-to-posts'),
            4 => __('Donderdag', 'apify-events-to-posts'),
            5 => __('Vrijdag', 'apify-events-to-posts'),
            6 => __('Zaterdag', 'apify-events-to-posts'),
            7 => __('Zondag', 'apify-events-to-posts'),
        ];

        echo '<label>' . esc_html__('Dag', 'apify-events-to-posts') . ' ';
        echo '<select name="apify_events_options[weekly_day]">';
        foreach ($days as $k => $label) {
            echo '<option value="' . esc_attr($k) . '" ' . selected($day, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> ';

        echo '<label style="margin-left:12px;">' . esc_html__('Tijd (Europe/Amsterdam)', 'apify-events-to-posts') . ' ';
        echo '<input type="time" name="apify_events_options[weekly_time]" value="' . esc_attr($time) . '" /></label>';

        echo '<p class="description">' . esc_html__(
            'Bepaalt het tijdstip van de wekelijkse WP-Cron-run. Let op: WP-Cron start bij bezoek aan de site; als er geen bezoek is op dat exacte moment, draait hij bij het eerstvolgende bezoek.',
            'apify-events-to-posts'
        ) . '</p>';
    }

    /**
     * Handle AJAX run now request
     */
    public function handleRunNow()
    {
        // Log that the AJAX handler was called
        error_log('Apify Events: AJAX handler called');
        
        try {
            error_log('Apify Events: Checking nonce');
            check_ajax_referer('apify_events_nonce', 'nonce');
            
            error_log('Apify Events: Checking permissions');
            if (!current_user_can('manage_options')) {
                wp_die(__('Onvoldoende rechten', 'apify-events-to-posts'));
            }

            error_log('Apify Events: Creating Runner instance');
            $runner = new Runner();
            
            error_log('Apify Events: Starting run');
            $result = $runner->run();
            
            error_log('Apify Events: Run completed, sending response');
            wp_send_json($result);
            
        } catch (\Exception $e) {
            error_log('Apify Events: Exception caught: ' . $e->getMessage());
            error_log('Apify Events: Exception file: ' . $e->getFile() . ' line: ' . $e->getLine());
            Utils::log('AJAX run failed: ' . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => 'Run mislukt: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        } catch (\Error $e) {
            error_log('Apify Events: Fatal error caught: ' . $e->getMessage());
            error_log('Apify Events: Error file: ' . $e->getFile() . ' line: ' . $e->getLine());
            Utils::log('AJAX run fatal error: ' . $e->getMessage(), 'error');
            wp_send_json_error([
                'message' => 'Fatale fout: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Handle AJAX test request — test SerpAPI
     */
    public function handleTest()
    {
        check_ajax_referer('apify_events_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Onvoldoende rechten']);
        }
        $client = new FreeSearchClient();
        $result = $client->testSerpApi();
        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        }
        wp_send_json_error(['message' => $result['message']]);
    }

    /**
     * Show admin notices
     */
    public function showAdminNotices()
    {
        $last_run = get_option('apify_events_last_run', 0);
        $days_since_last_run = (time() - $last_run) / DAY_IN_SECONDS;
        
        if ($days_since_last_run > 45) {
            echo '<div class="notice notice-warning"><p>';
            echo sprintf(
                __('Event Scraper: geen succesvolle run in de laatste %d dagen. <a href="%s">Bekijk instellingen</a> of <a href="%s">run nu</a>.', 'apify-events-to-posts'),
                round($days_since_last_run),
                admin_url('options-general.php?page=apify-events'),
                admin_url('options-general.php?page=apify-events')
            );
            echo '</p></div>';
        }
    }

    /**
     * Run weekly event (target = week 4 weeks ahead)
     */
    public function runWeeklyEvent()
    {
        $runner = new Runner();
        $runner->run();
    }

    /**
     * Run monthly event (legacy)
     */
    public function runMonthlyEvent()
    {
        $runner = new Runner();
        $runner->run();
    }

    /**
     * Plugin activation
     */
    public static function activate()
    {
        // Create category if it doesn't exist
        $category = get_category_by_slug('evenementen');
        if (!$category) {
            wp_create_category('Evenementen');
        }

        $options = Utils::getOptions();
        $day = isset($options['weekly_day']) ? (int) $options['weekly_day'] : 1;
        $time = isset($options['weekly_time']) ? (string) $options['weekly_time'] : '09:00';

        // Schedule weekly cron (configurable — target week = 4 weeks ahead)
        if (!wp_next_scheduled('apify_events_weekly')) {
            $next_run = self::getNextWeeklyRunTime($day, $time);
            wp_schedule_event($next_run, 'weekly', 'apify_events_weekly');
        }
        // Legacy: keep monthly if already set (do not add new)
        if (!wp_next_scheduled('apify_events_monthly')) {
            $next_run = self::getNextMonthlyRunTime();
            wp_schedule_event($next_run, 'monthly', 'apify_events_monthly');
        }

        // Set default options
        $default_options = [
            'apify_token' => '',
            'queries' => implode("\n", [
                'site:.nl evenement <VOLGEND_MAAND_JAAR> natuur',
                'site:.nl agenda <VOLGEND_MAAND_JAAR> duurzaamheid',
                'site:.nl <VOLGEND_MAAND_JAAR> biodiversiteit',
                'site:.nl <VOLGEND_MAAND_JAAR> "biologisch" evenement',
                'site:.nl <VOLGEND_MAAND_JAAR> planten evenement'
            ]),
            'language_code' => 'nl',
            'country_code' => 'nl',
            'max_results_per_query' => 20,
            'max_urls_per_domain' => 10,
            'excluded_domains' => '',
            'min_image_width' => 300,
            'min_image_height' => 200,
            'test_mode' => false,
            'weekly_day' => 1,
            'weekly_time' => '09:00',
        ];

        add_option('apify_events_options', $default_options);
    }

    /**
     * Plugin deactivation
     */
    public static function deactivate()
    {
        wp_clear_scheduled_hook('apify_events_weekly');
        wp_clear_scheduled_hook('apify_events_monthly');
    }

    /**
     * Next weekly run time in Europe/Amsterdam.
     *
     * @param int    $day  1=Mon .. 7=Sun
     * @param string $time HH:MM
     */
    private static function getNextWeeklyRunTime($day = 1, $time = '09:00')
    {
        $tz = new \DateTimeZone('Europe/Amsterdam');
        $now = new \DateTime('now', $tz);

        $day = (int) $day;
        if ($day < 1 || $day > 7) {
            $day = 1;
        }

        $hour = 9;
        $minute = 0;
        if (is_string($time) && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
        }

        $next = (clone $now)->setTime($hour, $minute, 0);
        $todayDow = (int) $now->format('N'); // 1=Mon .. 7=Sun

        // If it's the selected day and we haven't reached the time yet, schedule today.
        if ($todayDow === $day && $now->getTimestamp() < $next->getTimestamp()) {
            return $next->getTimestamp();
        }

        // Otherwise schedule the next occurrence of the selected weekday.
        $delta = ($day - $todayDow + 7) % 7;
        if ($delta === 0) {
            $delta = 7;
        }
        $next->modify("+{$delta} days");
        return $next->getTimestamp();
    }

    /**
     * Clear and re-schedule the weekly cron hook.
     */
    private static function rescheduleWeeklyCron($day, $time)
    {
        // Avoid scheduling before WP cron is ready
        if (!function_exists('wp_schedule_event')) {
            return;
        }

        wp_clear_scheduled_hook('apify_events_weekly');
        $next = self::getNextWeeklyRunTime($day, $time);
        wp_schedule_event($next, 'weekly', 'apify_events_weekly');
    }

    /**
     * Get next monthly run time (15th at 15:00 Europe/Amsterdam)
     */
    private static function getNextMonthlyRunTime()
    {
        $timezone = new \DateTimeZone('Europe/Amsterdam');
        $now = new \DateTime('now', $timezone);
        
        // If we're past the 15th, schedule for next month
        if ($now->format('j') > 15) {
            $next_run = $now->modify('first day of next month')->setTime(15, 0);
        } else {
            $next_run = $now->setDate($now->format('Y'), $now->format('n'), 15)->setTime(15, 0);
        }
        
        return $next_run->getTimestamp();
    }
}
