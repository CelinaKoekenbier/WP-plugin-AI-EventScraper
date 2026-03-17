<?php

namespace ApifyEvents;

/**
 * Search client using SerpAPI (Google results) and HTML scraping.
 *
 * - SerpAPI: web search for event URLs (100 free searches/month).
 * - Manual URLs + default agenda URLs when no API key.
 * - Scrape pages via WordPress HTTP API.
 */
class FreeSearchClient
{
    /**
     * SerpAPI key
     */
    private $serpapi_api_key;

    /**
     * Constructor
     */
    public function __construct()
    {
        $options = Utils::getOptions();
        $this->serpapi_api_key = $options['serpapi_api_key'] ?? '';
    }

    /**
     * Check if SerpAPI is configured
     */
    public function hasSearchApi()
    {
        return !empty($this->serpapi_api_key);
    }

    /**
     * Search for Dutch events using SerpAPI (Google results)
     */
    public function searchDutchEvents($queries, $max_results = 20)
    {
        if (!$this->hasSearchApi()) {
            throw new \Exception('SerpAPI key not configured. Add your key in Settings → Apify Events (100 free searches/month).');
        }

        $all_results = [];
        $last_error = null;
        $per_query = min(10, max(1, (int) ceil($max_results / max(1, count($queries)))));

        foreach ($queries as $query) {
            try {
                $results = $this->serpApiSearch($query, $per_query);
                $all_results = array_merge($all_results, $results);
                sleep(1); // rate limit
            } catch (\Exception $e) {
                $last_error = $e->getMessage();
                Utils::log('SerpAPI failed for query: ' . $query . ' - ' . $last_error, 'error');
                continue;
            }
        }

        if (empty($all_results)) {
            if ($last_error) {
                throw new \Exception('SerpAPI failed: ' . $last_error);
            }
            throw new \Exception('SerpAPI returned no results. Try different search queries.');
        }

        $unique_results = $this->removeDuplicateUrls($all_results);
        return array_slice($unique_results, 0, $max_results);
    }

    /**
     * One SerpAPI request (Google search)
     * @see https://serpapi.com/search-api
     */
    private function serpApiSearch($query, $num = 10)
    {
        $url = 'https://serpapi.com/search';
        $params = [
            'engine' => 'google',
            'api_key' => $this->serpapi_api_key,
            'q' => $query,
            'gl' => 'nl',
            'hl' => 'nl',
            'num' => min(10, max(1, (int) $num)),
        ];
        $request_url = $url . '?' . http_build_query($params);

        $response = wp_remote_get($request_url, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('SerpAPI request failed: ' . $response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code !== 200) {
            $msg = isset($data['error']) ? $data['error'] : substr($body, 0, 200);
            throw new \Exception('SerpAPI error ' . $status_code . ': ' . $msg);
        }

        if (empty($data['organic_results'])) {
            return [];
        }

        $results = [];
        foreach ($data['organic_results'] as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['link'] ?? '',
                'snippet' => $item['snippet'] ?? '',
                'source' => 'serpapi',
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
        if (Utils::shouldExcludeUrl($url)) {
            return null;
        }

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8',
            ],
            'sslverify' => false,
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
            'headers' => wp_remote_retrieve_headers($response),
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
     * Default event agenda URLs (used when manual URLs empty)
     */
    private static $default_manual_urls = [
        'https://www.natuurmonumenten.nl/agenda',
        'https://www.ivn.nl/agenda',
        'https://www.staatsbosbeheer.nl/activiteiten',
    ];

    /**
     * Get manual URLs from settings (or defaults when empty)
     */
    public function getManualUrls()
    {
        $options = Utils::getOptions();
        $manual_urls = $options['manual_urls'] ?? '';

        if (empty(trim($manual_urls))) {
            return array_map(function ($url) {
                return [
                    'title' => 'Agenda',
                    'url' => $url,
                    'description' => 'Default agenda URL',
                    'source' => 'manual',
                ];
            }, self::$default_manual_urls);
        }

        $urls = array_filter(array_map('trim', explode("\n", $manual_urls)));
        $results = [];
        foreach ($urls as $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $results[] = [
                    'title' => 'Manual URL',
                    'url' => $url,
                    'description' => 'Manually added URL',
                    'source' => 'manual',
                ];
            }
        }
        return $results;
    }

    /**
     * Test SerpAPI (one request)
     */
    public function testSerpApi()
    {
        if (!$this->hasSearchApi()) {
            return ['success' => false, 'message' => 'SerpAPI key not configured. Add it in Settings and save.'];
        }
        try {
            $results = $this->serpApiSearch('evenement Nederland', 2);
            return [
                'success' => true,
                'message' => 'SerpAPI OK, found ' . count($results) . ' results (100 free searches/month).',
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
