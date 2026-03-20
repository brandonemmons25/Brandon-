#!/bin/sh
# Seed script: waits for WordPress to be ready, installs it, and creates test data.
# Run via: docker compose run --rm wpcli

set -e

WP="wp --allow-root --path=/var/www/html"

# ── Wait for WordPress files and DB ───────────────────────────────────────────
echo "Waiting for WordPress to be ready..."
until $WP core is-installed 2>/dev/null || [ -f /var/www/html/wp-load.php ]; do
  sleep 2
done

# ── Install WordPress if not yet installed ────────────────────────────────────
if ! $WP core is-installed 2>/dev/null; then
  echo "Installing WordPress..."
  $WP core install \
    --url="http://localhost:8080" \
    --title="IDX Migration Test" \
    --admin_user="admin" \
    --admin_password="admin" \
    --admin_email="admin@example.com" \
    --skip-email
  echo "WordPress installed. Login: admin / admin at http://localhost:8080/wp-admin"
else
  echo "WordPress already installed."
fi

# ── Activate plugins ──────────────────────────────────────────────────────────
echo "Activating plugins..."
$WP plugin activate idx-to-ihf-migration       2>/dev/null || echo "  idx-to-ihf-migration: check path"
$WP plugin activate idx-saved-searches-exporter 2>/dev/null || echo "  idx-saved-searches-exporter: check path"

# ── Fake credentials (so UI buttons activate without real API calls) ──────────
echo "Seeding fake credentials..."
$WP option update isse_api_key         "FAKE_IDX_API_KEY_FOR_TESTING"
$WP option update isse_subdomain       "search.testsite.com"
$WP option update ihf_authentication_token "FAKE_IHF_TOKEN_FOR_TESTING"

# ── Seed test posts with IDX shortcodes ───────────────────────────────────────
echo "Creating test posts..."

# Post 1: has an impress_property_showcase shortcode with saved_link_id
POST1=$($WP post create \
  --post_title="College Station Homes For Sale" \
  --post_status="publish" \
  --post_type="page" \
  --post_content='<h2>Browse Listings</h2>
[impress_property_showcase property_type="savedlinks" saved_link_id="1001" title="College Station Homes"]
<p>Check out all available homes.</p>' \
  --porcelain)
echo "  Created post #$POST1: College Station Homes For Sale"

# Post 2: has a saved-link URL in content
POST2=$($WP post create \
  --post_title="Luxury Homes" \
  --post_status="publish" \
  --post_type="page" \
  --post_content='<h2>Luxury Properties</h2>
<p>Browse our <a href="https://search.testsite.com/i/luxury-homes">luxury home listings</a>.</p>
[impress_property_showcase property_type="savedlinks" saved_link_id="1002"]' \
  --porcelain)
echo "  Created post #$POST2: Luxury Homes"

# Post 3: has both a shortcode and a URL
POST3=$($WP post create \
  --post_title="Waterfront Properties" \
  --post_status="publish" \
  --post_type="page" \
  --post_content='<h2>Waterfront Homes</h2>
<p>See all listings: https://search.testsite.com/i/waterfront-properties</p>
[impress_property_showcase property_type="savedlinks" saved_link_id="1003" title="Waterfront"]' \
  --porcelain)
echo "  Created post #$POST3: Waterfront Properties"

# Post 4: no IDX content (should NOT be modified)
POST4=$($WP post create \
  --post_title="About Us" \
  --post_status="publish" \
  --post_type="page" \
  --post_content='<p>Welcome to our agency. We specialize in residential real estate.</p>' \
  --porcelain)
echo "  Created post #$POST4: About Us (clean, should not be touched)"

# ── Seed a fake IDX widget in a sidebar ───────────────────────────────────────
echo "Seeding fake IDX widget instance..."
$WP option update widget_idx-broker-platinum-showcase \
  '{"3":{"title":"Featured Listings Widget","saved_link_id":"1001","number_of_results":"6"},"_multiwidget":1}' \
  --format=json

# Point primary sidebar at the widget
SIDEBARS=$($WP option get sidebars_widgets --format=json 2>/dev/null || echo '{}')
$WP option update sidebars_widgets \
  '{"wp_inactive_widgets":[],"sidebar-1":["idx-broker-platinum-showcase-3"],"_multiwidget":1}' \
  --format=json

echo ""
echo "========================================="
echo "  Test environment ready!"
echo "  URL:      http://localhost:8080"
echo "  WP Admin: http://localhost:8080/wp-admin"
echo "  Login:    admin / admin"
echo ""
echo "  Test posts created:"
echo "    #$POST1  College Station Homes (shortcode id=1001)"
echo "    #$POST2  Luxury Homes (shortcode id=1002 + URL)"
echo "    #$POST3  Waterfront Properties (shortcode id=1003 + URL)"
echo "    #$POST4  About Us (no IDX content)"
echo ""
echo "  IDX widget: idx-broker-platinum-showcase-3 (id=1001)"
echo ""
echo "  To test the Migration plugin:"
echo "    1. Go to IDX → iHF in WP Admin"
echo "    2. The fetch buttons will fail (fake credentials) — see note below"
echo "    3. Use test/mock_ajax.sh to inject fake API responses and test replacement"
echo "========================================="
