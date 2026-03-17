<?php

namespace ApifyEvents;

/**
 * Utility functions.
 *
 * Houses constants and helper methods shared across the plugin:
 * - Dutch date/month parsing utilities (`parseDutchDate`, `getNextMonthString`).
 * - Option getters/setters and logging helpers (`getOptions`, `log`).
 * - Hashing, canonical URL building and duplicate detection glue.
 * - Misc helpers for taxonomy setup, admin notices, cron scheduling.
 *
 * Almost every class depends on `Utils`, so changes here ripple widely.
 */
class Utils
{
    /**
     * Dutch month names
     */
    private static $dutch_months = [
        1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
        5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
        9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december'
    ];

    /**
     * Dutch month names (alternative forms)
     */
    private static $dutch_months_alt = [
        'jan', 'feb', 'mrt', 'apr', 'mei', 'jun',
        'jul', 'aug', 'sep', 'okt', 'nov', 'dec'
    ];

    /**
     * Dutch cities/regions for validation
     */
    private static $dutch_locations = [
        'amsterdam', 'rotterdam', 'den haag', 'utrecht', 'eindhoven', 'tilburg',
        'groningen', 'almere', 'breda', 'nijmegen', 'enschede', 'haarlem',
        'arnhem', 'zaanstad', 'amersfoort', 'apeldoorn', 'hoofddorp', 'maastricht',
        'leiden', 'dordrecht', 'ede', 'westland', 'delft', 'venlo',
        'deventer', 'zwolle', 'zoetermeer', 'hague', 'haarlem', 'amersfoort',
        'nederland', 'holland', 'friesland', 'gelderland', 'noord-holland',
        'zuid-holland', 'utrecht', 'noord-brabant', 'limburg', 'overijssel',
        'drenthe', 'flevoland', 'zeeland', 'groningen'
    ];

    /**
     * Get next month string in Dutch
     */
    public static function getNextMonthString()
    {
        $timezone = new \DateTimeZone('Europe/Amsterdam');
        $now = new \DateTime('now', $timezone);
        $next_month = $now->modify('first day of next month');
        
        $month_num = (int) $next_month->format('n');
        $year = $next_month->format('Y');
        
        return self::$dutch_months[$month_num] . ' ' . $year;
    }

    /**
     * Get the target week (the week that starts 4 weeks from today).
     * Used for weekly runs: run on March 9 → target week April 6–12.
     *
     * @return array{0: int, 1: int} [start_timestamp, end_timestamp] (Monday 00:00 to Sunday 23:59:59)
     */
    public static function getTargetWeekRange()
    {
        $tz = new \DateTimeZone('Europe/Amsterdam');
        $now = new \DateTime('now', $tz);
        $inFourWeeks = (clone $now)->modify('+28 days');
        // Monday of that week (ISO week starts Monday)
        $dayOfWeek = (int) $inFourWeeks->format('N'); // 1=Mon .. 7=Sun
        $daysToMonday = $dayOfWeek - 1;
        $monday = (clone $inFourWeeks)->modify("-{$daysToMonday} days")->setTime(0, 0, 0);
        $sunday = (clone $monday)->modify('+6 days')->setTime(23, 59, 59);
        return [$monday->getTimestamp(), $sunday->getTimestamp()];
    }

    /**
     * Get target week string in Dutch for search queries (e.g. "6-12 april 2026")
     */
    public static function getTargetWeekString()
    {
        list($start, $end) = self::getTargetWeekRange();
        $tz = new \DateTimeZone('Europe/Amsterdam');
        $d1 = (new \DateTime('@' . $start))->setTimezone($tz);
        $d2 = (new \DateTime('@' . $end))->setTimezone($tz);
        $day1 = (int) $d1->format('j');
        $day2 = (int) $d2->format('j');
        $month = self::$dutch_months[(int) $d1->format('n')];
        $year = $d1->format('Y');
        if ($day1 === $day2) {
            return "{$day1} {$month} {$year}";
        }
        return "{$day1}-{$day2} {$month} {$year}";
    }

    /**
     * Parse Dutch date string to timestamp
     */
    public static function parseDutchDate($date_string, $timezone = 'Europe/Amsterdam')
    {
        if (empty($date_string)) {
            return null;
        }

        $tz = new \DateTimeZone($timezone);
        $date_string = trim(strtolower($date_string));

        // Try IntlDateFormatter first
        if (class_exists('IntlDateFormatter')) {
            $formatter = new \IntlDateFormatter('nl_NL', \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
            $timestamp = $formatter->parse($date_string);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        // Manual parsing patterns
        $patterns = [
            // dd maand yyyy
            '/(\d{1,2})\s+(' . implode('|', self::$dutch_months) . ')\s+(\d{4})/',
            // d maand yyyy
            '/(\d{1,2})\s+(' . implode('|', self::$dutch_months_alt) . ')\s+(\d{4})/',
            // dd-mm-yyyy
            '/(\d{1,2})-(\d{1,2})-(\d{4})/',
            // dd/mm/yyyy
            '/(\d{1,2})\/(\d{1,2})\/(\d{4})/',
            // yyyy-mm-dd (ISO)
            '/(\d{4})-(\d{1,2})-(\d{1,2})/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $date_string, $matches)) {
                try {
                    if (strpos($pattern, 'maand') !== false) {
                        // Dutch month name
                        $month_name = $matches[2];
                        $month_num = array_search($month_name, self::$dutch_months);
                        if (!$month_num) {
                            $month_num = array_search($month_name, self::$dutch_months_alt);
                        }
                        if ($month_num) {
                            $date = new \DateTime("{$matches[3]}-{$month_num}-{$matches[1]}", $tz);
                            return $date->getTimestamp();
                        }
                    } else {
                        // Numeric date
                        if (strpos($pattern, 'yyyy-mm-dd') !== false) {
                            $date = new \DateTime("{$matches[1]}-{$matches[2]}-{$matches[3]}", $tz);
                        } else {
                            $date = new \DateTime("{$matches[3]}-{$matches[2]}-{$matches[1]}", $tz);
                        }
                        return $date->getTimestamp();
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Parse time string to HH:MM format
     */
    public static function parseTime($time_string)
    {
        if (empty($time_string)) {
            return null;
        }

        $time_string = trim($time_string);
        
        // HH:MM format
        if (preg_match('/(\d{1,2}):(\d{2})/', $time_string, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            
            if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                return sprintf('%02d:%02d', $hour, $minute);
            }
        }

        return null;
    }

    /**
     * Check if location is in Netherlands
     */
    public static function isDutchLocation($location)
    {
        if (empty($location)) {
            return false;
        }

        $location = strtolower(trim($location));
        
        // Check .nl TLD
        if (strpos($location, '.nl') !== false) {
            return true;
        }

        // Check Dutch cities/regions
        foreach (self::$dutch_locations as $dutch_location) {
            if (strpos($location, $dutch_location) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate SHA-1 hash for deduplication
     */
    public static function generateHash($url)
    {
        return sha1($url);
    }

    /**
     * Normalize title for deduplication
     */
    public static function normalizeTitle($title)
    {
        if (empty($title)) {
            return '';
        }

        // Remove extra whitespace and convert to lowercase
        $normalized = preg_replace('/\s+/', ' ', trim($title));
        $normalized = strtolower($normalized);
        
        // Remove common words that might vary
        $common_words = ['het', 'de', 'een', 'van', 'op', 'in', 'voor', 'met', 'aan', 'door'];
        $words = explode(' ', $normalized);
        $words = array_filter($words, function($word) use ($common_words) {
            return !in_array($word, $common_words) && strlen($word) > 2;
        });
        
        return implode(' ', $words);
    }

    /**
     * Log message
     */
    /**
     * Session debug log (for current run only)
     */
    private static $debug_log = [];
    
    public static function log($message, $level = 'info')
    {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Write to WordPress debug log if enabled
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log($log_entry);
        }
        
        // Store debug entries for current run
        if ($level === 'debug') {
            self::$debug_log[] = $message;
        }
        
        return $log_entry;
    }
    
    public static function getDebugLog()
    {
        return self::$debug_log;
    }
    
    public static function clearDebugLog()
    {
        self::$debug_log = [];
    }

    /**
     * Save run log
     */
    public static function saveRunLog($stats, $errors = [], $skipped_reasons = [])
    {
        $log_data = [
            'timestamp' => time(),
            'stats' => $stats,
            'errors' => $errors,
            'skipped_reasons' => $skipped_reasons,
        ];
        
        update_option('apify_events_last_run_log', json_encode($log_data, JSON_PRETTY_PRINT));
        
        // Update last successful run timestamp if we imported posts
        if (isset($stats['imported']) && $stats['imported'] > 0) {
            update_option('apify_events_last_run', time());
        }
    }

    /**
     * Get excluded domains array
     */
    public static function getExcludedDomains()
    {
        $options = get_option('apify_events_options', []);
        $excluded_domains = $options['excluded_domains'] ?? '';
        
        if (empty($excluded_domains)) {
            return [];
        }
        
        $domains = array_map('trim', explode(',', $excluded_domains));
        return array_filter($domains);
    }

    /**
     * Check if URL should be excluded
     */
    public static function shouldExcludeUrl($url)
    {
        $excluded_domains = self::getExcludedDomains();
        
        if (empty($excluded_domains)) {
            return false;
        }
        
        $parsed_url = parse_url($url);
        if (!$parsed_url || !isset($parsed_url['host'])) {
            return false;
        }
        
        $host = strtolower($parsed_url['host']);
        
        foreach ($excluded_domains as $excluded_domain) {
            $excluded_domain = strtolower(trim($excluded_domain));
            if (strpos($host, $excluded_domain) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get image rules
     */
    public static function getImageRules()
    {
        $options = get_option('apify_events_options', []);
        
        return [
            'min_width' => $options['min_image_width'] ?? 300,
            'min_height' => $options['min_image_height'] ?? 200,
        ];
    }

    /**
     * Check if image meets requirements
     */
    public static function isValidImage($image_url, $width = null, $height = null)
    {
        $rules = self::getImageRules();
        
        if ($width && $width < $rules['min_width']) {
            return false;
        }
        
        if ($height && $height < $rules['min_height']) {
            return false;
        }
        
        // Check if it's a valid image URL
        $parsed_url = parse_url($image_url);
        if (!$parsed_url || !isset($parsed_url['scheme'])) {
            return false;
        }
        
        // Check file extension
        $path = $parsed_url['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        return in_array($extension, $valid_extensions);
    }

    /**
     * Generate canonical URL
     */
    public static function getCanonicalUrl($url)
    {
        $parsed = parse_url($url);
        if (!$parsed) {
            return $url;
        }
        
        // Remove common tracking parameters
        $tracking_params = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid', 'ref', 'source', 'campaign'
        ];
        
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query_params);
            foreach ($tracking_params as $param) {
                unset($query_params[$param]);
            }
            $parsed['query'] = http_build_query($query_params);
        }
        
        // Remove fragment
        unset($parsed['fragment']);
        
        return self::buildUrl($parsed);
    }

    /**
     * Build URL from parsed components
     */
    private static function buildUrl($parsed)
    {
        $url = '';
        
        if (isset($parsed['scheme'])) {
            $url .= $parsed['scheme'] . '://';
        }
        
        if (isset($parsed['host'])) {
            $url .= $parsed['host'];
        }
        
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        
        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }
        
        if (isset($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }
        
        return $url;
    }

    /**
     * Check if we're in test mode
     */
    public static function isTestMode()
    {
        $options = get_option('apify_events_options', []);
        return !empty($options['test_mode']);
    }

    /**
     * Get plugin options
     */
    public static function getOptions()
    {
        $defaults = [
            'apify_token' => '',
            'serpapi_api_key' => '',
            'queries' => implode("\n", [
                'site:natuurmonumenten.nl "activiteit" -filetype:pdf',
                'site:ivn.nl "activiteit" -filetype:pdf',
                'site:landschapnoordholland.nl "agenda" -filetype:pdf',
                'site:staatsbosbeheer.nl "evenement" -filetype:pdf',
                'site:natuurmuseum.nl "workshop" -filetype:pdf'
            ]),
            'manual_urls' => '',
            'language_code' => 'nl',
            'country_code' => 'nl',
            'max_results_per_query' => 20,
            'max_urls_per_domain' => 10,
            'excluded_domains' => '',
            'min_image_width' => 300,
            'min_image_height' => 200,
            'test_mode' => false,
            'use_free_method' => true,
            'restrict_to_target_week' => false,
        ];
        
        $options = get_option('apify_events_options', []);
        return array_merge($defaults, $options);
    }
}
