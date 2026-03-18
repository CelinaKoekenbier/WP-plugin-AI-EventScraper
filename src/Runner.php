<?php

namespace ApifyEvents;

/**
 * Event discovery runner.
 *
 * Coordinates the end-to-end workflow for both manual runs and cron jobs:
 *  1. Gather candidate URLs (SerpAPI web search, manual URLs, RSS fallbacks).
 *  2. Scrape pages (directly or via Apify) and extract potential events.
 *  3. Deduplicate, validate, import posts, and record detailed statistics.
 *
 * Public entry points:
 * - `run()` – main method invoked by AJAX/manual trigger.
 * - `runFreeMethod()` – free stack (manual URLs + Custom Search).
 * - `runApifyMethod()` – optional Apify actor workflow.
 *
 * Uses helper classes:
 * - `FreeSearchClient` / `ApifyClient` for fetching data sources.
 * - `Extractor` for JSON-LD & heuristic parsing.
 * - `Importer` for post/media/taxonomy creation.
 * - `Utils` for logging, options, and hash helpers.
 */
class Runner
{
    /**
     * Run the event discovery process
     */
    public function run()
    {
        $start_time = time();
        $stats = [
            'discovered' => 0,
            'fetched' => 0,
            'parsed' => 0,
            'imported' => 0,
            'skipped' => 0,
        ];
        
        $errors = [];
        $skipped_reasons = [];
        
        try {
            // Clear debug log from previous run
            Utils::clearDebugLog();
            
            Utils::log('Starten met event-ontdekking (Apify Events)', 'info');
            
            // Get options
            $options = Utils::getOptions();
            
            // Check if we should use free method
            if ($options['use_free_method'] ?? true) {
                Utils::log('Using free search method', 'info');
                $this->runFreeMethod($options, $stats, $errors, $skipped_reasons);
            } else {
                Utils::log('Using Apify method', 'info');
                $this->runApifyMethod($options, $stats, $errors, $skipped_reasons);
            }
            
            $run_time = time() - $start_time;
            Utils::log("Run afgerond in {$run_time} seconden", 'info');
            
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
            Utils::log('Run mislukt: ' . $e->getMessage(), 'error');
        }
        
        // Save run log
        Utils::saveRunLog($stats, $errors, $skipped_reasons);
        
        return [
            'success' => empty($errors),
            'stats' => $stats,
            'errors' => $errors,
            'skipped_reasons' => $skipped_reasons,
            'log' => $this->formatLog($stats, $errors, $skipped_reasons),
        ];
    }

    /**
     * Prepare search queries by replacing placeholders.
     *
     * Month placeholders (Dutch):
     * - <DEZE_MAAND_JAAR> = this month (e.g. "maart 2026")
     * - <VOLGEND_MAAND_JAAR> = next month (e.g. "april 2026") (backward compatible)
     * - <VOLGENDE_MAAND_JAAR> = next month (alias)
     * - <MAAND_DAARNA_JAAR> = month after next (e.g. "mei 2026")
     *
     * Week placeholder:
     * - <TARGET_WEEK> = week 4 weeks ahead (e.g. "6-12 april 2026") for weekly runs
     */
    private function prepareQueries($queries_text, $next_month, $target_week = null)
    {
        if ($target_week === null) {
            $target_week = Utils::getTargetWeekString();
        }
        $queries = array_filter(array_map('trim', explode("\n", $queries_text)));
        $prepared_queries = [];

        $this_month = Utils::getThisMonthString();
        $next_month2 = Utils::getNextMonthString();
        $month_after_next = Utils::getMonthAfterNextString();
        
        foreach ($queries as $query) {
            if (empty($query)) {
                continue;
            }
            
            // Backward compatible: existing placeholder uses $next_month arg
            $query = str_replace('<VOLGEND_MAAND_JAAR>', $next_month, $query);
            // New placeholders
            $query = str_replace('<DEZE_MAAND_JAAR>', $this_month, $query);
            $query = str_replace('<VOLGENDE_MAAND_JAAR>', $next_month2, $query);
            $query = str_replace('<MAAND_DAARNA_JAAR>', $month_after_next, $query);

            $query = str_replace('<TARGET_WEEK>', $target_week, $query);
            $prepared_queries[] = $query;
        }
        
        return $prepared_queries;
    }

    /**
     * Format log for display
     */
    private function formatLog($stats, $errors, $skipped_reasons)
    {
        $timezone_string = get_option('timezone_string') ?: 'Europe/Amsterdam';
        $log = "Run uitgevoerd op " . wp_date('Y-m-d H:i:s', null, new \DateTimeZone($timezone_string)) . " ({$timezone_string})\n\n";
        
        $log .= "Statistieken:\n";
        $log .= "- Ontdekte URL’s: {$stats['discovered']}\n";
        $log .= "- Opgehaalde pagina’s: {$stats['fetched']}\n";
        $log .= "- Events herkend: {$stats['parsed']}\n";
        $log .= "- Posts geïmporteerd: {$stats['imported']}\n";
        $log .= "- Posts overgeslagen: {$stats['skipped']}\n\n";
        
        if (!empty($errors)) {
            $log .= "Fouten:\n";
            foreach ($errors as $error) {
                $log .= "- {$error}\n";
            }
            $log .= "\n";
        }
        
        if (!empty($skipped_reasons)) {
            $log .= "Redenen om over te slaan:\n";
            foreach ($skipped_reasons as $reason => $count) {
                $log .= "- {$reason}: {$count}\n";
            }
            $log .= "\n";
        }
        
        // Add debug log if available (for troubleshooting)
        $debug_log = Utils::getDebugLog();
        if (!empty($debug_log)) {
            $log .= "=== Debug-details (laatste 15 regels) ===\n";
            $log .= implode("\n", array_slice($debug_log, -15));
            $log .= "\n\n";
        }
        
        return $log;
    }

    /**
     * Check if run is in progress
     */
    public function isRunInProgress()
    {
        $lock_file = sys_get_temp_dir() . '/apify_events_run.lock';
        
        if (!file_exists($lock_file)) {
            return false;
        }
        
        $lock_time = filemtime($lock_file);
        $max_lock_time = 30 * 60; // 30 minutes
        
        if (time() - $lock_time > $max_lock_time) {
            // Remove stale lock
            unlink($lock_file);
            return false;
        }
        
        return true;
    }

    /**
     * Set run lock
     */
    private function setRunLock()
    {
        $lock_file = sys_get_temp_dir() . '/apify_events_run.lock';
        touch($lock_file);
    }

    /**
     * Remove run lock
     */
    private function removeRunLock()
    {
        $lock_file = sys_get_temp_dir() . '/apify_events_run.lock';
        if (file_exists($lock_file)) {
            unlink($lock_file);
        }
    }

    /**
     * Get run status
     */
    public function getRunStatus()
    {
        $last_run = get_option('apify_events_last_run', 0);
        $last_log = get_option('apify_events_last_run_log', '');
        
        $status = [
            'last_run' => $last_run,
            'last_run_formatted' => $last_run ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $last_run) : 'Never',
            'in_progress' => $this->isRunInProgress(),
            'next_scheduled' => wp_next_scheduled('apify_events_monthly'),
        ];
        
        if ($last_log) {
            $log_data = json_decode($last_log, true);
            if ($log_data) {
                $status['last_stats'] = $log_data['stats'] ?? [];
                $status['last_errors'] = $log_data['errors'] ?? [];
            }
        }
        
        return $status;
    }

    /**
     * Test Apify connection
     */
    public function testConnection()
    {
        try {
            $apify_client = new ApifyClient();
            
            if (!$apify_client->hasToken()) {
                return [
                    'success' => false,
                    'message' => 'Apify-token niet ingesteld'
                ];
            }
            
            // Try a simple search to test connection
            $test_queries = ['site:.nl test'];
            $run_id = $apify_client->runGoogleSearchScraper($test_queries, 'nl', 'nl', 1);
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'run_id' => $run_id
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Limit candidate list to at most N URLs per domain (keeps source diversity).
     *
     * @param array $results Array of items with 'url' key
     * @param int   $maxPerDomain Max URLs to keep per host
     * @return array Filtered list, order preserved
     */
    private function limitUrlsPerDomain(array $results, $maxPerDomain = 10)
    {
        if ($maxPerDomain < 1) {
            return $results;
        }
        $counts = [];
        $out = [];
        foreach ($results as $item) {
            $url = $item['url'] ?? '';
            if (empty($url)) {
                continue;
            }
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) {
                $out[] = $item;
                continue;
            }
            $host = strtolower($host);
            $counts[$host] = ($counts[$host] ?? 0) + 1;
            if ($counts[$host] <= $maxPerDomain) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Get domain statistics
     */
    public function getDomainStats()
    {
        $posts = get_posts([
            'meta_query' => [
                [
                    'key' => 'apify_source_url',
                    'compare' => 'EXISTS'
                ]
            ],
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => -1,
        ]);
        
        $domains = [];
        foreach ($posts as $post) {
            $url = get_post_meta($post->ID, 'apify_source_url', true);
            if ($url) {
                $domain = parse_url($url, PHP_URL_HOST);
                if ($domain) {
                    $domains[$domain] = ($domains[$domain] ?? 0) + 1;
                }
            }
        }
        
        arsort($domains);
        
        return $domains;
    }

    /**
     * Clean up old runs and logs
     */
    public function cleanup()
    {
        // Remove old run logs (keep last 10)
        $this->cleanupOldLogs();
        
        // Clean up old imports if configured
        $options = Utils::getOptions();
        if (!empty($options['cleanup_old_imports'])) {
            $importer = new Importer();
            $deleted_count = $importer->cleanupOldImports($options['cleanup_days'] ?? 90);
            Utils::log("Cleaned up {$deleted_count} old imports", 'info');
        }
    }

    /**
     * Clean up old run logs
     */
    private function cleanupOldLogs()
    {
        // This would be implemented if we stored multiple logs
        // For now, we only keep the last run log
        return;
    }

    /**
     * Run using free method (SerpAPI web search + scraping)
     */
    private function runFreeMethod($options, &$stats, &$errors, &$skipped_reasons)
    {
        $free_client = new FreeSearchClient();
        
        // Target week = week that starts 4 weeks from today (for weekly runs)
        list($target_week_start, $target_week_end) = Utils::getTargetWeekRange();
        $target_week_str = Utils::getTargetWeekString();
        Utils::log("Target week (events to import): {$target_week_str}", 'info');
        
        // Prepare queries: <VOLGEND_MAAND_JAAR> and <TARGET_WEEK>
        $next_month = Utils::getNextMonthString();
        $queries = $this->prepareQueries($options['queries'], $next_month, $target_week_str);
        
        if (empty($queries)) {
            throw new \Exception('No search queries configured');
        }
        
        Utils::log('Using queries: ' . implode(', ', $queries), 'info');
        
        // Step 1: Search for candidate URLs
        Utils::log('Stap 1: Zoeken naar kandidaat-URL’s', 'info');
        
        $search_results = [];
        
        // Try SerpAPI web search if configured
        if ($free_client->hasSearchApi()) {
            try {
                $search_results = $free_client->searchDutchEvents($queries, $options['max_results_per_query']);
                $stats['discovered'] = count($search_results);
                Utils::log("Found {$stats['discovered']} URLs via SerpAPI", 'info');
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
                Utils::log('SerpAPI failed: ' . $e->getMessage(), 'error');
            }
        }
        
        // Add manual URLs first so they are not dropped by the per-domain cap
        $manual_urls = $free_client->getManualUrls();
        if (!empty($manual_urls)) {
            $search_results = array_merge($manual_urls, $search_results);
            Utils::log("Added " . count($manual_urls) . " manual URLs", 'info');
        }
        
        // Limit URLs per domain so one site (e.g. groeneagenda.nl) doesn't dominate
        $max_per_domain = isset($options['max_urls_per_domain']) ? max(1, (int) $options['max_urls_per_domain']) : 10;
        $search_results = $this->limitUrlsPerDomain($search_results, $max_per_domain);
        
        $stats['discovered'] = count($search_results);
        $stats['sample_urls'] = array_slice(array_map(function ($r) { return $r['url'] ?? ''; }, $search_results), 0, 5);
        
        if (empty($search_results)) {
            throw new \Exception('No candidate URLs found. Please configure SerpAPI key or add manual URLs.');
        }
        
        // Step 2: Scrape pages
        Utils::log('Stap 2: Pagina’s scrapen', 'info');
        
        $extractor = new Extractor();
        $importer = new Importer();
        
        $valid_events = [];
        $processed_urls = [];
        
        foreach ($search_results as $result) {
            try {
                $url = $result['url'] ?? '';
                if (empty($url)) {
                    continue;
                }
                
                $canonical = Utils::getCanonicalUrl($url);
                if (in_array($canonical, $processed_urls, true)) {
                    Utils::log("Skipping already processed URL: {$url}", 'debug');
                    continue;
                }
                $processed_urls[] = $canonical;
                
                // Scrape the page
                $scraped_data = $free_client->scrapeWebpage($url);
                if (!$scraped_data) {
                    $skipped_reasons['scrape_failed'] = ($skipped_reasons['scrape_failed'] ?? 0) + 1;
                    continue;
                }
                
                $stats['fetched']++;
                
                // Extract events (may return multiple per page)
                $events = $extractor->extractEvents($scraped_data['html'], $url);
                $added = $this->collectValidEvents($events, $extractor, $valid_events, $stats, $skipped_reasons, $url);
                
                if ($added === 0) {
                    $linked_urls = $extractor->extractEventLinks($scraped_data['html'], $url);
                    foreach ($linked_urls as $linked_url) {
                        $linked_canonical = Utils::getCanonicalUrl($linked_url);
                        if (in_array($linked_canonical, $processed_urls, true)) {
                            continue;
                        }
                        $processed_urls[] = $linked_canonical;
                        
                        $linked_scrape = $free_client->scrapeWebpage($linked_url);
                        if (!$linked_scrape) {
                            $skipped_reasons['scrape_failed'] = ($skipped_reasons['scrape_failed'] ?? 0) + 1;
                            continue;
                        }
                        
                        $stats['fetched']++;
                        $linked_events = $extractor->extractEvents($linked_scrape['html'], $linked_url);
                        $this->collectValidEvents($linked_events, $extractor, $valid_events, $stats, $skipped_reasons, $linked_url);
                    }
                }
                
            } catch (\Exception $e) {
                $errors[] = "Fout bij verwerken {$url}: " . $e->getMessage();
                Utils::log("Fout bij verwerken {$url}: " . $e->getMessage(), 'error');
            }
        }
        
        Utils::log("Parsed {$stats['parsed']} valid events", 'info');
        
        // Optionally keep only events in the target week (4 weeks ahead)
        $restrict = $options['restrict_to_target_week'] ?? false;
        if ($restrict) {
            $before = count($valid_events);
            $valid_events = array_values(array_filter($valid_events, function ($e) use ($target_week_start, $target_week_end) {
                $d = $e['date_start'] ?? 0;
                return $d >= $target_week_start && $d <= $target_week_end;
            }));
            $removed = $before - count($valid_events);
            if ($removed > 0) {
            Utils::log("Gefilterd op doelweek: {$removed} events buiten {$target_week_str} verwijderd", 'info');
            }
        } else {
            Utils::log("Doelweek: {$target_week_str} (niet beperken — alle geldige events importeren)", 'info');
        }

        // Keep only events within this month + next month + month after next (and never in the past)
        $tz = new \DateTimeZone('Europe/Amsterdam');
        $now = new \DateTime('now', $tz);
        $windowStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $windowEnd = (clone $now)->modify('first day of this month')->modify('+2 months')->modify('last day of this month')->setTime(23, 59, 59);
        $windowStartTs = $windowStart->getTimestamp();
        $windowEndTs = $windowEnd->getTimestamp();
        $nowTs = $now->getTimestamp();

        $beforeWindow = count($valid_events);
        $valid_events = array_values(array_filter($valid_events, function ($e) use ($windowStartTs, $windowEndTs, $nowTs) {
            $d = (int) ($e['date_start'] ?? 0);
            return $d >= $nowTs && $d >= $windowStartTs && $d <= $windowEndTs;
        }));
        $removedWindow = $beforeWindow - count($valid_events);
        if ($removedWindow > 0) {
            Utils::log("Gefilterd op 3-maanden venster: {$removedWindow} events buiten deze+volgende+maand daarna verwijderd", 'info');
        }
        
        // Sort by confidence and limit to 10
        usort($valid_events, function($a, $b) {
            return ($b['confidence'] ?? 0) - ($a['confidence'] ?? 0);
        });
        
        $valid_events = array_slice($valid_events, 0, 10);
        
        // Import events
        foreach ($valid_events as $event_data) {
            try {
                $result = $importer->importEvent($event_data);
                
                if ($result['success']) {
                    $stats['imported']++;
                    Utils::log("Event geïmporteerd: {$event_data['title']}", 'info');
                } else {
                    $stats['skipped']++;
                    $reason = $result['reason'] ?? 'unknown';
                    $skipped_reasons[$reason] = ($skipped_reasons[$reason] ?? 0) + 1;
                    Utils::log("Event overgeslagen: {$event_data['title']} - {$result['message']}", 'info');
                }
                
            } catch (\Exception $e) {
                $stats['skipped']++;
                $skipped_reasons['import_error'] = ($skipped_reasons['import_error'] ?? 0) + 1;
                $errors[] = "Fout bij importeren {$event_data['title']}: " . $e->getMessage();
                Utils::log("Fout bij importeren {$event_data['title']}: " . $e->getMessage(), 'error');
            }
        }
        
        // Validate import count (lowered to 1 for testing)
        if ($stats['imported'] < 1) {
            $errors[] = "Geen events geïmporteerd (minstens 1 nodig voor test)";
        } elseif ($stats['imported'] < 3) {
            Utils::log("Waarschuwing: slechts {$stats['imported']} events geïmporteerd (richtlijn: 3-10)", 'info');
        }
        
        if ($stats['imported'] > 10) {
            Utils::log("Waarschuwing: {$stats['imported']} events geïmporteerd (maximaal 10 aanbevolen)", 'info');
        }
    }

    /**
     * Run using Apify method (original implementation)
     */
    private function runApifyMethod($options, &$stats, &$errors, &$skipped_reasons)
    {
        // Check if we have Apify token
        Utils::log('Creating ApifyClient instance', 'info');
        $apify_client = new ApifyClient();
        
        Utils::log('Checking if token is configured', 'info');
        if (!$apify_client->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }
        
        Utils::log('Token is configured, getting options', 'info');
        
        Utils::log('Preparing queries with next month', 'info');
        // Prepare queries with next month
        $next_month = Utils::getNextMonthString();
        $queries = $this->prepareQueries($options['queries'], $next_month);
        
        if (empty($queries)) {
            throw new \Exception('No search queries configured');
        }
        
        Utils::log('Using queries: ' . implode(', ', $queries), 'info');
        
        // Step 1: Search for candidate URLs
        Utils::log('Stap 1: Zoeken naar kandidaat-URL’s', 'info');
        $search_run_id = $apify_client->runGoogleSearchScraper(
            $queries,
            $options['language_code'],
            $options['country_code'],
            1 // max_pages_per_query
        );
        
        Utils::log("Google Search Scraper started with ID: {$search_run_id}", 'info');
        
        // Wait for search to complete
        $apify_client->waitForRunCompletion($search_run_id, 'apify/google-search-scraper');
        
        // Get search results
        $search_results = $apify_client->getGoogleSearchResults($search_run_id);
        $candidate_urls = $apify_client->extractUrlsFromSearchResults($search_results);
        
        $stats['discovered'] = count($candidate_urls);
        Utils::log("Found {$stats['discovered']} candidate URLs", 'info');
        
        if (empty($candidate_urls)) {
            throw new \Exception('No candidate URLs found');
        }
        
        // Limit URLs to respect max_fetched_pages
        $max_pages = $options['max_fetched_pages'] ?? 100;
        if (count($candidate_urls) > $max_pages) {
            $candidate_urls = array_slice($candidate_urls, 0, $max_pages);
            Utils::log("Limited to {$max_pages} URLs due to max_fetched_pages setting", 'info');
        }
        
        // Step 2: Fetch page content
        Utils::log('Step 2: Fetching page content', 'info');
        $crawl_run_id = $apify_client->runWebsiteContentCrawler($candidate_urls);
        
        Utils::log("Website Content Crawler started with ID: {$crawl_run_id}", 'info');
        
        // Wait for crawl to complete
        $apify_client->waitForRunCompletion($crawl_run_id, 'apify/website-content-crawler');
        
        // Get crawl results
        $crawl_results = $apify_client->getWebsiteContentResults($crawl_run_id);
        
        $stats['fetched'] = count($crawl_results);
        Utils::log("Fetched {$stats['fetched']} pages", 'info');
        
        // Step 3: Extract and import events
        Utils::log('Stap 3: Events extraheren en importeren', 'info');
        $extractor = new Extractor();
        $importer = new Importer();
        
        $valid_events = [];
        
        foreach ($crawl_results as $result) {
            try {
                $url = $result['url'] ?? '';
                $content = $result['html'] ?? '';
                
                if (empty($url) || empty($content)) {
                    $skipped_reasons['empty_content'] = ($skipped_reasons['empty_content'] ?? 0) + 1;
                    continue;
                }
                
                // Extract events
                $events = $extractor->extractEvents($content, $url);
                
                if (empty($events)) {
                    $skipped_reasons['invalid_event'] = ($skipped_reasons['invalid_event'] ?? 0) + 1;
                    continue;
                }
                
                $valid_from_page = 0;
                
                foreach ($events as $event_data) {
                    if (!$extractor->isValidEvent($event_data)) {
                        $skipped_reasons['invalid_event'] = ($skipped_reasons['invalid_event'] ?? 0) + 1;
                        continue;
                    }
                    
                    $stats['parsed']++;
                    $valid_events[] = $event_data;
                    $valid_from_page++;
                }
                
                if ($valid_from_page === 0) {
                    Utils::log("No valid events extracted from {$url}", 'debug');
                }
                
            } catch (\Exception $e) {
                $errors[] = "Fout bij verwerken {$url}: " . $e->getMessage();
                Utils::log("Fout bij verwerken {$url}: " . $e->getMessage(), 'error');
            }
        }
        
        Utils::log("Parsed {$stats['parsed']} valid events", 'info');
        
        // Sort by confidence and limit to 10
        usort($valid_events, function($a, $b) {
            return ($b['confidence'] ?? 0) - ($a['confidence'] ?? 0);
        });
        
        $valid_events = array_slice($valid_events, 0, 10);
        
        // Import events
        foreach ($valid_events as $event_data) {
            try {
                $result = $importer->importEvent($event_data);
                
                if ($result['success']) {
                    $stats['imported']++;
                    Utils::log("Event geïmporteerd: {$event_data['title']}", 'info');
                } else {
                    $stats['skipped']++;
                    $reason = $result['reason'] ?? 'unknown';
                    $skipped_reasons[$reason] = ($skipped_reasons[$reason] ?? 0) + 1;
                    Utils::log("Event overgeslagen: {$event_data['title']} - {$result['message']}", 'info');
                }
                
            } catch (\Exception $e) {
                $stats['skipped']++;
                $skipped_reasons['import_error'] = ($skipped_reasons['import_error'] ?? 0) + 1;
                $errors[] = "Fout bij importeren {$event_data['title']}: " . $e->getMessage();
                Utils::log("Fout bij importeren {$event_data['title']}: " . $e->getMessage(), 'error');
            }
        }
        
        // Validate import count (lowered to 1 for testing)
        if ($stats['imported'] < 1) {
            $errors[] = "Geen events geïmporteerd (minstens 1 nodig voor test)";
        } elseif ($stats['imported'] < 3) {
            Utils::log("Waarschuwing: slechts {$stats['imported']} events geïmporteerd (richtlijn: 3-10)", 'info');
        }
        
        if ($stats['imported'] > 10) {
            Utils::log("Waarschuwing: {$stats['imported']} events geïmporteerd (maximaal 10 aanbevolen)", 'info');
        }
    }

    /**
     * Validate and collect extracted events
     */
    private function collectValidEvents(array $events, Extractor $extractor, array &$valid_events, array &$stats, array &$skipped_reasons, string $source_url = '')
    {
        $added = 0;
        
        foreach ($events as $event_data) {
            if (!$extractor->isValidEvent($event_data)) {
                $skipped_reasons['invalid_event'] = ($skipped_reasons['invalid_event'] ?? 0) + 1;
                continue;
            }
            
            $stats['parsed']++;
            $valid_events[] = $event_data;
            $added++;
        }
        
        if ($added === 0 && !empty($events) && !empty($source_url)) {
            Utils::log("Geen geldige events geëxtraheerd uit {$source_url}", 'debug');
        }
        
        return $added;
    }
}
