<?php

namespace ApifyEvents;

/**
 * Apify API client.
 *
 * Thin wrapper around the Apify REST API used when the paid actors are
 * available. Handles:
 * - Starting actor runs (Google Search Scraper, Website Content Crawler, Web Scraper).
 * - Polling run status / waiting for completion.
 * - Fetching dataset items and normalising responses into PHP arrays.
 *
 * When free mode is enabled this class is bypassed in favour of
 * `FreeSearchClient`, but the code is still loaded so we keep the interface
 * consistent.
 */
class ApifyClient
{
    /**
     * Apify API base URL
     */
    const API_BASE_URL = 'https://api.apify.com/v2';

    /**
     * Apify token
     */
    private $token;

    /**
     * Constructor
     */
    public function __construct()
    {
        $options = Utils::getOptions();
        $this->token = $options['apify_token'] ?? '';
    }

    /**
     * Check if token is set
     */
    public function hasToken()
    {
        return !empty($this->token);
    }

    /**
     * Run Google Search Scraper
     */
    public function runGoogleSearchScraper($queries, $language_code = 'nl', $country_code = 'nl', $max_pages_per_query = 1)
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . '/acts/apify/google-search-scraper/runs';
        
        // Convert queries array to string (Apify expects a string, not array)
        $queries_string = is_array($queries) ? implode(', ', $queries) : $queries;
        
        $data = [
            'queries' => $queries_string,
            'languageCode' => $language_code,
            'countryCode' => $country_code,
            'maxPagesPerQuery' => $max_pages_per_query,
            'includeUnfilteredResults' => false,
        ];

        Utils::log('Making request to Apify API: ' . $url, 'info');
        Utils::log('Request data: ' . json_encode($data), 'info');
        
        $response = $this->makeRequest('POST', $url, $data);
        
        Utils::log('Response received: ' . json_encode($response), 'info');
        
        if (!$response || !isset($response['data']['id'])) {
            throw new \Exception('Mislukt om Google Search Scraper te starten');
        }

        return $response['data']['id'];
    }

    /**
     * Get Google Search Scraper results
     */
    public function getGoogleSearchResults($run_id)
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . "/acts/apify/google-search-scraper/runs/{$run_id}/dataset/items";
        
        $response = $this->makeRequest('GET', $url);
        
        if (!$response || !isset($response['data'])) {
            throw new \Exception('Mislukt om Google Search-resultaten op te halen');
        }

        return $response['data'];
    }

    /**
     * Run Website Content Crawler
     */
    public function runWebsiteContentCrawler($urls, $max_crawl_depth = 0)
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . '/acts/apify/website-content-crawler/runs';
        
        $start_urls = array_map(function($url) {
            return ['url' => $url];
        }, $urls);

        $data = [
            'startUrls' => $start_urls,
            'maxCrawlDepth' => $max_crawl_depth,
            'removeElementsCssSelector' => 'nav,header,footer,script,style,noscript,.cookie,[role="dialog"],[role="navigation"]',
            'maxRequestsPerCrawl' => count($urls), // Limit to number of URLs we're crawling
        ];

        $response = $this->makeRequest('POST', $url, $data);
        
        if (!$response || !isset($response['data']['id'])) {
            throw new \Exception('Mislukt om Website Content Crawler te starten');
        }

        return $response['data']['id'];
    }

    /**
     * Get Website Content Crawler results
     */
    public function getWebsiteContentResults($run_id)
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . "/acts/apify/website-content-crawler/runs/{$run_id}/dataset/items";
        
        $response = $this->makeRequest('GET', $url);
        
        if (!$response || !isset($response['data'])) {
            throw new \Exception('Mislukt om Website Content-resultaten op te halen');
        }

        return $response['data'];
    }

    /**
     * Run Web Scraper (fallback)
     */
    public function runWebScraper($urls)
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . '/acts/apify/web-scraper/runs';
        
        $start_urls = array_map(function($url) {
            return ['url' => $url];
        }, $urls);

        $data = [
            'startUrls' => $start_urls,
            'maxRequestsPerCrawl' => count($urls),
        ];

        $response = $this->makeRequest('POST', $url, $data);
        
        if (!$response || !isset($response['data']['id'])) {
            throw new \Exception('Mislukt om Web Scraper te starten');
        }

        return $response['data']['id'];
    }

    /**
     * Get Web Scraper results
     */
    public function getWebScraperResults($run_id)
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . "/acts/apify/web-scraper/runs/{$run_id}/dataset/items";
        
        $response = $this->makeRequest('GET', $url);
        
        if (!$response || !isset($response['data'])) {
            throw new \Exception('Mislukt om Web Scraper-resultaten op te halen');
        }

        return $response['data'];
    }

    /**
     * Wait for run to complete
     */
    public function waitForRunCompletion($run_id, $actor_name = 'apify/google-search-scraper', $timeout = 300)
    {
        $start_time = time();
        $url = self::API_BASE_URL . "/acts/{$actor_name}/runs/{$run_id}";
        
        while (time() - $start_time < $timeout) {
            $response = $this->makeRequest('GET', $url);
            
            if (!$response || !isset($response['data']['status'])) {
                throw new \Exception('Kon runstatus niet ophalen');
            }
            
            $status = $response['data']['status'];
            
            if ($status === 'SUCCEEDED') {
                return true;
            }
            
            if ($status === 'FAILED' || $status === 'ABORTED') {
                throw new \Exception("Run mislukt met status: {$status}");
            }
            
            // Wait 5 seconds before checking again
            sleep(5);
        }
        
        throw new \Exception('Run-time-out overschreden');
    }

    /**
     * Make HTTP request to Apify API
     */
    private function makeRequest($method, $url, $data = null)
    {
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            throw new \Exception('HTTP-aanvraag mislukt: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code >= 400) {
            $error_data = json_decode($body, true);
            $error_message = $error_data['error']['message'] ?? 'Onbekende fout';
            throw new \Exception("API-fout {$status_code}: {$error_message}");
        }

        $decoded_body = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Ongeldige JSON-respons: ' . json_last_error_msg());
        }

        return $decoded_body;
    }

    /**
     * Extract URLs from Google Search results
     */
    public function extractUrlsFromSearchResults($results)
    {
        $urls = [];
        
        foreach ($results as $result) {
            if (isset($result['url']) && !empty($result['url'])) {
                $url = $result['url'];
                
                // Skip excluded domains
                if (Utils::shouldExcludeUrl($url)) {
                    continue;
                }
                
                // Only include .nl domains or domains that might be Dutch
                if (strpos($url, '.nl') !== false || self::isLikelyDutchUrl($url)) {
                    $urls[] = $url;
                }
            }
        }
        
        return array_unique($urls);
    }

    /**
     * Check if URL is likely Dutch
     */
    private static function isLikelyDutchUrl($url)
    {
        $dutch_indicators = [
            'evenement', 'agenda', 'event', 'activiteit', 'bijeenkomst',
            'workshop', 'lezing', 'cursus', 'festival', 'beurs'
        ];
        
        $url_lower = strtolower($url);
        
        foreach ($dutch_indicators as $indicator) {
            if (strpos($url_lower, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get run status
     */
    public function getRunStatus($run_id, $actor_name = 'apify/google-search-scraper')
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . "/acts/{$actor_name}/runs/{$run_id}";
        
        $response = $this->makeRequest('GET', $url);
        
        if (!$response || !isset($response['data']['status'])) {
            throw new \Exception('Kon runstatus niet ophalen');
        }

        return $response['data']['status'];
    }

    /**
     * Abort run
     */
    public function abortRun($run_id, $actor_name = 'apify/google-search-scraper')
    {
        if (!$this->hasToken()) {
            throw new \Exception('Apify-token niet ingesteld');
        }

        $url = self::API_BASE_URL . "/acts/{$actor_name}/runs/{$run_id}/abort";
        
        $response = $this->makeRequest('POST', $url);
        
        return $response !== null;
    }
}
