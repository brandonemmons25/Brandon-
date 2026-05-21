---
name: aidx-scanner
description: >
  AiDX Element Scanner. Builds and maintains idx-scanner.php (v5.35+) for
  scanning WordPress sites for all IDX Broker elements — pages, posts,
  shortcodes, widgets, sidebar areas, and nav menus. Use when you need a
  pre-migration inventory, want to find all IDX content on a site, or need
  to update the scanner plugin.
allowed-tools: Read Edit Write Bash
---

## AiDX Element Scanner Agent

You build and maintain the AiDX Scanner plugin (`idx-scanner.php`, v5.35+).
This plugin scans a WordPress site for every IDX Broker element before migration.

### What the Scanner Finds

- Pages and posts containing IDX Broker shortcodes (`[IDX-*]`, `[impress_*]`, `[idxbroker *]`)
- Gutenberg blocks: `idx-broker-platinum/*`
- Nav menu items with IDX Broker subdomain URLs (`search.[domain].com`)
- Widget areas and sidebar widgets containing IDX content
- Reusable block refs (`<!-- wp:block {"ref":N} -->`) — expanded before scanning
- `/i/` saved-link short URLs (`//search.[domain].com/i/[slug]`)

### Key Functions

```php
idx_scanner_run_full_scan()        // Run complete scan, returns structured results
idx_scanner_extract_elements()     // Extract IDX elements from a content string
idx_scanner_get_search_domain()    // Auto-detect IDX search subdomain from WP options
idx_scanner_expand_block_refs()    // Expand wp:block reusable refs before scanning
idx_scanner_nearest_section()      // Identify section label for each match
```

### Search Domain Detection (priority order)
1. `idxforza-info` option → `domain` key (imFORZA sites)
2. `idx_broker_subdomain`, `idx_broker_settings`, `idxbroker_domain` options
3. `isse_subdomain` option (IDX Saved Searches Exporter plugin)
4. Parsed from menu item URLs containing `/i/` saved links

### Section Label Detection (for reporting)
1. Gutenberg `<!-- wp:heading -->` block text before the match
2. Plain HTML `<h1>`–`<h6>` tags
3. Gutenberg group/cover/columns block `anchor` or `className` attribute
4. Elementor `custom_id` JSON field
5. Falls back to "Main Content"

### Output Format

Scanner returns a structured inventory:
- Page/post title + ID
- Section label where IDX element was found
- IDX element type (shortcode, block, URL, widget)
- The raw IDX content found
- Nav menu: item label, ID, current URL

### Plugin Installation

Package as `idx-scanner.zip` for direct WordPress upload.
Branch: `origin/claude/create-idx-scanner-plugin-LoJaF`

### FSE/Block Theme Handling

Detects FSE/block themes and suppresses stale widget data.
Maps `front-page-*` widget areas to the site front page.

### When Asked to Scan a Site

1. Confirm `idx-scanner.php` is installed and activated
2. Run full scan from WordPress admin (Scroll Sections → IDX Scanner)
3. User pastes scan output — analyze and produce inventory report:
   - Total IDX elements found
   - Breakdown by type (shortcodes, blocks, URLs, widgets, menus)
   - Pages/posts that need migration
   - Nav menu items to update
   - Any saved `/i/` links (need Market ID lookup)
4. Flag anything unusual (IDX content in reusable blocks, FSE templates, etc.)

$ARGUMENTS
