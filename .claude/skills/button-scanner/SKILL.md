---
name: button-scanner
description: >
  Button Scanner & Mapping Application. Builds and maintains the
  button-link-scanner WordPress plugin for scanning all buttons and links
  across a site, checking Gravity Forms confirmations, and mapping destinations.
  Use when auditing a site's CTA buttons, finding broken links, or checking
  GF form confirmation URLs before a migration.
allowed-tools: Read Edit Write Bash
---

## Button Scanner & Mapping Application Agent

You build and maintain the Button Link Scanner plugin
(`button-link-scanner.php`, v1.1.2+). This plugin scans all pages and posts
for buttons and links, maps their destinations, and flags issues.

### What It Scans

- All button blocks and linked elements across pages and posts
- Gravity Forms confirmation URLs (checks each form's confirmation redirect)
- WooCommerce utility pages (excluded from results automatically)
- Homepage (scanned separately — not missed like regular pages)
- Button text and `title` attribute shown in results

### Scan Results Show

- Page/post title + ID
- Button text / label
- Link destination URL
- Button type (button block, linked image, GF confirmation, etc.)
- Status flag: OK, broken, external, empty, GF confirmation

### Plugin Version History

- v1.0.0 — initial button/link scanner
- v1.1.0 — added Gravity Forms confirmation checker
- v1.1.1 — excluded WooCommerce pages, added button text + title attr
- v1.1.2 — fixed GF child-page check, GF/nav buttons in results
- Branch: `origin/claude/wordpress-button-link-scanner-7ZZWS`

### When Asked to Scan a Site

1. Confirm plugin is installed and activated
2. Run scan from WordPress admin (Tools → Button Scanner)
3. User pastes scan results — analyze:
   - Total buttons/links found
   - Flag broken links (404, empty href)
   - Flag GF confirmations pointing to wrong pages
   - Flag external links that may be IDX Broker URLs (pre-migration)
   - Group by page for easy review
4. Produce a clean mapping table: Page → Button Label → Current URL → Status

### Common Issues to Flag

- Buttons with no `href` (visually present but not linked)
- IDX Broker subdomain URLs (`search.[domain].com`) in button links
- Gravity Forms confirmations pointing to deleted/redirected pages
- Links to `/wp-admin/` or other internal admin URLs exposed to public
- Duplicate CTAs pointing to different destinations inconsistently

### Packaging

Package as `button-link-scanner.zip` for direct WordPress upload.

$ARGUMENTS
