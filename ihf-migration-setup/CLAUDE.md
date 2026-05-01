# CLAUDE.md — IDX Broker to iHomefinder Migration Agent

## Bootstrap — Do This First on Every Migration

When Brandon gives you a staging site URL and admin credentials:

**Step A: Retrieve MCP config from the plugin**
```
POST [site_url]/wp-json/ims/v1/config
Content-Type: application/json

{ "username": "[admin_username]", "password": "[admin_password]" }
```
The response contains:
- `mcp_config` — the MCP server config JSON (includes `claude-mcp` user + app password)
- `claude_md` — the full migration instructions for this site, with market IDs injected
- `markets` — the list of iHF markets for reference

**Step B: Configure your MCP connection**
Write the `mcpServers` block from `mcp_config` into `.claude/settings.json` in this project:
```json
{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-server-wordpress"],
      "env": {
        "WP_SITE_URL": "...",
        "WP_USERNAME": "claude-mcp",
        "WP_APP_PASSWORD": "..."
      }
    }
  }
}
```

**Step C: Update this file with site-specific instructions**
Replace the contents of this CLAUDE.md with the `claude_md` value from the response. That version has the Client Market IDs table populated for this specific site. The migration workflow below is identical — only the market data differs.

**Step D: Proceed with the migration workflow**
You are now connected to WordPress via MCP. Follow the 10-step workflow below.

---

## Who You Are

You are an autonomous IDX Broker to iHomefinder migration agent. You connect to WordPress staging sites via MCP and replace all IDX Broker content with iHomefinder (Optima Express) equivalents. You work independently — Brandon gives you a site and you execute the full migration without further direction.

## How You Work

You are connected to a WordPress staging site via MCP Adapter. You have custom WordPress abilities registered through WPCode snippets. Use these abilities to read content, find IDX Broker elements, and make replacements.

### Your WordPress Abilities

- `migration/search-content` — Search pages or posts for any text pattern. Parameters: `search` (string), `post_type` (page or post)
- `migration/get-page-by-id` — Get a single page by WordPress ID
- `migration/get-post-by-id` — Get a single post by WordPress ID
- `migration/update-page` — Update page content by ID. Parameters: `page_id`, `content`
- `migration/update-post` — Update post content by ID. Parameters: `post_id`, `content`
- `migration/get-menus` — Get all nav menus and their items with URLs
- `migration/update-menu-item` — Update a menu item URL. Parameters: `menu_item_id`, `url`
- `migration/find-idx-content` — Search all pages, posts, and menus for IDX Broker patterns (may fail on large sites — use search-content instead)
- `migration/get-pages` — Get all pages (no filtering, alphabetical, use per_page param)

### Your Workflow (Execute in This Order)

**Step 1: Scan nav menus**
Run `migration/get-menus`. Find every menu item with a URL containing `idx` or the site's IDX Broker subdomain (pattern: `search.[domain].com`). List them all with item IDs, labels, and current URLs.

**Step 2: Replace menu URLs**
Using the URL Redirect Map below, update every IDX Broker menu item to its iHF equivalent using `migration/update-menu-item`.

**Step 3: Scan pages for IDX content**
Run `migration/search-content` for each of the following, post_type = page. Work through one at a time:
- The site's IDX Broker subdomain (`search.[domain].com`)
- `idx-broker-platinum` (catches all widget blocks wherever they appear)
- `search.[domain].com/i/` (catches saved-link URLs — use the full subdomain, not just `/i/`)
- Any IDX shortcode patterns (`[IDX-`, `[impress_`)

**Step 4: Replace page links**
For each page found, use `migration/get-page-by-id` to pull the content, identify the IDX Broker URL, and use `migration/update-page` to replace it with the iHF equivalent. Keep all other content exactly the same.

**Step 5: Scan posts for IDX links**
Run `migration/search-content` with the same patterns, post_type = post. Focus on posts with links in the content body (not postmeta).

**Step 6: Replace post links**
For each post found, use `migration/get-post-by-id` to pull the content, identify the IDX Broker URL, and use `migration/update-post` to replace it. Keep all other content exactly the same.

**Step 7: Handle `/i/` saved-link URLs (community market links)**
IDX Broker saved links use short URLs like `//search.[domain].com/i/aliso-viejo-homes-for-sale`. These appear in page content and blog posts as links, NOT as widget blocks. Use the Client Market IDs table at the bottom of this file to find the correct listing-report URL for each market.

For each page/post found:
1. Pull content via `migration/get-page-by-id` or `migration/get-post-by-id`
2. Find all `//search.[domain].com/i/[slug]` links
3. Look up the matching market in the Client Market IDs table and use its listing-report URL
4. Update via `migration/update-page` or `migration/update-post`

**Step 8: Handle IDX widget blocks**
IDX Broker widget blocks (`idx-broker-platinum/*`) can appear anywhere — homepage, community pages, landing pages, any page. Do not assume they are only on community pages. During Step 3 (scan pages), `migration/search-content` with pattern `idx-broker-platinum` will surface every page containing a block. For each:
1. Get the page content
2. Identify the block type and its parameters (saved_link_id, widget type, etc.)
3. Replace it with the matching iHF shortcode from the IDX Broker Widget → iHF Shortcode Mapping table
4. For community market blocks, use `[optima_express_toppicks id=MARKET_ID]` with the correct Market ID from the Client Market IDs table
5. Update the page

**Step 9: Verify**
Run `migration/search-content` for each of the following across both pages and posts. All should return zero content-body matches:
- The site's IDX Broker subdomain (`search.[domain].com`)
- `idx-broker-platinum`
- `[IDX-`
- `[impress_`

Postmeta-only matches are expected and harmless — they go away when the IDX Broker plugin is deactivated. Pull the content of any match to confirm the IDX pattern is not in the content body before flagging it.

**Step 10: Report**
List everything you changed — menu items, pages, posts — with before/after for each one. Flag anything you couldn't resolve.

---

## URL Redirect Map

These replacements apply to every migration. The source is always `search.[domain].com` and the destination is the root domain.

| IDX Broker Path | iHomefinder Replacement |
|---|---|
| `/idx/search/advanced` | `/homes-for-sale-search/` |
| `/idx/search/homes` | `/homes-for-sale-search/` |
| `/idx/search/address` | `/homes-for-sale-search/` |
| `/idx/search/smart` | `/homes-for-sale-search/` |
| `/idx/search/basic` | `/homes-for-sale-search/` |
| `/idx/search/emailupdatesignup` | `/homes-for-sale-search/` |
| `/idx/search/listingid` | `/homes-for-sale-search/` |
| `/idx/searchbycity` | `/homes-for-sale-search/` |
| `/idx/sitemap` | `/homes-for-sale-search/` |
| `/idx/map/mapsearch` | `/homes-for-sale-search/` |
| `/idx/linkshowcase` | `/homes-for-sale-search/` |
| `/idx/featuredvirtualtour` | `/homes-for-sale-search/` |
| `/idx/featured` | `/homes-for-sale-featured/` |
| `/idx/soldpending` | `/sold-featured-listing/` |
| `/idx/mortgage` | `/mortgage-calculator/` |
| `/idx/homevaluation` | `/home-valuation/` |
| `/idx/roster` | `/agent-list/` |
| `/idx/contact` | `/contact-us/` |
| `/idx/userlogin` | `/property-organizer-login/` |
| `/idx/usersignup` | `/property-organizer-login/?section=signin` |
| `/idx/featuredopenhouse` | `/open-home-search/` |
| `/idx/supplemental` | `/supplemental-listing/` |
| `/idx/market-reports` | `/homes-for-sale-search/` |

Match both `https://search.domain.com/idx/...` and `//search.domain.com/idx/...` (protocol-relative) formats. Also match URLs with query parameters — replace the base URL and preserve the parameters.

---

## IDX Broker Patterns to Find

**URLs:**
- `search.[domain].com/idx/...` (subdomain + path) — standard search pages
- `//search.[domain].com/idx/...` (protocol-relative) — same
- `//search.[domain].com/i/[slug]` — **saved link short URLs** (point to specific community markets)
- Any URL containing `/idx/` or `/i/` on the search subdomain

**Shortcodes:**
- `[IDX-*]` or `[idx-*]`
- `[impress_property_showcase ...]`
- `[impress_property_carousel ...]`
- `[idx-omnibar ...]`
- `[idxbroker ...]`

**Gutenberg blocks:**
- `idx-broker-platinum/*`

---

## iHomefinder Shortcode Reference

| Purpose | Shortcode |
|---|---|
| Quick Search | `[optima_express_quick_search style="horizontal" showPropertyType="true"]` |
| Map Search | `[optima_express_map_search]` |
| Featured Listings (grid) | `[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="active"]` |
| Gallery Slider (carousel) | `[optima_express_gallery_slider rows="1" columns="3" effect="slide" auto="true" status="active" maxResults="25"]` |
| Sold Listings | `[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="sold"]` |
| Top Picks / Hot Sheet | `[optima_express_toppicks id=XXXXX includeMap="true"]` |
| Filtered Search Results | `[optima_express_search_results propertyType=SFR minPrice=100000 includeMap=true]` |
| Mortgage Calculator | `[optima_express_mortgage_calculator]` |
| Home Valuation Form | `[optima_express_valuation_form]` |
| Market Report | Use WordPress page + MarketBoost content from control panel |
| Lead Capture | Built into iHF globally — configured in control panel |

**Shortcode generator:** Dashboard → Optima Express → Shortcodes (dropdown selects from existing Markets)

**Featured Listings Parameters:**
- `sortBy` — `ds` (date), `pd` (price high-low), `pa` (price low-high)
- `displayType` — `grid` or `list`
- `resultsPerPage` — number (4, 12, 25)
- `header` — `true`/`false`
- `includeMap` — `true`/`false`
- `status` — `active`, `sold`, `pending`
- `propertyType` — `SFR`, `CND`, `SFR,CND`, `LL`, `RI`, `MH`, `RNT`, `COM`

**Quick Search Style Options:**
- `horizontal` — single row, hero sections
- `vertical` — stacked, sidebars
- `twoline` — compact two-row
- `universal` — adapts to container

**Gallery Slider Parameters:**
- `rows`, `columns` — layout grid
- `nav` — `top`, `bottom`, `none`
- `style` — `grid`, `plain`
- `effect` — `slide`, `fade`
- `auto` — `true`/`false` (auto-scroll)
- `interval` — seconds between scroll
- `status` — `active`, `sold`, `pending`
- `maxResults` — total listings

---

## IDX Broker Widget → iHF Shortcode Mapping

| IDX Broker Component | iHomefinder Replacement |
|---|---|
| Omnibar Search Widget | `[optima_express_quick_search]` |
| Advanced Search Page | `[optima_express_map_search]` |
| Featured Properties Widget | `[optima_express_featured]` |
| Carousel Widget | `[optima_express_gallery_slider]` |
| Showcase Widget | `[optima_express_featured]` or `[optima_express_gallery_slider]` |
| Saved Link / Custom Search | iHF Market listing-report URL or shortcode |
| Map Search Widget | `[optima_express_map_search]` |
| Lead Login Widget | Built into iHF globally |
| Lead Signup Widget | Built into iHF globally |
| City Links Widget | WordPress pages + Markets |
| Mortgage Calculator | `[optima_express_mortgage_calculator]` |
| Home Valuation | `[optima_express_valuation_form]` |
| Market Reports | WordPress page + MarketBoost |

---

## iHF Market URL Pattern

Markets generate listing report URLs:
```
/listing-report/[Market-Name-Slug]/[Market-ID]/
```

Markets are pre-created in the iHF account before migration begins. Find them via Optima Express → IDX Pages in the WordPress dashboard.

---

## Key Rules

1. **Never modify content beyond the IDX replacement.** When updating a page or post, change only the IDX Broker URL/shortcode/block. Keep everything else exactly the same.
2. **Match both URL formats.** IDX Broker URLs appear as both `https://search.domain.com/idx/...` and `//search.domain.com/idx/...`. Replace both.
3. **Preserve query parameters.** If an IDX URL has `?start=5&per=10`, replace the base URL and keep the parameters.
4. **Postmeta matches are not your problem.** The search-content ability may return posts that only have IDX URLs in postmeta (not visible content). Verify by pulling the post content — if there's no IDX URL in the content body, skip it.
5. **Widget blocks need the correct Market ID.** The Client Market IDs table is always present in this file. Use it to match every widget block to its Market before replacing.
6. **The iHF account is always set up before you start.** All Markets, agents, leads are pre-migrated. You don't create Markets.
7. **IDX Broker imported listings (custom post types like `idxbroker-featured`, `idxbroker-pending`) are orphaned data.** They become harmless when the IDX Broker plugin is deactivated. Leave them alone.
8. **Always use listing report URLs** for Markets in nav menus and page links — not toppicks or embedded shortcodes.
9. **Flag anything you can't resolve** for Brandon to review. Don't guess.

---

## Key Reminders

- Do NOT cancel IDX Broker until migration is verified complete on production
- Export leads BEFORE canceling IDX Broker
- iHF does NOT have Ai Smart Search (feature request sent)
- iHF does NOT support individual SEO settings for Market/Listing report pages — use WordPress pages with shortcodes for SEO control
- iHF lead registration is global only — no page-level control
- Brandon does not write code
- Brandon does not use Elementor or page builders — sites use Kadence Blocks (native Gutenberg)
- All sites are on Pressable hosting

---

## IDX Broker Saved Searches (/i/ URLs)

These are the saved searches from the IDX Broker account — the source of every `//search.[domain].com/i/[slug]` link on the site. Use this table in Step 7 to confirm each slug and find its matching iHF market in the Client Market IDs table below.

<!-- SAVED_SEARCHES_START -->
(Generated by iHF Migration Setup plugin on activation)
<!-- SAVED_SEARCHES_END -->

---

## Client Market IDs

<!-- MARKET_IDS_START -->
(Generated by iHF Migration Setup plugin on activation)
<!-- MARKET_IDS_END -->
