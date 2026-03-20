# Test Environment

Uses Docker Compose to spin up WordPress + MySQL + WP-CLI.

## Quick Start

```bash
# 1. Start WordPress (runs in background)
docker compose up -d db wordpress

# 2. Seed test data (installs WP, activates plugins, creates test posts)
docker compose run --rm wpcli

# 3. Open WordPress admin
#    http://localhost:8080/wp-admin  →  admin / admin

# 4. Run automated tests (tests replacement engine directly)
docker compose run --rm wpcli wp --allow-root eval-file /test/test_replacement.php
```

## What the seed script creates

| Post                        | IDX Content                                             |
|-----------------------------|----------------------------------------------------------|
| College Station Homes       | `[impress_property_showcase saved_link_id="1001"]`      |
| Luxury Homes                | shortcode id=1002 + URL `/i/luxury-homes`               |
| Waterfront Properties       | shortcode id=1003 + URL `/i/waterfront-properties`      |
| About Us                    | No IDX content (should never be modified)               |

One IDX widget instance (`idx-broker-platinum-showcase-3`, saved_link_id=1001) is also seeded.

## What the tests verify

1. Dry run finds all 3 posts and the widget but writes nothing
2. Post content is unchanged after dry run
3. Live run updates all 3 posts and converts the widget
4. New shortcode IDs are correct (`id=9001`, `id=9002`, `id=9003`)
5. Saved-link URLs are replaced with iHF market URLs
6. The About Us page is untouched
7. A Text widget with the new shortcode was created in wp_options

## Manual UI testing

The Migration plugin lives at **WP Admin → IDX → iHF**.

The "Load" buttons call the real IDX Broker and iHF APIs — they will fail
with the seeded fake credentials. To test the full UI manually:

1. Enter a real IDX API key in **IDX Saved Searches** settings
2. Ensure Optima Express is installed and authenticated
3. Then use the migration tool normally

To test just the matching/apply UI **without real APIs**, open browser DevTools
on the migration page and inject fake data:

```js
// Paste in browser console on the IDX → iHF admin page
idxData = [
  { id: "1001", name: "College Station Homes", slug: "college-station-homes" },
  { id: "1002", name: "Luxury Homes",          slug: "luxury-homes" },
];
ihfData = [
  { id: "9001", name: "College Station Homes", url: "https://example.com/csh/" },
  { id: "9002", name: "Luxury Homes",          url: "https://example.com/luxury/" },
];
document.getElementById('idxihf-match').disabled = false;
document.getElementById('idxihf-match').click();
```

## Teardown

```bash
docker compose down -v   # removes containers AND volumes (fresh start next time)
docker compose down      # removes containers, keeps data
```
