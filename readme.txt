=== Apify Events to Posts ===
Contributors: your-username
Tags: events, apify, automation, dutch, posts, cron
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Discovers real Dutch events monthly using Apify and saves them as Draft WordPress posts.

== Description ==

Apify Events to Posts is a powerful WordPress plugin that automatically discovers Dutch events using the Apify platform and saves them as draft posts in your WordPress site. The plugin runs monthly via WP-Cron and creates 3-10 high-quality event posts per run.

=== Code Map ===

Quick reference for the source tree:

* `apify-events-to-posts.php` – bootstrap & autoloader.
* `src/Plugin.php` – main orchestrator (admin, cron, AJAX, activation hooks).
* `src/Settings.php` – settings page renderer + last-run log.
* `src/Runner.php` – free/Apify pipelines, stats, import orchestration.
* `src/FreeSearchClient.php` – Google Custom Search + manual URLs + RSS.
* `src/ApifyClient.php` – wrappers around the Apify REST API.
* `src/Extractor.php` – schema/heuristic parsing, listing fallbacks.
* `src/Importer.php` – post/media/taxonomy/meta creation.
* `src/Paraphraser.php` – deterministic Dutch paraphrasing utilities.
* `src/Utils.php` – shared helpers (options, logging, hashing, cron).
* `assets/admin.js` / `assets/admin.css` – settings UI behaviour and styling.

**Key Features:**

* **Automatic Discovery**: Uses Apify Google Search Scraper to find Dutch event URLs
* **Smart Extraction**: Fetches and parses event pages using schema.org and heuristics
* **Dutch Focus**: Specifically targets Netherlands (.nl domains) and Dutch content
* **Quality Control**: Validates events, removes duplicates, and ensures quality
* **Scheduled Runs**: Automatically runs on the 15th of each month at 15:00 Europe/Amsterdam
* **Manual Control**: Run discovery manually from the admin settings page
* **Rich Content**: Creates posts with featured images, structured data, and Dutch descriptions
* **Deduplication**: Prevents duplicate events using URL hashing and content matching

**What the Plugin Does:**

1. **Search Phase**: Uses Apify to search for Dutch events for the next calendar month
2. **Fetch Phase**: Downloads and analyzes candidate event pages
3. **Extract Phase**: Parses event data using schema.org JSON-LD and heuristics
4. **Import Phase**: Creates draft WordPress posts with proper taxonomies and meta data
5. **Quality Control**: Ensures 3-10 valid events per run with confidence scoring

**Post Structure:**

Each imported event becomes a draft post with:
* **Title**: Event name
* **Content**: Structured HTML with date, time, location, and source link
* **Featured Image**: Downloaded from event page with proper alt text
* **Category**: "Evenementen" (created automatically)
* **Tag**: "Apify import"
* **Meta Data**: Source URL, event dates, location, and description

**Settings & Configuration:**

* Apify API token configuration
* Customizable search queries with month/year placeholders
* Language and country targeting
* Domain exclusions
* Image quality requirements
* Test mode for safe testing

**Requirements:**

* WordPress 5.0 or higher
* PHP 7.4 or higher
* Valid Apify API token
* Internet connection for API calls

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/apify-events-to-posts` directory
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Apify Events to configure your Apify token
4. Set up your search queries and preferences
5. The plugin will automatically schedule monthly runs

== Frequently Asked Questions ==

= Do I need an Apify account? =

Yes, you need a valid Apify API token to use this plugin. Sign up at apify.com and get your token from the account settings.

= How often does the plugin run? =

The plugin runs automatically on the 15th of each month at 15:00 Europe/Amsterdam time. You can also run it manually from the settings page.

= What types of events does it find? =

The plugin searches for Dutch events related to nature, sustainability, biodiversity, organic farming, and plants. You can customize the search queries in the settings.

= How many events will be imported? =

The plugin aims to import 3-10 events per run. It uses confidence scoring to select the best events and avoids duplicates.

= Can I customize the search queries? =

Yes, you can edit the search queries in the settings page. Use the `<VOLGEND_MAAND_JAAR>` placeholder for the next month/year.

= What if I don't want certain domains? =

You can exclude domains in the settings page by entering them as a comma-separated list.

= Are the posts published immediately? =

No, all imported events are saved as draft posts. You can review and publish them manually.

= Can I run the plugin manually? =

Yes, use the "Run Now" button in the settings page to trigger an immediate discovery run.

== Screenshots ==

1. Settings page with configuration options
2. Manual run interface with progress logging
3. Example of imported event post
4. Run statistics and logs

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic Dutch event discovery using Apify
* Monthly scheduled runs via WP-Cron
* Manual run capability
* Rich post creation with images and structured data
* Comprehensive settings page
* Deduplication and quality control
* Dutch language support

== Upgrade Notice ==

= 1.0.0 =
Initial release of Apify Events to Posts plugin.

== Technical Details ==

**Architecture:**
* PSR-4 autoloaded classes
* Namespaced code (`ApifyEvents\`)
* WordPress hooks and filters
* Secure AJAX handling with nonces
* Comprehensive error handling and logging

**API Integration:**
* Apify Google Search Scraper for URL discovery
* Apify Website Content Crawler for page analysis
* Fallback to Apify Web Scraper if needed
* Rate limiting and error handling

**Data Processing:**
* Schema.org JSON-LD parsing
* Heuristic text extraction
* Dutch date/time parsing
* Location validation
* Image processing and validation
* Content paraphrasing

**WordPress Integration:**
* Custom post meta for event data
* Automatic taxonomy assignment
* Media library integration
* WP-Cron scheduling
* Admin interface with AJAX

**Security:**
* Input sanitization and validation
* Capability checks (`manage_options`)
* Nonce verification
* SQL injection prevention
* XSS protection

**Performance:**
* Efficient database queries
* Image optimization
* Caching where appropriate
* Background processing
* Resource limits

== Support ==

For support, feature requests, or bug reports, please visit the plugin's GitHub repository or contact the developer.

== Privacy Policy ==

This plugin:
* Does not collect personal data
* Only processes publicly available event information
* Stores data locally in your WordPress database
* Uses Apify services for web scraping (subject to Apify's privacy policy)
* Logs processing information for debugging purposes

== License ==

This plugin is licensed under the GPL v2 or later.

== Credits ==

* Built for Dutch event discovery
* Uses Apify platform for web scraping
* Integrates with WordPress core functionality
* Follows WordPress coding standards
* Implements security best practices
