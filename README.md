# Apify Events to Posts - Free Version

## 🎉 Completely FREE WordPress Plugin for Dutch Event Discovery

This plugin automatically discovers Dutch events and saves them as draft WordPress posts. **No paid subscriptions required!**

---

## 🆓 FREE Methods (Choose One or Combine Both)

### Method 1: Manual URLs (Easiest - Start Here!)

**Perfect for:** Testing, specific events, small-scale use

**How it works:**
1. Go to **Settings > Apify Events**
2. Scroll to **"Manual URLs (Free)"**
3. Add event URLs, one per line. For example:
```
https://www.natuurmonumenten.nl/agenda/natuur-wandeling-november
https://www.duurzaamheidsfestival.nl/evenementen/workshop-composteren
https://www.biologischvoedsel.nl/events/markten
```
4. Click **"Save Changes"**
5. Click **"Run Now"** to scrape and import events

**Benefits:**
- ✅ No API keys needed
- ✅ Works immediately
- ✅ Perfect for testing
- ✅ Control exactly which events to import

---

### Method 2: Google Custom Search API (Automated)

**Perfect for:** Automated discovery, large-scale use, hands-off operation

**How it works:**
1. Get a **free** Google Custom Search API key (100 searches/day free)
2. Create a Custom Search Engine
3. Configure in plugin settings
4. Plugin automatically discovers events monthly

**Setup Instructions:**

#### Step 1: Get Google API Key (5 minutes)
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select existing)
3. Enable "Custom Search API"
4. Go to **Credentials** > **Create Credentials** > **API Key**
5. Copy your API key

#### Step 2: Create Custom Search Engine (3 minutes)
1. Go to [Google Custom Search](https://cse.google.com/cse/all)
2. Click **"Add"** to create a new search engine
3. In "Sites to search", enter: `*.nl/*`
4. Click **"Create"**
5. Click **"Control Panel"** > **"Setup"**
6. Find your **Search engine ID** (long string like: `abc123def456...`)

#### Step 3: Configure Plugin
1. Go to **Settings > Apify Events** in WordPress
2. Enter **Google API Key**
3. Enter **Google CSE ID**
4. Click **"Save Changes"**
5. Click **"Run Now"** to test

**Benefits:**
- ✅ 100 free searches per day
- ✅ Automatic event discovery
- ✅ Monthly scheduled runs
- ✅ Finds events you might miss manually

**Free Tier Limits:**
- 100 API calls/day (plenty for monthly runs)
- No credit card required
- No automatic upgrades

---

## 📋 Quick Start Guide

### Option A: Test with Manual URLs (Recommended First Step)

1. **Install & Activate** the plugin
2. Go to **Settings > Apify Events**
3. Add a few event URLs to **"Manual URLs (Free)"**:
```
https://www.natuurmonumenten.nl/agenda/november
https://www.ivn.nl/agenda
```
4. Click **"Save Changes"**
5. Click **"Run Now"**
6. Check **Posts** to see imported draft events!

### Option B: Set Up Google Custom Search

1. Follow the setup instructions above
2. Configure API key and CSE ID in settings
3. Click **"Run Now"** to test
4. Plugin will run automatically on the 15th of each month

---

## ⚙️ Plugin Settings

### General Settings

**Search Queries**
- Customize what events to search for
- Uses placeholder `<VOLGEND_MAAND_JAAR>` (replaced with next month/year)
- Default queries focus on: natuur, duurzaamheid, biodiversiteit, biologisch, planten

**Manual URLs (Free Method)**
- Add specific event page URLs
- One URL per line
- No API needed
- Perfect for testing

**Google API Key (Free Method)**
- Your Google Custom Search API key
- Free: 100 searches/day
- [Get one here](https://developers.google.com/custom-search/v1/overview)

**Google CSE ID (Free Method)**
- Your Custom Search Engine ID
- [Create one here](https://cse.google.com/cse/all)

**Max Results per Query**
- Default: 20
- Controls how many results to process

**Excluded Domains**
- Comma-separated list of domains to skip
- Example: `facebook.com, eventbrite.com`

**Image Rules**
- Minimum width/height for featured images
- Default: 300x200 pixels

**Test Mode**
- Enable to test without creating posts
- Useful for debugging

---

## 🎯 How It Works

### 1. Discovery Phase
- **Manual URLs:** Uses your provided URLs directly
- **Google Custom Search:** Searches for Dutch events for next month

### 2. Scraping Phase
- Visits each URL
- Extracts HTML content
- Uses WordPress's built-in HTTP API (no external services)

### 3. Extraction Phase
- Looks for schema.org JSON-LD event data
- Falls back to heuristic extraction:
  - Finds dates (Dutch format supported)
  - Finds times (HH:MM format)
  - Finds locations (Dutch cities)
  - Finds images
  - Extracts descriptions

### 4. Import Phase
- Creates draft posts (3-10 per run)
- Downloads featured images
- Sets proper alt text
- Assigns category: "Evenementen"
- Adds tag: "Apify import"
- Stores metadata for deduplication

---

## 📝 Post Structure

Each imported event becomes a draft post with:

**Content:**
```html
<div class="apify-event-info">
  <dl>
    <dt>Datum:</dt><dd>15 november 2025</dd>
    <dt>Tijd:</dt><dd>19:30</dd>
    <dt>Plaats:</dt><dd>Amsterdam</dd>
    <dt>Bron:</dt><dd><a href="...">...</a></dd>
  </dl>
</div>

<div class="apify-event-description">
  [Paraphrased Dutch description ≤120 words]
  Meer info: [URL]
</div>
```

**Metadata:**
- `apify_source_url` - Original event URL
- `apify_source_hash` - For deduplication
- `event_date_start` - Event start date
- `event_time_str` - Event time
- `event_place` - Event location

**Featured Image:**
- Downloaded to Media Library
- Alt text: `foto van [title]. Voor meer informatie: [url]`

---

## 🔄 Automated Monthly Runs

The plugin automatically runs on:
- **Date:** 15th of each month
- **Time:** 15:00 Europe/Amsterdam
- **Target:** Events in the next month

**To manually trigger:**
1. Go to **Settings > Apify Events**
2. Click **"Run Now"** button
3. Watch the progress in real-time

---

## 🛠️ Troubleshooting

### "No candidate URLs found"
**Solution:** Add manual URLs or configure Google Custom Search API

### "Google API error 403"
**Solution:** Make sure Custom Search API is enabled in Google Cloud Console

### "No valid events found"
**Possible causes:**
- URLs don't contain events
- Events are not in next month
- Events don't have required date field

**Solution:** 
- Check that URLs actually have events
- Verify events are for next month
- Try different URLs

### Images not importing
**Possible causes:**
- Images too small (check Image Rules settings)
- Image URL invalid
- SSL certificate issues

**Solution:**
- Adjust minimum image size in settings
- Check if images are publicly accessible

---

## 💡 Tips & Best Practices

### For Manual URLs Method:
1. **Start small:** Add 3-5 URLs to test
2. **Use event calendar pages:** These usually have structured data
3. **Check date range:** Make sure events are in next month
4. **Diverse sources:** Use different websites for variety

### For Google Custom Search Method:
1. **Test first:** Use manual run before relying on automated runs
2. **Monitor quota:** You have 100 free searches/day
3. **Adjust queries:** Customize to find events you want
4. **Exclude domains:** Add unwanted domains to exclusion list

### General Tips:
1. **Review drafts:** Always review before publishing
2. **Check duplicates:** Plugin prevents duplicates, but double-check
3. **Adjust queries:** Tailor search queries to your needs
4. **Use Test Mode:** Enable for safe testing

---

## 📊 Monitoring & Logs

### Last Run Log
View in **Settings > Apify Events** sidebar:
- URLs discovered
- Pages fetched
- Events parsed
- Posts imported
- Skip reasons

### Admin Notices
- Shows warning if no run in 45 days
- Link to settings page
- Quick "Run Now" option

---

## 🔐 Security & Privacy

- ✅ All inputs sanitized
- ✅ Capability checks (`manage_options`)
- ✅ Nonce verification for AJAX
- ✅ SQL injection prevention
- ✅ XSS protection
- ✅ No personal data collected
- ✅ Only processes public event information

---

## 🆘 Support

### Getting Help
1. Check this README first
2. Review plugin settings
3. Check WordPress debug log
4. Test with manual URLs first

### Common Issues
- **No results:** Add manual URLs or set up Google Custom Search
- **Wrong dates:** Adjust queries to target correct month
- **Bad content:** Use domain exclusions
- **Duplicates:** Plugin handles this automatically

---

## 🎓 Example Use Cases

### Use Case 1: Nature Events Website
**Goal:** Import monthly nature events in Netherlands

**Setup:**
- Add manual URLs from: natuurmonumenten.nl, ivn.nl, staatsbosbeheer.nl
- OR use Google Custom Search with queries like:
  - `site:.nl evenement <VOLGEND_MAAND_JAAR> natuur`
  - `site:.nl wandeling <VOLGEND_MAAND_JAAR>`

### Use Case 2: Sustainability Blog
**Goal:** Discover sustainability events automatically

**Setup:**
- Set up Google Custom Search
- Use queries like:
  - `site:.nl duurzaamheid evenement <VOLGEND_MAAND_JAAR>`
  - `site:.nl circulaire economie <VOLGEND_MAAND_JAAR>`
- Exclude commercial sites in settings

### Use Case 3: Local Event Aggregator
**Goal:** Manually curate quality events

**Setup:**
- Use Manual URLs method only
- Add 5-10 specific event URLs weekly
- Review and publish high-quality events

---

## 🚀 Upgrade Options

**Free Method (Current)**
- Manual URLs: Unlimited
- Google Custom Search: 100 searches/day

**Paid Apify Method (Optional)**
- More automated discovery
- Higher limits
- Professional actors
- Requires Apify subscription ($49+/month)

The free method works great for most use cases!

---

## 📄 License

GPL v2 or later

---

## 🙏 Credits

Built for Dutch event discovery using:
- WordPress HTTP API
- Google Custom Search API (free tier)
- PHP/JavaScript
- Schema.org standards

No paid Apify subscription required! 🎉
