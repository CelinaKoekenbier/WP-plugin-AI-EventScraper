<?php

namespace ApifyEvents;

/**
 * Event description paraphraser (English output).
 *
 * Converts raw descriptions into short human-readable summaries
 * (≤120 words) through a deterministic set of replacements.
 *
 * Features:
 * - Synonym swapping to avoid duplicate content.
 * - Boilerplate removal and sentence reordering.
 * - Filter (`apify_events_paraphrase`) allowing third-party overrides.
 *
 * Called from `Importer::createPost` before content is saved.
 */
class Paraphraser
{
    /**
     * Synonyms and phrase replacements (primarily Dutch → used to clean NL sources).
     */
    private static $synonyms = [
        'evenement' => ['activiteit', 'bijeenkomst', 'gebeurtenis', 'event'],
        'workshop' => ['cursus', 'training', 'sessie', 'bijeenkomst'],
        'lezing' => ['presentatie', 'voordracht', 'spreker', 'lezing'],
        'festival' => ['feest', 'festiviteit', 'viering', 'evenement'],
        'beurs' => ['expositie', 'tentoonstelling', 'fair', 'markt'],
        'duurzaam' => ['milieuvriendelijk', 'ecologisch', 'groen', 'verantwoord'],
        'natuur' => ['milieu', 'ecologie', 'biodiversiteit', 'groen'],
        'biologisch' => ['eco', 'natuurlijk', 'organisch', 'duurzaam'],
        'planten' => ['flora', 'vegetatie', 'groen', 'natuur'],
        'biodiversiteit' => ['soortenrijkdom', 'natuurlijke diversiteit', 'ecosysteem'],
        'locatie' => ['plaats', 'adres', 'locatie', 'waar'],
        'datum' => ['dag', 'datum', 'wanneer', 'tijd'],
        'tijd' => ['uur', 'tijdstip', 'wanneer', 'klok'],
        'kosten' => ['prijs', 'tarief', 'kosten', 'bijdrage'],
        'aanmelden' => ['inschrijven', 'registreren', 'aanmelden', 'opgeven'],
        'informatie' => ['details', 'informatie', 'meer weten', 'meer info'],
        'organisatie' => ['organisator', 'organisatie', 'stichting', 'vereniging'],
        'spreker' => ['presentator', 'spreker', 'expert', 'docent'],
        'deelnemers' => ['bezoekers', 'deelnemers', 'gasten', 'publiek'],
        'programma' => ['agenda', 'programma', 'rooster', 'planning'],
    ];

    /**
     * Boilerplate phrases to remove
     */
    private static $boilerplate_patterns = [
        '/lees meer/i',
        '/meer informatie/i',
        '/klik hier/i',
        '/bekijk hier/i',
        '/ga naar/i',
        '/cookie/i',
        '/privacy/i',
        '/disclaimer/i',
        '/algemene voorwaarden/i',
        '/contact/i',
        '/over ons/i',
        '/home/i',
        '/terug naar/i',
        '/naar boven/i',
        '/social media/i',
        '/volg ons/i',
        '/like ons/i',
        '/deel dit/i',
        '/ticket/i',
        '/koop nu/i',
        '/bestel nu/i',
        '/aanmelden/i',
        '/inschrijven/i',
        '/registreren/i',
        '/login/i',
        '/inloggen/i',
        '/menu/i',
        '/navigatie/i',
        '/zoeken/i',
        '/zoek/i',
        '/filter/i',
        '/sorteer/i',
        '/pagina/i',
        '/volgende/i',
        '/vorige/i',
        '/vorige pagina/i',
        '/volgende pagina/i',
    ];

    /**
     * Sentence starters for variety (Dutch)
     */
    private static $sentence_starters = [
        'Dit evenement',
        'Deze activiteit',
        'Deze bijeenkomst',
        'Deze workshop',
        'Deze lezing',
        'Dit festival',
        'Deze cursus',
        'Deze sessie',
        'Deze presentatie',
    ];

    /**
     * Paraphrase text to English, max 120 words
     */
    public function paraphrase($text, $event_data = [])
    {
        if (empty($text)) {
            return $this->generateFallbackDescription($event_data);
        }

        // Clean and prepare text
        $text = $this->cleanText($text);
        
        // Remove boilerplate
        $text = $this->removeBoilerplate($text);
        
        // Extract key information
        $key_info = $this->extractKeyInformation($text, $event_data);
        
        // Generate paraphrase
        $paraphrase = $this->generateParaphrase($key_info, $event_data);
        
        // Ensure it's under 120 words
        $paraphrase = $this->limitWords($paraphrase, 120);
        
        // Add source link
        $paraphrase = $this->addSourceLink($paraphrase, $event_data['url'] ?? '');
        
        return $paraphrase;
    }

    /**
     * Clean text content
     */
    private function cleanText($text)
    {
        // Remove HTML tags
        $text = strip_tags($text);
        
        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim
        $text = trim($text);
        
        return $text;
    }

    /**
     * Remove boilerplate content
     */
    private function removeBoilerplate($text)
    {
        foreach (self::$boilerplate_patterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }
        
        // Remove sentences that are too short or too long
        $sentences = preg_split('/[.!?]+/', $text);
        $filtered_sentences = [];
        
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 10 && strlen($sentence) < 200) {
                $filtered_sentences[] = $sentence;
            }
        }
        
        return implode('. ', $filtered_sentences);
    }

    /**
     * Extract key information from text
     */
    private function extractKeyInformation($text, $event_data)
    {
        $key_info = [
            'title' => $event_data['title'] ?? '',
            'date' => $event_data['date_start'] ?? null,
            'time' => $event_data['time'] ?? null,
            'place' => $event_data['place'] ?? '',
            'description' => $text,
        ];
        
        // Extract key facts
        $facts = [];
        
        // Look for specific information patterns
        if (preg_match('/(\d+)\s+(deelnemers?|bezoekers?|gasten?)/i', $text, $matches)) {
            $facts['participants'] = $matches[1] . ' ' . $matches[2];
        }
        
        if (preg_match('/(gratis|vrijwillige bijdrage|donatie)/i', $text)) {
            $facts['cost'] = 'free';
        } elseif (preg_match('/(€\s*\d+)/i', $text, $matches)) {
            $facts['cost'] = $matches[1];
        }
        
        if (preg_match('/(aanmelden|inschrijven|registreren)/i', $text)) {
            $facts['registration'] = 'inschrijving vereist';
        }
        
        if (preg_match('/(workshop|cursus|training)/i', $text)) {
            $facts['type'] = 'workshop';
        } elseif (preg_match('/(lezing|presentatie|voordracht)/i', $text)) {
            $facts['type'] = 'talk';
        } elseif (preg_match('/(festival|feest)/i', $text)) {
            $facts['type'] = 'festival';
        }
        
        $key_info['facts'] = $facts;
        
        return $key_info;
    }

    /**
     * Generate paraphrase from key information
     */
    private function generateParaphrase($key_info, $event_data)
    {
        $sentences = [];
        
        // Start with event type and basic info
        $starter = $this->getRandomSentenceStarter();
        $title = $key_info['title'];
        
        if (!empty($key_info['facts']['type'])) {
            $type = $key_info['facts']['type'];
            // Minimal mapping from internal type -> Dutch.
            $type_nl = $type;
            if ($type === 'talk') {
                $type_nl = 'lezing';
            } elseif ($type === 'festival') {
                $type_nl = 'festival';
            } elseif ($type === 'workshop') {
                $type_nl = 'workshop';
            }
            $sentences[] = "{$starter} '{$title}' is een {$type_nl}";
        } else {
            $sentences[] = "{$starter} '{$title}' vindt plaats";
        }
        
        // Add date and time info
        if ($key_info['date']) {
            $date_str = $this->formatDate($key_info['date']);
            if ($key_info['time']) {
                $sentences[] = "Het vindt plaats op {$date_str} om {$key_info['time']}";
            } else {
                $sentences[] = "Het vindt plaats op {$date_str}";
            }
        }
        
        // Add location
        if (!empty($key_info['place'])) {
            $sentences[] = "Locatie: {$key_info['place']}";
        }
        
        // Add description content
        $description = $this->paraphraseDescription($key_info['description']);
        if (!empty($description)) {
            $sentences[] = $description;
        }
        
        // Add additional facts
        if (!empty($key_info['facts']['cost'])) {
            $sentences[] = "Kosten: {$key_info['facts']['cost']}";
        }
        
        if (!empty($key_info['facts']['participants'])) {
            $sentences[] = "Capaciteit: {$key_info['facts']['participants']}";
        }
        
        if (!empty($key_info['facts']['registration'])) {
            $sentences[] = ucfirst($key_info['facts']['registration']);
        }
        
        return implode('. ', $sentences) . '.';
    }

    /**
     * Paraphrase description text
     */
    private function paraphraseDescription($description)
    {
        if (empty($description)) {
            return '';
        }
        
        // Split into sentences
        $sentences = preg_split('/[.!?]+/', $description);
        $sentences = array_filter(array_map('trim', $sentences));
        
        // Take first 2-3 meaningful sentences
        $selected_sentences = array_slice($sentences, 0, 3);
        
        // Apply synonym replacement
        $paraphrased = [];
        foreach ($selected_sentences as $sentence) {
            $paraphrased[] = $this->replaceSynonyms($sentence);
        }
        
        return implode(' ', $paraphrased);
    }

    /**
     * Replace words with synonyms
     */
    private function replaceSynonyms($text)
    {
        $words = explode(' ', $text);
        $replaced_words = [];
        
        foreach ($words as $word) {
            $lower_word = strtolower($word);
            
            if (isset(self::$synonyms[$lower_word])) {
                $synonyms = self::$synonyms[$lower_word];
                $replaced_words[] = $synonyms[array_rand($synonyms)];
            } else {
                $replaced_words[] = $word;
            }
        }
        
        return implode(' ', $replaced_words);
    }

    /**
     * Get random sentence starter
     */
    private function getRandomSentenceStarter()
    {
        return self::$sentence_starters[array_rand(self::$sentence_starters)];
    }

    /**
     * Format date for Dutch text
     */
    private function formatDate($timestamp)
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
     * Limit text to maximum number of words
     */
    private function limitWords($text, $max_words)
    {
        $words = explode(' ', $text);
        
        if (count($words) <= $max_words) {
            return $text;
        }
        
        $limited_words = array_slice($words, 0, $max_words);
        return implode(' ', $limited_words);
    }

    /**
     * Add source link
     */
    private function addSourceLink($text, $url)
    {
        if (empty($url)) {
            return $text;
        }
        
        $link_text = "Meer info: {$url}";
        return $text . ' ' . $link_text;
    }

    /**
     * Generate fallback description when no text available
     */
    private function generateFallbackDescription($event_data)
    {
        $sentences = [];
        
        $title = $event_data['title'] ?? 'Dit evenement';
        $sentences[] = "{$title} vindt plaats";
        
        if (!empty($event_data['date_start'])) {
            $date_str = $this->formatDate($event_data['date_start']);
            $sentences[] = "Het vindt plaats op {$date_str}";
        }
        
        if (!empty($event_data['time'])) {
            $sentences[] = "Starttijd: {$event_data['time']}";
        }
        
        if (!empty($event_data['place'])) {
            $sentences[] = "Locatie: {$event_data['place']}";
        }
        
        $description = implode('. ', $sentences) . '.';
        
        if (!empty($event_data['url'])) {
            $description = $this->addSourceLink($description, $event_data['url']);
        }
        
        return $description;
    }

    /**
     * Apply filters for custom paraphrasing
     */
    public function applyFilters($text, $event_data)
    {
        // Allow plugins to override paraphrasing
        $filtered_text = apply_filters('apify_events_paraphrase', $text, $event_data);
        
        if ($filtered_text !== $text) {
            return $filtered_text;
        }
        
        return $this->paraphrase($text, $event_data);
    }
}
