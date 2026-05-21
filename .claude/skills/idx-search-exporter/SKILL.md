---
name: idx-search-exporter
description: >
  IDX Broker Saved Searches Exporter. Builds and maintains the
  idx-saved-searches-exporter plugin for exporting saved searches and
  community market links from IDX Broker before migration. Use when you
  need to export /i/ saved link slugs, get the IDX search subdomain, or
  prepare the saved searches list for the iHF migration redirect map.
allowed-tools: Read Edit Write Bash
---

## IDX Broker Saved Searches Exporter Agent

You build and maintain the IDX Saved Searches Exporter plugin
(`idx-saved-searches-exporter.php`, v1.7+). This plugin exports all saved
searches and community market /i/ links from IDX Broker before migration.

### What It Does

- Hits the IDX Broker API to pull all saved searches for the account
- Exports saved search slugs (the `/i/[slug]` paths used in nav menus and pages)
- Stores the IDX search subdomain in `isse_subdomain` WP option
- Handles IDX Broker API 204 (no content) responses correctly
- Produces a CSV: slug, saved search name, IDX Broker URL, destination (blank for mapping)

### Key Option

`isse_subdomain` — stores the site's IDX search subdomain (e.g. `search.collegestationhomes.com`).
The AiDX Scanner reads this option as a fallback when `idxforza-info` is not set.

### Plugin Version History

- v1.7 — handles IDX Broker 204 response correctly
- Branch: `origin/claude/create-idx-scanner-plugin-LoJaF`

### Export Output Format

CSV columns:
- `source` — the `/i/[slug]` path (e.g. `/i/aliso-viejo-homes-for-sale`)
- `name` — human-readable saved search name
- `idx_url` — full IDX Broker URL
- `destination` — blank, to be filled in with iHF listing-report URL

### Workflow

1. Install plugin on staging site
2. Enter IDX Broker API key in plugin settings
3. Click Export — downloads CSV of all saved searches
4. Use CSV as source list for iHF redirect map
5. Match each `/i/` slug to the corresponding iHF Market listing-report URL

### When Asked to Export Saved Searches

1. Confirm plugin is installed and IDX Broker API key is set
2. Run export from WordPress admin
3. User pastes or uploads CSV — analyze:
   - Total saved searches found
   - Flag any slugs with no obvious iHF Market match
   - Produce filled redirect map (source → iHF destination) where matches are clear
   - Leave destination blank where Market ID is unknown — never guess
4. Output a ready-to-use redirect CSV

$ARGUMENTS
