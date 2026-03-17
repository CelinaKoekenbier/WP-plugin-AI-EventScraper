<?php

namespace ApifyEvents;

/**
 * Event data importer.
 *
 * Converts validated event payloads into WordPress draft posts. Handles:
 * - Duplicate detection (source URL hash + normalised title/date combo).
 * - Post creation and HTML content assembly (facts block + description).
 * - Featured image sideloading, media alt text, taxonomy assignment.
 * - Custom post meta used for dedupe and downstream integrations.
 *
 * Infrastructure helpers:
 * - `Paraphraser` to summarise/clean descriptions.
 * - `Utils` for hashing, logging and canonical URL helpers.
 */
class Importer
{
    /**
     * Import event as WordPress post
     */
    public function importEvent($event_data)
    {
        // Check for duplicates
        if ($this->isDuplicate($event_data)) {
            return [
                'success' => false,
                'reason' => 'duplicate',
                'message' => 'Event already exists'
            ];
        }

        // Apply filters
        $event_data = apply_filters('apify_events_before_create_post', $event_data);
        
        if (empty($event_data)) {
            return [
                'success' => false,
                'reason' => 'filtered',
                'message' => 'Event filtered out'
            ];
        }

        // Create post
        $post_id = $this->createPost($event_data);
        
        if (!$post_id) {
            return [
                'success' => false,
                'reason' => 'post_creation_failed',
                'message' => 'Failed to create post'
            ];
        }

        // Set featured image
        if (!empty($event_data['image'])) {
            $this->setFeaturedImage($post_id, $event_data['image'], $event_data['title'], $event_data['url']);
        }

        // Set taxonomies
        $this->setTaxonomies($post_id);

        // Set post meta
        $this->setPostMeta($post_id, $event_data);

        // Fire action hook
        do_action('apify_events_after_create_post', $post_id, $event_data);

        return [
            'success' => true,
            'post_id' => $post_id,
            'message' => 'Event imported successfully'
        ];
    }

    /**
     * Check if event is duplicate
     */
    private function isDuplicate($event_data)
    {
        $canonical_url = Utils::getCanonicalUrl($event_data['url']);
        $url_hash = Utils::generateHash($canonical_url);
        
        // Check by URL hash
        $existing_posts = get_posts([
            'meta_query' => [
                [
                    'key' => 'apify_source_hash',
                    'value' => $url_hash,
                    'compare' => '='
                ]
            ],
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
        ]);
        
        if (!empty($existing_posts)) {
            return true;
        }
        
        // Check by normalized title + date
        if (!empty($event_data['title']) && !empty($event_data['date_start'])) {
            $normalized_title = Utils::normalizeTitle($event_data['title']);
            $date_start = $event_data['date_start'];
            
            $existing_posts = get_posts([
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'event_date_start',
                        'value' => $date_start,
                        'compare' => '='
                    ]
                ],
                'post_type' => 'post',
                'post_status' => 'any',
                'numberposts' => 10,
            ]);
            
            foreach ($existing_posts as $post) {
                $post_title_normalized = Utils::normalizeTitle($post->post_title);
                if ($post_title_normalized === $normalized_title) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Create WordPress post
     */
    private function createPost($event_data)
    {
        $paraphraser = new Paraphraser();
        $description = $paraphraser->applyFilters($event_data['description'] ?? '', $event_data);
        
        // Build post content
        $content = $this->buildPostContent($event_data, $description);
        
        // Use site timezone so "Last modified" matches when the import ran.
        // If you see a 1-hour difference, set Settings → General → Timezone to "Europe/Amsterdam".
        $wp_tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('Europe/Amsterdam');
        $now_utc = new \DateTime('now', new \DateTimeZone('UTC'));
        $now_local = clone $now_utc;
        $now_local->setTimezone($wp_tz);
        $post_date_gmt = $now_utc->format('Y-m-d H:i:s');
        $post_date_local = $now_local->format('Y-m-d H:i:s');
        
        $post_data = [
            'post_title' => $event_data['title'],
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => 1, // Admin user
            'post_date' => $post_date_local,
            'post_date_gmt' => $post_date_gmt,
            'meta_input' => [
                'apify_source_url' => $event_data['url'],
                'apify_source_hash' => Utils::generateHash(Utils::getCanonicalUrl($event_data['url'])),
            ]
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            Utils::log('Failed to create post: ' . $post_id->get_error_message(), 'error');
            return false;
        }
        
        return $post_id;
    }

    /**
     * Build post content HTML
     */
    private function buildPostContent($event_data, $description)
    {
        $content = '<div class="apify-event-info">' . "\n";
        $content .= '<dl>' . "\n";
        
        // Date
        if (!empty($event_data['date_start'])) {
            $date_str = $this->formatDateForContent($event_data['date_start']);
            $content .= '<dt>Datum:</dt><dd>' . esc_html($date_str) . '</dd>' . "\n";
        }
        
        // Time
        if (!empty($event_data['time'])) {
            $content .= '<dt>Tijd:</dt><dd>' . esc_html($event_data['time']) . '</dd>' . "\n";
        }
        
        // Place
        if (!empty($event_data['place'])) {
            $content .= '<dt>Plaats:</dt><dd>' . esc_html($event_data['place']) . '</dd>' . "\n";
        }
        
        // Source
        if (!empty($event_data['url'])) {
            $content .= '<dt>Bron:</dt><dd><a href="' . esc_url($event_data['url']) . '" rel="nofollow noopener" target="_blank">' . esc_html($event_data['url']) . '</a></dd>' . "\n";
        }
        
        $content .= '</dl>' . "\n";
        $content .= '</div>' . "\n\n";
        
        // Description
        if (!empty($description)) {
            $content .= '<div class="apify-event-description">' . "\n";
            $content .= wp_kses_post($description);
            $content .= '</div>' . "\n";
        }
        
        return $content;
    }

    /**
     * Format date for content display
     */
    private function formatDateForContent($timestamp)
    {
        $timezone = new \DateTimeZone('Europe/Amsterdam');
        $date = new \DateTime('@' . $timestamp, $timezone);
        
        $day = $date->format('j');
        $month = $date->format('n');
        $year = $date->format('Y');
        
        $dutch_months = [
            1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april',
            5 => 'mei', 6 => 'juni', 7 => 'juli', 8 => 'augustus',
            9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december'
        ];
        
        return "{$day} {$dutch_months[$month]} {$year}";
    }

    /**
     * Set featured image
     */
    private function setFeaturedImage($post_id, $image_url, $title, $source_url)
    {
        if (empty($image_url) || !Utils::isValidImage($image_url)) {
            return false;
        }
        
        // Download image
        $attachment_id = $this->downloadImage($image_url, $title);
        
        if (!$attachment_id) {
            return false;
        }
        
        // Set as featured image
        $result = set_post_thumbnail($post_id, $attachment_id);
        
        if ($result) {
            // Set alt text
            $alt_text = "foto van {$title}. Voor meer informatie: {$source_url}";
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            
            return $attachment_id;
        }
        
        return false;
    }

    /**
     * Download image to media library
     */
    private function downloadImage($image_url, $title)
    {
        // Check if image already exists
        $existing_attachment = $this->getExistingAttachment($image_url);
        if ($existing_attachment) {
            return $existing_attachment;
        }
        
        // Download image
        $file_array = [];
        $file_array['name'] = sanitize_file_name(basename($image_url));
        
        // Add file extension if missing
        $path_info = pathinfo($file_array['name']);
        if (empty($path_info['extension'])) {
            $file_array['name'] .= '.jpg';
        }
        
        $file_array['tmp_name'] = download_url($image_url);
        
        if (is_wp_error($file_array['tmp_name'])) {
            Utils::log('Failed to download image: ' . $file_array['tmp_name']->get_error_message(), 'error');
            return false;
        }
        
        // Validate image
        $image_info = getimagesize($file_array['tmp_name']);
        if (!$image_info) {
            Utils::log('Invalid image file: ' . $image_url, 'error');
            unlink($file_array['tmp_name']);
            return false;
        }
        
        $rules = Utils::getImageRules();
        if ($image_info[0] < $rules['min_width'] || $image_info[1] < $rules['min_height']) {
            Utils::log('Image too small: ' . $image_url . ' (' . $image_info[0] . 'x' . $image_info[1] . ')', 'error');
            unlink($file_array['tmp_name']);
            return false;
        }
        
        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, 0, $title);
        
        if (is_wp_error($attachment_id)) {
            Utils::log('Failed to upload image: ' . $attachment_id->get_error_message(), 'error');
            unlink($file_array['tmp_name']);
            return false;
        }
        
        // Store original URL for deduplication
        update_post_meta($attachment_id, 'apify_original_url', $image_url);
        
        return $attachment_id;
    }

    /**
     * Check if image already exists in media library
     */
    private function getExistingAttachment($image_url)
    {
        $attachments = get_posts([
            'meta_query' => [
                [
                    'key' => 'apify_original_url',
                    'value' => $image_url,
                    'compare' => '='
                ]
            ],
            'post_type' => 'attachment',
            'post_status' => 'any',
            'numberposts' => 1,
        ]);
        
        return !empty($attachments) ? $attachments[0]->ID : false;
    }

    /**
     * Set taxonomies (category and tags)
     */
    private function setTaxonomies($post_id)
    {
        // Set category "Evenementen"
        $category = get_category_by_slug('evenementen');
        if (!$category) {
            $category_id = wp_create_category('Evenementen');
        } else {
            $category_id = $category->term_id;
        }
        
        if ($category_id) {
            wp_set_post_categories($post_id, [$category_id]);
        }
        
        // Set tag "Apify import"
        $tag = get_term_by('name', 'Apify import', 'post_tag');
        if (!$tag) {
            $tag_result = wp_insert_term('Apify import', 'post_tag');
            if (!is_wp_error($tag_result)) {
                $tag_id = $tag_result['term_id'];
            }
        } else {
            $tag_id = $tag->term_id;
        }
        
        if (isset($tag_id)) {
            wp_set_post_tags($post_id, [$tag_id], false);
        }
    }

    /**
     * Set post meta data
     */
    private function setPostMeta($post_id, $event_data)
    {
        $meta_data = [
            'apify_source_url' => $event_data['url'],
            'apify_source_hash' => Utils::generateHash(Utils::getCanonicalUrl($event_data['url'])),
            'event_date_start' => $event_data['date_start'],
            'event_place' => $event_data['place'] ?? '',
            'event_desc_short' => $event_data['description'] ?? '',
        ];
        
        if (!empty($event_data['date_end'])) {
            $meta_data['event_date_end'] = $event_data['date_end'];
        }
        
        if (!empty($event_data['time'])) {
            $meta_data['event_time_str'] = $event_data['time'];
        }
        
        foreach ($meta_data as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
    }

    /**
     * Get import statistics
     */
    public function getImportStats()
    {
        $stats = [
            'total_imported' => 0,
            'this_month' => 0,
            'last_import' => null,
        ];
        
        // Count total imported posts
        $total_posts = get_posts([
            'meta_query' => [
                [
                    'key' => 'apify_source_hash',
                    'compare' => 'EXISTS'
                ]
            ],
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => -1,
        ]);
        
        $stats['total_imported'] = count($total_posts);
        
        // Count this month
        $this_month_start = strtotime('first day of this month');
        $this_month_end = strtotime('last day of this month');
        
        $this_month_posts = get_posts([
            'meta_query' => [
                [
                    'key' => 'apify_source_hash',
                    'compare' => 'EXISTS'
                ],
                [
                    'key' => 'event_date_start',
                    'value' => [$this_month_start, $this_month_end],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC'
                ]
            ],
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => -1,
        ]);
        
        $stats['this_month'] = count($this_month_posts);
        
        // Get last import date
        $last_run = get_option('apify_events_last_run', 0);
        if ($last_run) {
            $stats['last_import'] = $last_run;
        }
        
        return $stats;
    }

    /**
     * Clean up old imports (optional maintenance)
     */
    public function cleanupOldImports($days_old = 90)
    {
        $cutoff_date = time() - ($days_old * DAY_IN_SECONDS);
        
        $old_posts = get_posts([
            'meta_query' => [
                [
                    'key' => 'apify_source_hash',
                    'compare' => 'EXISTS'
                ],
                [
                    'key' => 'event_date_start',
                    'value' => $cutoff_date,
                    'compare' => '<',
                    'type' => 'NUMERIC'
                ]
            ],
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => -1,
        ]);
        
        $deleted_count = 0;
        foreach ($old_posts as $post) {
            if (wp_delete_post($post->ID, true)) {
                $deleted_count++;
            }
        }
        
        return $deleted_count;
    }
}
