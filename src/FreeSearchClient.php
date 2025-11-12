<?php

namespace ApifyEvents;

/**
 * Free search client using Google Custom Search API and HTML scraping.
 *
 * Responsibilities:
 * - Resolve settings options (API keys, manual URLs, excluded domains).
 * - Call Google Custom Search (100 free requests/day) and normalize results.
 * - Fetch arbitrary pages via WordPress HTTP API (with simple throttling).
 * - Provide RSS fallbacks and manual-URL ingestion for deterministic testing.
 *
 * Acts as the data source provider for `Runner::runFreeMethod()`.
 */
class FreeSearchClient
{
    /**
     * Google Custom Search API key
     */
    private $google_api_key;
    
    /**
     * Google Custom Search Engine ID
     */
    private $google_cse_id;

    /**
     * Constructor
     */
    public function __construct()
    {
        $options = Utils::getOptions();
        $this->google_api_key = $options['google_api_key'] ?? '';
        $this->google_cse_id = $options['google_cse_id'] ?? '';
    }

    /**
     * Check if Google API is configured
     */
    public function hasGoogleApi()
    {
        return !empty($this->google_api_key) && !empty($this->google_cse_id);
    }

    /**
     * Search for Dutch events using Google Custom Search
     */
    public function searchDutchEvents($queries, $max_results = 20)
    {
        if (!$this->hasGoogleApi()) {
            throw new \Exception('Google Custom Search API not configured');
        }

        $all_results = [];
        
        foreach ($queries as $query) {
            try {
                $results = $this->googleCustomSearch($query, min(10, $max_results));
                $all_results = array_merge($all_results, $results);
                
                // Rate limiting - wait 1 second between requests
                sleep(1);
                
            } catch (\Exception $e) {
                Utils::log('Google search failed for query: ' . $query . ' - ' . $e->getMessage(), 'error');
                continue;
            }
        }
        
        // Remove duplicates and limit results
        $unique_results = $this->removeDuplicateUrls($all_results);
        return array_slice($unique_results, 0, $max_results);
    }

    /**
     * Perform Google Custom Search
     */
    private function googleCustomSearch($query, $num_results = 10)
    {
        $url = 'https://www.googleapis.com/customsearch/v1';
        
        $params = [
            'key' => $this->google_api_key,
            'cx' => $this->google_cse_id,
            'q' => $query,
            'num' => min($num_results, 10), // Google limits to 10 per request
            'safe' => 'off',
            'lr' => 'lang_nl', // Dutch language
            'cr' => 'countryNL', // Netherlands country
        ];
        
        $request_url = $url . '?' . http_build_query($params);
        
        $response = wp_remote_get($request_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'WordPress Apify Events Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            throw new \Exception('Google API request failed: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            throw new \Exception('Google API error ' . $status_code . ': ' . $body);
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$data || !isset($data['items'])) {
            return [];
        }
        
        $results = [];
        foreach ($data['items'] as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'source' => 'google_custom_search'
            ];
        }
        
        return $results;
    }

    /**
     * Scrape webpage content using WordPress HTTP API
     */
    public function scrapeWebpage($url)
    {
        if (empty($url)) {
            return null;
        }
        
        // Check if URL should be excluded
        if (Utils::shouldExcludeUrl($url)) {
            return null;
        }
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (compatible; WordPress Apify Events Plugin)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8',
            ],
            'sslverify' => false, // For local development
        ]);
        
        if (is_wp_error($response)) {
            Utils::log('Failed to fetch URL: ' . $url . ' - ' . $response->get_error_message(), 'error');
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            Utils::log('HTTP error ' . $status_code . ' for URL: ' . $url, 'error');
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }
        
        return [
            'url' => $url,
            'html' => $body,
            'status_code' => $status_code,
            'headers' => wp_remote_retrieve_headers($response)
        ];
    }

    /**
     * Remove duplicate URLs from results
     */
    private function removeDuplicateUrls($results)
    {
        $seen_urls = [];
        $unique_results = [];
        
        foreach ($results as $result) {
            $url = $result['url'] ?? '';
            $canonical_url = Utils::getCanonicalUrl($url);
            
            if (!in_array($canonical_url, $seen_urls)) {
                $seen_urls[] = $canonical_url;
                $unique_results[] = $result;
            }
        }
        
        return $unique_results;
    }

    /**
     * Get Dutch event websites RSS feeds
     */
    public function getRssFeeds()
    {
        $rss_feeds = [
            'https://www.eventbrite.nl/d/netherlands/events/',
            'https://www.meetup.com/find/events/?location=nl',
            'https://www.facebook.com/events/',
        ];
        
        $events = [];
        
        foreach ($rss_feeds as $feed_url) {
            try {
                $feed_events = $this->parseRssFeed($feed_url);
                $events = array_merge($events, $feed_events);
            } catch (\Exception $e) {
                Utils::log('RSS feed failed: ' . $feed_url . ' - ' . $e->getMessage(), 'error');
                continue;
            }
        }
        
        return $events;
    }

    /**
     * Parse RSS feed for events
     */
    private function parseRssFeed($feed_url)
    {
        $response = wp_remote_get($feed_url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'WordPress Apify Events Plugin'
            ]
        ]);
        
        if (is_wp_error($response)) {
            throw new \Exception('RSS feed request failed: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return [];
        }
        
        // Simple RSS parsing (WordPress has built-in RSS parser but it's limited)
        $events = [];
        
        // Look for common RSS patterns
        if (preg_match_all('/<item[^>]*>(.*?)<\/item>/is', $body, $items)) {
            foreach ($items[1] as $item) {
                $event = $this->parseRssItem($item);
                if ($event) {
                    $events[] = $event;
                }
            }
        }
        
        return $events;
    }

    /**
     * Parse individual RSS item
     */
    private function parseRssItem($item_xml)
    {
        $title = '';
        $link = '';
        $description = '';
        
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $item_xml, $matches)) {
            $title = strip_tags($matches[1]);
        }
        
        if (preg_match('/<link[^>]*>(.*?)<\/link>/is', $item_xml, $matches)) {
            $link = trim($matches[1]);
        }
        
        if (preg_match('/<description[^>]*>(.*?)<\/description>/is', $item_xml, $matches)) {
            $description = strip_tags($matches[1]);
        }
        
        if (empty($title) || empty($link)) {
            return null;
        }
        
        // Check if it's likely an event
        if (!$this->isLikelyEvent($title, $description)) {
            return null;
        }
        
        return [
            'title' => $title,
            'url' => $link,
            'description' => $description,
            'source' => 'rss_feed'
        ];
    }

    /**
     * Check if content is likely an event
     */
    private function isLikelyEvent($title, $description)
    {
        $event_keywords = [
            'evenement', 'event', 'workshop', 'lezing', 'cursus', 'training',
            'festival', 'beurs', 'bijeenkomst', 'conferentie', 'seminar',
            'datum', 'tijd', 'locatie', 'plaats', 'inschrijven', 'aanmelden'
        ];
        
        $text = strtolower($title . ' ' . $description);
        
        foreach ($event_keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get manual URLs from settings
     */
    public function getManualUrls()
    {
        $options = Utils::getOptions();
        $manual_urls = $options['manual_urls'] ?? '';
        
        if (empty($manual_urls)) {
            return [];
        }
        
        $urls = array_filter(array_map('trim', explode("\n", $manual_urls)));
        $results = [];
        
        foreach ($urls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $results[] = [
                    'title' => 'Manual URL',
                    'url' => $url,
                    'description' => 'Manually added URL',
                    'source' => 'manual'
                ];
            }
        }
        
        return $results;
    }

    /**
     * Test Google Custom Search API
     */
    public function testGoogleApi()
    {
        if (!$this->hasGoogleApi()) {
            return [
                'success' => false,
                'message' => 'Google API key or CSE ID not configured'
            ];
        }
        
        try {
            $results = $this->googleCustomSearch('test evenement', 1);
            return [
                'success' => true,
                'message' => 'Google API working, found ' . count($results) . ' results'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Google API error: ' . $e->getMessage()
            ];
        }
    }
}
