<?php

namespace ApifyEvents;

/**
 * Event data extractor.
 *
 * Centralises every bit of content parsing logic:
 * - Multi-event JSON-LD parsing (`extractSchemaOrgEvents` / `flattenSchemaItems`).
 * - Heuristic fallbacks for single event pages (date/time/location regexes).
 * - Listing-page parsing (`extractEventsFromListing`) for agendas that render
 *   multiple cards without structured data.
 * - Utility helpers for URL normalisation, Dutch date parsing, confidence scoring.
 *
 * It exposes three main methods used elsewhere:
 * - `extractEvents()` — returns an array of candidate events for a page.
 * - `extractEventLinks()` — discovers detail-page URLs when we’re on a listing.
 * - `isValidEvent()` — final validation gate before import.
 *
 * Related helpers live in `Utils` (date/month tables, hashing, logging).
 */
class Extractor
{
    /**
     * Extract multiple events from webpage content
     */
    public function extractEvents($content, $url)
    {
        $events = [];
        
        // Try JSON-LD schema.org first (supports multiple events per page)
        $schema_events = $this->extractSchemaOrgEvents($content, $url);
        if (!empty($schema_events)) {
            $schema_events = $this->pruneOutdatedEvents($schema_events);
            if (!empty($schema_events)) {
                $events = $schema_events;
            }
        }
        
        // Fallback to heuristics if no schema events were found
        if (empty($events)) {
            $heuristic_event = $this->buildHeuristicEvent($content, $url);
            if (!empty($heuristic_event)) {
                $events[] = $heuristic_event;
            }
        }
        
        // Domain/listing specific fallback
        if (empty($events)) {
            $listing_events = $this->extractEventsFromListing($content, $url);
            if (!empty($listing_events)) {
                $listing_events = $this->pruneOutdatedEvents($listing_events);
                if (!empty($listing_events)) {
                    $events = $listing_events;
                }
            }
        }
        
        // Normalise events (defaults, absolute URLs, validation)
        $normalised_events = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            
            $event_data = array_merge($this->createEmptyEvent($url), $event);
            
            if (!empty($event_data['url'])) {
                $event_data['url'] = $this->makeAbsoluteUrl($event_data['url'], $url);
            } else {
                $event_data['url'] = $url;
            }
            
            $event_data['source_url'] = $url;
            $event_data = $this->validateAndCleanData($event_data);
            
            // Debug log
            Utils::log("Extracted event from {$url}: " . json_encode([
                'title' => $event_data['title'],
                'date_start' => $event_data['date_start'] ? date('Y-m-d', $event_data['date_start']) : 'none',
                'place' => $event_data['place'],
                'confidence' => $event_data['confidence'],
                'url' => $event_data['url'],
            ]), 'debug');
            
            $normalised_events[] = $event_data;
        }
        
        return $normalised_events;
    }

    /**
     * Extract first event (backwards compatibility)
     */
    public function extractEventData($content, $url)
    {
        $events = $this->extractEvents($content, $url);
        return $events[0] ?? $this->createEmptyEvent($url);
    }

    /**
     * Create empty event template
     */
    private function createEmptyEvent($url = '')
    {
        return [
            'title' => '',
            'date_start' => null,
            'date_end' => null,
            'time' => null,
            'place' => '',
            'image' => '',
            'description' => '',
            'url' => $url,
            'source_url' => $url,
            'confidence' => 0,
        ];
    }

    /**
     * Build event using heuristics (single-event pages)
     */
    private function buildHeuristicEvent($content, $url)
    {
        $event_data = $this->createEmptyEvent($url);
        
        $heuristic_data = $this->extractHeuristicData($content, $url);
        if ($heuristic_data) {
            foreach ($heuristic_data as $key => $value) {
                if (empty($event_data[$key]) && !empty($value)) {
                    $event_data[$key] = $value;
                }
            }
            $event_data['confidence'] += 30;
        }
        
        if (empty($event_data['title']) && empty($event_data['date_start'])) {
            return [];
        }
        
        return $event_data;
    }

    /**
     * Listing parser: derive events directly from cards/rows (e.g. natuurmonumenten.nl agenda)
     */
    private function extractEventsFromListing($content, $source_url)
    {
        $events = [];
        $seen_urls = [];
        
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadHTML($content, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return $events;
        }
        libxml_clear_errors();
        
        $xpath = new \DOMXPath($dom);
        $link_query = "//a[contains(@href,'/agenda') or contains(@href,'/evenement') or contains(@href,'/event') or contains(@href,'/activiteit') or contains(@href,'/activiteiten') or contains(@href,'/excursie') or contains(@href,'/wandeling') or contains(@href,'/workshop')]";
        $nodes = $xpath->query($link_query);
        
        if (!$nodes || $nodes->length === 0) {
            return $events;
        }
        
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            
            $href = $node->getAttribute('href');
            if (empty($href)) {
                continue;
            }
            
            $absolute_url = $this->makeAbsoluteUrl($href, $source_url);
            if (empty($absolute_url)) {
                continue;
            }
            
            $canonical = Utils::getCanonicalUrl($absolute_url);
            if (in_array($canonical, $seen_urls, true)) {
                continue;
            }
            $seen_urls[] = $canonical;
            
            $title = $this->cleanText($node->textContent);
            if (empty($title)) {
                continue;
            }
            
            $context_node = $this->findListingContextNode($node);
            $context_html = $this->nodeOuterHtml($context_node ?: $node);
            if (!empty($context_html)) {
                $context_html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $context_html);
                $context_html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $context_html);
                $context_html = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $context_html);
            }
            $context_text = $this->cleanText($context_html);
            
            $event = $this->createEmptyEvent($absolute_url);
            $event['title'] = $title;
            
            // Dates
            $dates = $this->extractDates($context_html);
            if (!empty($dates)) {
                $event['date_start'] = $dates[0];
                if (count($dates) > 1) {
                    $event['date_end'] = $dates[1];
                }
            }
            
            // Time
            $time = $this->extractTime($context_html);
            if (!empty($time)) {
                $event['time'] = $time;
            }
            
            // Location
            $place = $this->extractLocation($context_html);
            if (!empty($place)) {
                $event['place'] = $place;
            }
            
            // Description (short snippet)
            if (!empty($context_text)) {
                $event['description'] = $context_text;
            }
            
            // Boost confidence because we have at least title; more bonuses applied later
            $event['confidence'] += 20;
            
            $events[] = $event;
        }
        
        return $events;
    }

    /**
     * Extract event detail links from aggregator/listing pages
     */
    public function extractEventLinks($content, $base_url)
    {
        $links = [];
        $base_host = parse_url($base_url, PHP_URL_HOST);
        
        if (empty($base_host)) {
            return $links;
        }
        
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $href) {
                if (empty($href)) {
                    continue;
                }
                
                $absolute = $this->makeAbsoluteUrl($href, $base_url);
                if (empty($absolute)) {
                    continue;
                }
                
                $absolute = strtok($absolute, '#');
                $absolute_host = parse_url($absolute, PHP_URL_HOST);
                
                if ($absolute_host && $absolute_host !== $base_host) {
                    continue;
                }
                
                // Focus on likely event detail slugs (Dutch + English event terms)
                if (preg_match('~/(agenda|event|evenement|activiteiten|activiteit|excursie|wandeling|workshop|cursus|lezing|events)[^/]*(/|$)~i', $absolute)) {
                    $links[] = $absolute;
                }
            }
        }
        
        $links = array_values(array_unique($links));
        // Safety limit
        if (count($links) > 25) {
            $links = array_slice($links, 0, 25);
        }
        
        return $links;
    }

    /**
     * Extract schema.org events (supports multiple entries)
     */
    private function extractSchemaOrgEvents($content, $source_url)
    {
        $events = [];
        
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content, $matches)) {
            foreach ($matches[1] as $json_string) {
                $json_string = trim($json_string);
                if ($json_string === '') {
                    continue;
                }
                
                $json_data = json_decode($json_string, true);
                if (!$json_data) {
                    continue;
                }
                
                $items = $this->flattenSchemaItems($json_data);
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    
                    $types = [];
                    if (isset($item['@type'])) {
                        $types = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
                    }
                    
                    if (!in_array('Event', $types, true)) {
                        continue;
                    }
                    
                    $event_data = $this->parseSchemaOrgEvent($item, $source_url);
                    if (!empty($event_data)) {
                        $events[] = $event_data;
                    }
                }
            }
        }
        
        return $events;
    }

    /**
     * Parse schema.org Event object
     */
    private function parseSchemaOrgEvent($event, $source_url = '')
    {
        $data = $this->createEmptyEvent($source_url);
        $data['confidence'] = 60; // Base confidence for schema data
        
        // Title
        if (isset($event['name'])) {
            $data['title'] = $this->cleanText($event['name']);
        }
        
        // Dates & times
        if (isset($event['startDate'])) {
            $start = $this->parseSchemaDateTime($event['startDate']);
            if (!empty($start['timestamp'])) {
                $data['date_start'] = $start['timestamp'];
            }
            if (!empty($start['time'])) {
                $data['time'] = $start['time'];
            }
        }
        
        if (isset($event['endDate'])) {
            $end = $this->parseSchemaDateTime($event['endDate']);
            if (!empty($end['timestamp'])) {
                $data['date_end'] = $end['timestamp'];
            }
        }
        
        // Location
        if (isset($event['location'])) {
            $location = $event['location'];
            if (is_string($location)) {
                $data['place'] = $this->cleanText($location);
            } elseif (is_array($location)) {
                if (isset($location['name'])) {
                    $data['place'] = $this->cleanText($location['name']);
                } elseif (isset($location['address'])) {
                    $address = $location['address'];
                    if (is_string($address)) {
                        $data['place'] = $this->cleanText($address);
                    } elseif (is_array($address)) {
                        $parts = [];
                        if (!empty($address['streetAddress'])) {
                            $parts[] = $address['streetAddress'];
                        }
                        if (!empty($address['addressLocality'])) {
                            $parts[] = $address['addressLocality'];
                        }
                        if (!empty($address['addressRegion'])) {
                            $parts[] = $address['addressRegion'];
                        }
                        if (!empty($address['addressCountry'])) {
                            $parts[] = $address['addressCountry'];
                        }
                        $data['place'] = $this->cleanText(implode(', ', $parts));
                    }
                }
            }
        }
        
        // Image
        if (isset($event['image'])) {
            $image = $event['image'];
            if (is_string($image)) {
                $data['image'] = $this->cleanUrl($image);
            } elseif (is_array($image)) {
                if (isset($image['url'])) {
                    $data['image'] = $this->cleanUrl($image['url']);
                } elseif (!empty($image[0])) {
                    $data['image'] = $this->cleanUrl(is_array($image[0]) && isset($image[0]['url']) ? $image[0]['url'] : $image[0]);
                }
            }
        }
        
        // Description
        if (isset($event['description'])) {
            $data['description'] = $this->cleanText($event['description']);
        }
        
        // URL
        if (isset($event['url'])) {
            if (is_array($event['url']) && isset($event['url']['@id'])) {
                $data['url'] = $this->cleanUrl($event['url']['@id']);
            } else {
                $data['url'] = $this->cleanUrl(is_array($event['url']) ? reset($event['url']) : $event['url']);
            }
        } elseif (isset($event['@id'])) {
            $data['url'] = $this->cleanUrl($event['@id']);
        }
        
        return $data;
    }

    /**
     * Flatten schema items from JSON-LD
     */
    private function flattenSchemaItems($json)
    {
        $items = [];
        
        if (isset($json['@graph']) && is_array($json['@graph'])) {
            foreach ($json['@graph'] as $graph_item) {
                $items = array_merge($items, $this->flattenSchemaItems($graph_item));
            }
            return $items;
        }
        
        $types = [];
        if (isset($json['@type'])) {
            $types = is_array($json['@type']) ? $json['@type'] : [$json['@type']];
        }
        
        if (in_array('ItemList', $types, true) && isset($json['itemListElement'])) {
            $elements = is_array($json['itemListElement']) ? $json['itemListElement'] : [$json['itemListElement']];
            foreach ($elements as $element) {
                if (isset($element['item'])) {
                    $items = array_merge($items, $this->flattenSchemaItems($element['item']));
                } else {
                    $items = array_merge($items, $this->flattenSchemaItems($element));
                }
            }
            return $items;
        }
        
        if (in_array('EventSeries', $types, true) && isset($json['subEvent'])) {
            $sub_events = is_array($json['subEvent']) ? $json['subEvent'] : [$json['subEvent']];
            foreach ($sub_events as $sub_event) {
                $items = array_merge($items, $this->flattenSchemaItems($sub_event));
            }
            return $items;
        }
        
        if (!empty($types)) {
            $items[] = $json;
            return $items;
        }
        
        if (is_array($json)) {
            foreach ($json as $value) {
                if (is_array($value)) {
                    $items = array_merge($items, $this->flattenSchemaItems($value));
                }
            }
        }
        
        return $items;
    }

    /**
     * Parse schema.org date/time
     */
    private function parseSchemaDateTime($date_string)
    {
        if (empty($date_string)) {
            return [
                'timestamp' => null,
                'time' => null,
            ];
        }
        
        try {
            $timezone = new \DateTimeZone('Europe/Amsterdam');
            $date = new \DateTime($date_string, $timezone);
            return [
                'timestamp' => $date->getTimestamp(),
                'time' => $date->format('H:i'),
            ];
        } catch (\Exception $e) {
            return [
                'timestamp' => null,
                'time' => null,
            ];
        }
    }


    /**
     * Parse schema.org date
     */
    private function parseSchemaDate($date_string)
    {
        if (empty($date_string)) {
            return null;
        }
        
        try {
            $timezone = new \DateTimeZone('Europe/Amsterdam');
            $date = new \DateTime($date_string, $timezone);
            return $date->getTimestamp();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Extract data using heuristics
     */
    private function extractHeuristicData($content, $url)
    {
        $data = [];
        
        // Extract title from <title> or <h1>
        $data['title'] = $this->extractTitle($content);
        
        // Extract dates
        $dates = $this->extractDates($content);
        if (!empty($dates)) {
            $data['date_start'] = $dates[0];
            if (count($dates) > 1) {
                $data['date_end'] = $dates[1];
            }
        }
        
        // Extract time
        $data['time'] = $this->extractTime($content);
        
        // Extract location
        $data['place'] = $this->extractLocation($content);
        
        // Extract image
        $data['image'] = $this->extractImage($content, $url);
        
        // Extract description
        $data['description'] = $this->extractDescription($content);
        
        return $data;
    }

    /**
     * Extract title
     */
    private function extractTitle($content)
    {
        // Try <h1> first
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $content, $matches)) {
            $title = $this->cleanText($matches[1]);
            if (!empty($title)) {
                return $title;
            }
        }
        
        // Try <title>
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $content, $matches)) {
            $title = $this->cleanText($matches[1]);
            if (!empty($title)) {
                return $title;
            }
        }
        
        return '';
    }

    /**
     * Extract dates
     */
    private function extractDates($content)
    {
        $dates = [];
        
        // Remove HTML tags for better date parsing
        $text_content = strip_tags($content);
        
        // Dutch date patterns
        $patterns = [
            // dd maand yyyy
            '/(\d{1,2})\s+(januari|februari|maart|april|mei|juni|juli|augustus|september|oktober|november|december)\s+(\d{4})/i',
            // d maand yyyy
            '/(\d{1,2})\s+(jan|feb|mrt|apr|mei|jun|jul|aug|sep|okt|nov|dec)\s+(\d{4})/i',
            // dd-mm-yyyy
            '/(\d{1,2})-(\d{1,2})-(\d{4})/',
            // dd/mm/yyyy
            '/(\d{1,2})\/(\d{1,2})\/(\d{4})/',
            // yyyy-mm-dd
            '/(\d{4})-(\d{1,2})-(\d{1,2})/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text_content, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $date_string = $match[0];
                    $timestamp = Utils::parseDutchDate($date_string);
                    if ($timestamp) {
                        $dates[] = $timestamp;
                    }
                }
            }
        }
        
        // Sort dates and remove duplicates
        $dates = array_unique($dates);
        sort($dates);
        
        return $dates;
    }

    /**
     * Extract time
     */
    private function extractTime($content)
    {
        $text_content = strip_tags($content);
        
        // Time patterns
        $patterns = [
            '/(\d{1,2}):(\d{2})/',
            '/(\d{1,2})\.(\d{2})/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text_content, $matches)) {
                $time = Utils::parseTime($matches[0]);
                if ($time) {
                    return $time;
                }
            }
        }
        
        return null;
    }

    /**
     * Extract location
     */
    private function extractLocation($content)
    {
        $text_content = strip_tags($content);
        
        // Look for location markers
        $location_markers = [
            'locatie:', 'waar:', 'adres:', 'plaats:', 'location:', 'address:',
            'locatie', 'waar', 'adres', 'plaats', 'location', 'address'
        ];
        
        foreach ($location_markers as $marker) {
            $pattern = '/' . preg_quote($marker, '/') . '\s*([^\n\r]+)/i';
            if (preg_match($pattern, $text_content, $matches)) {
                $location = trim($matches[1]);
                if (!empty($location) && Utils::isDutchLocation($location)) {
                    return $this->cleanText($location);
                }
            }
        }
        
        // Look for Dutch city names
        $dutch_cities = [
            'amsterdam', 'rotterdam', 'den haag', 'utrecht', 'eindhoven', 'tilburg',
            'groningen', 'almere', 'breda', 'nijmegen', 'enschede', 'haarlem',
            'arnhem', 'zaanstad', 'amersfoort', 'apeldoorn', 'maastricht', 'leiden'
        ];
        
        foreach ($dutch_cities as $city) {
            if (stripos($text_content, $city) !== false) {
                return ucfirst($city);
            }
        }
        
        return '';
    }

    /**
     * Extract image
     */
    private function extractImage($content, $base_url)
    {
        // Look for images in content
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            foreach ($matches[1] as $img_url) {
                $img_url = $this->cleanUrl($img_url);
                $full_url = $this->makeAbsoluteUrl($img_url, $base_url);
                
                if (Utils::isValidImage($full_url)) {
                    return $full_url;
                }
            }
        }
        
        // Look for Open Graph images
        if (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            $img_url = $this->cleanUrl($matches[1]);
            $full_url = $this->makeAbsoluteUrl($img_url, $base_url);
            
            if (Utils::isValidImage($full_url)) {
                return $full_url;
            }
        }
        
        return '';
    }

    /**
     * Extract description
     */
    private function extractDescription($content)
    {
        // Try meta description first
        if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            $description = $this->cleanText($matches[1]);
            if (!empty($description)) {
                return $description;
            }
        }
        
        // Try Open Graph description
        if (preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
            $description = $this->cleanText($matches[1]);
            if (!empty($description)) {
                return $description;
            }
        }
        
        // Extract from paragraphs
        if (preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $content, $matches)) {
            foreach ($matches[1] as $paragraph) {
                $text = $this->cleanText($paragraph);
                if (strlen($text) > 50 && strlen($text) < 500) {
                    return $text;
                }
            }
        }
        
        return '';
    }

    /**
     * Validate and clean extracted data
     */
    private function validateAndCleanData($data)
    {
        // Validate title
        if (empty($data['title'])) {
            $data['confidence'] -= 20;
        }
        
        // Validate date (required)
        if (empty($data['date_start'])) {
            $data['confidence'] -= 30;
        } else {
            // Boost confidence for dates in next 3 months (preferred range)
            $now = time();
            $three_months_from_now = strtotime('+3 months');
            
            if ($data['date_start'] >= $now && $data['date_start'] <= $three_months_from_now) {
                $data['confidence'] += 10; // Bonus for events within 3 months
            }
            // No penalty for other future dates - accept any reasonable future event
        }
        
        // Validate location (be lenient - don't penalize missing Dutch locations)
        if (!empty($data['place']) && Utils::isDutchLocation($data['place'])) {
            $data['confidence'] += 5; // Bonus for valid Dutch location
        }
        
        // Validate image
        if (!empty($data['image']) && !Utils::isValidImage($data['image'])) {
            $data['image'] = '';
        } else if (!empty($data['image'])) {
            $data['confidence'] += 5; // Bonus for valid image
        }
        
        // Clean text fields
        $text_fields = ['title', 'place', 'description'];
        foreach ($text_fields as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->cleanText($data[$field]);
            }
        }
        
        return $data;
    }

    /**
     * Clean text content
     */
    private function cleanText($text)
    {
        if (empty($text)) {
            return '';
        }
        
        // Remove HTML tags
        $text = strip_tags($text);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim
        $text = trim($text);
        
        return $this->stripJsonNoise($text);
    }

    /**
     * Clean URL
     */
    private function cleanUrl($url)
    {
        if (empty($url)) {
            return '';
        }
        
        // Remove whitespace
        $url = trim($url);
        
        // Decode HTML entities
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $url;
    }

    /**
     * Find a meaningful context wrapper for a listing entry
     */
    private function findListingContextNode(\DOMNode $node)
    {
        $current = $node;
        $levels = 0;
        while ($current && $levels < 6) {
            if ($current instanceof \DOMElement) {
                $class = $current->getAttribute('class');
                if ($class && preg_match('/(item|card|row|entry|event)/i', $class)) {
                    return $current;
                }
            }
            $current = $current->parentNode;
            $levels++;
        }
        return $node->parentNode instanceof \DOMElement ? $node->parentNode : $node;
    }

    /**
     * Get outer HTML of a node
     */
    private function nodeOuterHtml(\DOMNode $node)
    {
        if (!$node) {
            return '';
        }
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        $import = $dom->importNode($node, true);
        $dom->appendChild($import);
        return $dom->saveHTML();
    }

    /**
     * Remove trailing JSON/config blobs that sometimes leak into text content.
     */
    private function stripJsonNoise($text)
    {
        if ($text === '') {
            return '';
        }
        
        $markers = [
            'Static.COOKIE_BANNER_CAPABLE',
            'function(){',
            '{"@context"',
            '"invalid',
            ',"containsInvalidKey"',
        ];
        
        foreach ($markers as $marker) {
            $pos = strpos($text, $marker);
            if ($pos !== false) {
                $text = substr($text, 0, $pos);
            }
        }
        
        // Generic JSON blobs
        if (strpos($text, '{"') !== false) {
            $text = substr($text, 0, strpos($text, '{"'));
        }
        
        // Remove leftover braces/quotes-heavy fragments
        if (preg_match('/\{.+\}/', $text)) {
            $text = preg_replace('/\{.*$/', '', $text);
        }
        
        // Specific Squarespace validation snippet
        $text = preg_replace('/de mag niet langer zijn dan \{0\}.*$/i', '', $text);
        
        return trim($text);
    }

    /**
     * Remove events that clearly ended far in the past.
     *
     * Accepts events that are upcoming, currently ongoing (end in the future),
     * or those without date data (so later stages can decide).
     */
    private function pruneOutdatedEvents(array $events)
    {
        $now = time();
        $cutoffPast = strtotime('-14 days', $now);
        $filtered = [];
        
        foreach ($events as $event) {
            $start = $event['date_start'] ?? null;
            $end = $event['date_end'] ?? null;
            
            $ongoing = !empty($end) && $end >= $now;
            $upcoming = !empty($start) && $start >= $cutoffPast;
            
            if ($ongoing || $upcoming || (empty($start) && empty($end))) {
                $filtered[] = $event;
            }
        }
        
        return $filtered;
    }

    /**
     * Make absolute URL
     */
    private function makeAbsoluteUrl($url, $base_url)
    {
        if (empty($url)) {
            return '';
        }
        
        // Already absolute
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }
        
        $parsed_base = parse_url($base_url);
        if (!$parsed_base) {
            return $url;
        }
        
        $base = $parsed_base['scheme'] . '://' . $parsed_base['host'];
        if (isset($parsed_base['port'])) {
            $base .= ':' . $parsed_base['port'];
        }
        
        // Relative URL starting with /
        if (strpos($url, '/') === 0) {
            return $base . $url;
        }
        
        // Relative URL
        $base_path = isset($parsed_base['path']) ? dirname($parsed_base['path']) : '';
        if ($base_path === '/') {
            $base_path = '';
        }
        
        return $base . $base_path . '/' . $url;
    }

    /**
     * Check if event data is valid for import
     */
    public function isValidEvent($event_data)
    {
        $url = $event_data['url'] ?? 'unknown';
        
        // Must have title
        if (empty($event_data['title'])) {
            Utils::log("Invalid event at {$url}: Missing title", 'debug');
            return false;
        }
        
        // Must have start date
        if (empty($event_data['date_start'])) {
            Utils::log("Invalid event '{$event_data['title']}' at {$url}: Missing date_start", 'debug');
            return false;
        }
        
        // Check if date is reasonable (within 1 year from now)
        $now = time();
        $grace_period = strtotime('-3 days'); // allow slight overlap for ongoing events
        $one_year_from_now = strtotime('+1 year');
        
        $event_end = $event_data['date_end'] ?? null;
        
        // Event ended in the past
        if (!empty($event_end) && $event_end < $now) {
            Utils::log("Invalid event '{$event_data['title']}' at {$url}: Event already ended (" . date('Y-m-d', $event_end) . ")", 'debug');
            return false;
        }
        
        // Event start in the distant past and no future end
        if ($event_data['date_start'] < $grace_period && (empty($event_end) || $event_end < $now)) {
            Utils::log("Invalid event '{$event_data['title']}' at {$url}: Date is in the past (" . date('Y-m-d', $event_data['date_start']) . ")", 'debug');
            return false;
        }
        
        if ($event_data['date_start'] > $one_year_from_now) {
            Utils::log("Invalid event '{$event_data['title']}' at {$url}: Date is too far in future (" . date('Y-m-d', $event_data['date_start']) . ")", 'debug');
            return false;
        }
        
        // Accept any reasonable future or ongoing event
        Utils::log("Event '{$event_data['title']}' at {$url}: Valid timing (start " . date('Y-m-d', $event_data['date_start']) . ", end " . (!empty($event_end) ? date('Y-m-d', $event_end) : 'n/a') . "), confidence: {$event_data['confidence']}", 'debug');
        
        // Location validation is very lenient - we accept any location
        // We only log if it's not recognized, but don't reject
        if (!empty($event_data['place'])) {
            if (Utils::isDutchLocation($event_data['place'])) {
                Utils::log("Event '{$event_data['title']}' has recognized Dutch location: {$event_data['place']}", 'debug');
            } else {
                Utils::log("Event '{$event_data['title']}' has unrecognized location: {$event_data['place']} (accepting anyway)", 'debug');
            }
        }
        
        // Must have reasonable confidence (lowered significantly for testing)
        // With new bonus system, events with title + date will have ~30-50 confidence
        if ($event_data['confidence'] < -10) {
            Utils::log("Invalid event '{$event_data['title']}' at {$url}: Confidence too low ({$event_data['confidence']})", 'debug');
            return false;
        }
        
        Utils::log("✅ Valid event '{$event_data['title']}' at {$url}: Passed all checks!", 'debug');
        return true;
    }

    /**
     * Calculate event confidence score
     */
    public function calculateConfidence($event_data)
    {
        $confidence = 0;
        
        // Title present
        if (!empty($event_data['title'])) {
            $confidence += 20;
        }
        
        // Date present
        if (!empty($event_data['date_start'])) {
            $confidence += 30;
        }
        
        // Time present
        if (!empty($event_data['time'])) {
            $confidence += 10;
        }
        
        // Location present and Dutch
        if (!empty($event_data['place']) && Utils::isDutchLocation($event_data['place'])) {
            $confidence += 20;
        }
        
        // Image present
        if (!empty($event_data['image'])) {
            $confidence += 10;
        }
        
        // Description present
        if (!empty($event_data['description'])) {
            $confidence += 10;
        }
        
        return $confidence;
    }
}

