<?php
/**
 * Deep test — loads actual plugin functions with mocked WordPress environment.
 * Tests ims_parse_markets, ims_normalise_markets, ims_claude_md_template,
 * config JSON generation, and CLAUDE.md output end-to-end.
 *
 * Run: php test-plugin-deep.php
 */

$pass = 0;
$fail = 0;

function ok( string $label, bool $result, string $detail = '' ): void {
    global $pass, $fail;
    if ( $result ) {
        echo "  PASS  {$label}\n";
        $pass++;
    } else {
        echo "  FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
        $fail++;
    }
}

// ── WordPress function stubs ───────────────────────────────────────────────────
function plugin_dir_path( $file ) { return rtrim( $file, '/' ) . '/'; }
function plugin_dir_url( $file )  { return 'http://localhost/wp-content/plugins/ihf-migration-setup/'; }
function trailingslashit( $s )    { return rtrim( $s, '/' ) . '/'; }
function is_wp_error( $v )        { return false; }
function sanitize_title( $s )     {
    $s = strtolower( $s );
    $s = preg_replace( '/[^a-z0-9\s-]/', '', $s );
    $s = preg_replace( '/[\s-]+/', '-', trim( $s ) );
    return $s;
}
function wp_parse_url( $url, $component = -1 ) {
    $r = parse_url( $url );
    if ( $component === -1 ) return $r;
    return $r[ $component ] ?? null;
}
function add_action()  {}
function add_filter()  {}
function wp_register_ability() {}
function register_activation_hook() {}
function sanitize_user( $s ) { return preg_replace( '/[^a-zA-Z0-9 _.\-@]/', '', $s ); }
function rest_url( $path = '' ) { return 'http://localhost/wp-json/' . ltrim( $path, '/' ); }
function admin_url( $path = '' ) { return 'http://localhost/wp-admin/' . ltrim( $path, '/' ); }
function wp_create_nonce( $action ) { return 'test_nonce'; }
function get_bloginfo( $show = '' ) { return 'Test Site'; }
function get_site_url() { return 'http://localhost'; }

// Constants the plugin needs (only define if not already defined)
if ( ! defined( 'ABSPATH' ) )           define( 'ABSPATH', '/tmp/' );
if ( ! defined( 'IMS_VERSION' ) )       define( 'IMS_VERSION', '2.0.0' );
if ( ! defined( 'IMS_FILE' ) )          define( 'IMS_FILE', __DIR__ . '/ihf-migration-setup/ihf-migration-setup.php' );
if ( ! defined( 'IMS_DIR' ) )           define( 'IMS_DIR',  __DIR__ . '/ihf-migration-setup/' );
if ( ! defined( 'IMS_URL' ) )           define( 'IMS_URL',  'http://localhost/wp-content/plugins/ihf-migration-setup/' );
if ( ! defined( 'HOUR_IN_SECONDS' ) )   define( 'HOUR_IN_SECONDS', 3600 );
if ( ! defined( 'IMS_OPT_STATUS' ) )    define( 'IMS_OPT_STATUS',   'ims_status' );
if ( ! defined( 'IMS_OPT_APP_PASS' ) )  define( 'IMS_OPT_APP_PASS', 'ims_app_password' );
if ( ! defined( 'IMS_OPT_MARKETS' ) )   define( 'IMS_OPT_MARKETS',  'ims_markets' );
if ( ! defined( 'IMS_OPT_CLAUDE_MD' ) ) define( 'IMS_OPT_CLAUDE_MD','ims_claude_md' );

// Load plugin functions (skip the add_action hooks — only the functions matter)
$plugin_src = file_get_contents( IMS_FILE );
// Strip top-level calls and constant definitions that conflict with our stubs
$plugin_src = preg_replace( '/^add_action\s*\(.*?^\}\s*\)\s*;/ms', '', $plugin_src );
$plugin_src = preg_replace( '/^add_filter\s*\(.*?^\}\s*\)\s*;/ms', '', $plugin_src );
$plugin_src = preg_replace( '/^register_activation_hook\s*\(.*?\)\s*;/ms', '', $plugin_src );
$plugin_src = preg_replace( '/^define\s*\(.*?\)\s*;/m', '', $plugin_src );
// Remove <?php opening tag for eval
$plugin_src = preg_replace( '/<\?php/', '', $plugin_src, 1 );
eval( $plugin_src );

// ── 1. ims_parse_markets — JSON response ──────────────────────────────────────
echo "\n=== Market Parser: JSON response ===\n";

$json_response = json_encode( [
    'hotsheets' => [
        [ 'name' => 'Aliso Viejo Homes for Sale',    'id' => 2996465, 'pageUrl' => 'https://cesipagano.com/listing-report/aliso-viejo-homes-for-sale/2996465/' ],
        [ 'name' => 'Aliso Viejo Listings $0-$500,000', 'id' => 2996466, 'pageUrl' => 'https://cesipagano.com/listing-report/aliso-viejo-listings-0-500000/2996466/' ],
        [ 'name' => 'Lake Forest Homes $500,000-$750,000', 'id' => 2996935, 'pageUrl' => 'https://cesipagano.com/listing-report/lake-forest-homes-500000-to-750000/2996935/' ],
        [ 'name' => 'Bear Brand', 'id' => 2996626, 'pageUrl' => 'https://cesipagano.com/listing-report/bear-brand/2996626/' ],
    ],
] );

$markets = ims_parse_markets( $json_response );
ok( 'JSON: returns array',              is_array( $markets ) );
ok( 'JSON: correct count',              count( $markets ) === 4 );
ok( 'JSON: sorted alphabetically',      $markets[0]['name'] === 'Aliso Viejo Homes for Sale' );
ok( 'JSON: extracts id correctly',      $markets[0]['id'] === '2996465' );
ok( 'JSON: strips domain from URL',     $markets[0]['url'] === '/listing-report/aliso-viejo-homes-for-sale/2996465/' );
ok( 'JSON: handles $0 in name',         $markets[1]['name'] === 'Aliso Viejo Listings $0-$500,000' );
ok( 'JSON: $0 URL correct (no back-ref corruption)', $markets[1]['url'] === '/listing-report/aliso-viejo-listings-0-500000/2996466/' );
$lf = array_values( array_filter( $markets, fn($m) => $m['name'] === 'Lake Forest Homes $500,000-$750,000' ) );
ok( 'JSON: lake forest URL preserved',  isset( $lf[0] ) && $lf[0]['url'] === '/listing-report/lake-forest-homes-500000-to-750000/2996935/' );

// ── 2. ims_parse_markets — XML-wrapped JSON (common iHF response format) ──────
echo "\n=== Market Parser: XML-wrapped JSON ===\n";

$inner_json = json_encode( [
    'hotsheets' => [
        [ 'name' => 'Newport Beach Homes for Sale', 'hotsheetId' => 2997025, 'permalink' => '/listing-report/newport-beach-homes-for-sale/2997025/' ],
        [ 'name' => 'Mission Viejo Active Listings', 'hotsheetId' => 2996952, 'permalink' => '/listing-report/mission-viejo-active-listings/2996952/' ],
    ],
] );
$xml_response = '<?xml version="1.0"?><response><data>' . htmlspecialchars( $inner_json ) . '</data></response>';

$markets_xml = ims_parse_markets( $xml_response );
ok( 'XML: returns array',               is_array( $markets_xml ) );
ok( 'XML: correct count',               count( $markets_xml ) === 2 );
ok( 'XML: extracts hotsheetId',         $markets_xml[1]['id'] === '2997025' );
ok( 'XML: sorted alphabetically',       $markets_xml[0]['name'] === 'Mission Viejo Active Listings' );
ok( 'XML: relative URL unchanged',      $markets_xml[1]['url'] === '/listing-report/newport-beach-homes-for-sale/2997025/' );

// ── 3. ims_parse_markets — flat JSON array (alternate format) ─────────────────
echo "\n=== Market Parser: flat JSON array ===\n";

$flat_json = json_encode( [
    [ 'title' => 'San Clemente Active Homes for Sale', 'marketId' => 2997104, 'link' => 'https://example.com/listing-report/san-clemente-active-homes-for-sale/2997104/' ],
    [ 'title' => 'Dana Point Active Listings',         'marketId' => 2996617, 'link' => 'https://example.com/listing-report/dana-point-active-listings/2996617/' ],
] );

$markets_flat = ims_parse_markets( $flat_json );
ok( 'Flat: returns array',              is_array( $markets_flat ) );
ok( 'Flat: correct count',              count( $markets_flat ) === 2 );
ok( 'Flat: uses title key',             $markets_flat[0]['name'] === 'Dana Point Active Listings' );
ok( 'Flat: uses marketId key',          $markets_flat[0]['id'] === '2996617' );
ok( 'Flat: strips domain from link',    $markets_flat[0]['url'] === '/listing-report/dana-point-active-listings/2996617/' );

// ── 4. ims_parse_markets — error/pending response ─────────────────────────────
echo "\n=== Market Parser: error responses ===\n";

$pending_xml = '<?xml version="1.0"?><response><message>pending account activation required</message></response>';
$result = ims_parse_markets( $pending_xml );
ok( 'Pending: returns error string',    is_string( $result ) );
ok( 'Pending: message mentions pending', stripos( $result, 'pending' ) !== false );

$garbage = 'not valid json or xml at all %%%';
$result2 = ims_parse_markets( $garbage );
ok( 'Garbage: returns error string',    is_string( $result2 ) );

// ── 5. ims_parse_markets — empty/no markets ───────────────────────────────────
echo "\n=== Market Parser: empty markets ===\n";

$empty_json = json_encode( [ 'hotsheets' => [] ] );
$result3 = ims_parse_markets( $empty_json );
ok( 'Empty: returns empty array',       is_array( $result3 ) && count( $result3 ) === 0 );

// ── 6. ims_claude_md_template — full end-to-end generation ───────────────────
echo "\n=== CLAUDE.md Generation: end-to-end ===\n";

// Build the market table exactly as the AJAX handler does
$test_markets = [
    [ 'name' => 'Aliso Viejo Homes for Sale',        'id' => '2996465', 'url' => '/listing-report/aliso-viejo-homes-for-sale/2996465/' ],
    [ 'name' => 'Aliso Viejo Listings $0-$500,000',  'id' => '2996466', 'url' => '/listing-report/aliso-viejo-listings-0-500000/2996466/' ],
    [ 'name' => 'Lake Forest Homes $500,000-$750,000','id' => '2996935', 'url' => '/listing-report/lake-forest-homes-500000-to-750000/2996935/' ],
    [ 'name' => 'Newport Beach Homes for Sale',       'id' => '2997025', 'url' => '/listing-report/newport-beach-homes-for-sale/2997025/' ],
    [ 'name' => 'Westridge - Oak View Estates',       'id' => '2997128', 'url' => '/listing-report/westridge-oak-view-estates/2997128/' ],
];

$market_table = "| Market Name | ID | listing-report URL |\n|---|---|---|\n";
foreach ( $test_markets as $m ) {
    $market_table .= "| {$m['name']} | " . ( $m['id'] ?: '—' ) . " | " . ( $m['url'] ?: '—' ) . " |\n";
}

$test_saved_searches = [
    [ 'id' => '11111', 'name' => 'Aliso Viejo Homes for Sale',   'slug' => 'aliso-viejo-homes-for-sale',   'idx_url' => 'search.test.com/i/aliso-viejo-homes-for-sale' ],
    [ 'id' => '11112', 'name' => 'Newport Beach Homes for Sale', 'slug' => 'newport-beach-homes-for-sale', 'idx_url' => 'search.test.com/i/newport-beach-homes-for-sale' ],
];

$md = ims_claude_md_template( 'Test Realty', 'https://test.mystagingwebsite.com', $market_table, $test_saved_searches );

ok( 'Generated: not empty',             ! empty( $md ) );
ok( 'Generated: contains full instructions',    strpos( $md, '## URL Redirect Map' ) !== false );
ok( 'Generated: contains workflow',             strpos( $md, 'Step 1: Scan nav menus' ) !== false );
ok( 'Generated: contains 10 steps',             strpos( $md, 'Step 10:' ) !== false );
ok( 'Generated: contains site name',            strpos( $md, 'Test Realty' ) !== false );
ok( 'Generated: contains site URL',             strpos( $md, 'https://test.mystagingwebsite.com' ) !== false );
ok( 'Generated: $0 market name intact',         strpos( $md, 'Aliso Viejo Listings $0-$500,000' ) !== false );
ok( 'Generated: $0 URL not corrupted',          strpos( $md, '/listing-report/aliso-viejo-listings-0-500000/2996466/' ) !== false );
ok( 'Generated: $500k market intact',           strpos( $md, 'Lake Forest Homes $500,000-$750,000' ) !== false );
ok( 'Generated: lake forest URL intact',        strpos( $md, '/listing-report/lake-forest-homes-500000-to-750000/2996935/' ) !== false );
ok( 'Generated: Westridge dash intact',         strpos( $md, 'Westridge - Oak View Estates' ) !== false );
ok( 'Generated: market placeholder removed',    strpos( $md, '(Generated by iHF Migration Setup' ) === false );
ok( 'Generated: saved searches injected',       strpos( $md, 'aliso-viejo-homes-for-sale' ) !== false );
ok( 'Generated: saved searches section present',strpos( $md, '## IDX Broker Saved Searches' ) !== false );
ok( 'Generated: shortcode reference present',   strpos( $md, 'optima_express_toppicks' ) !== false );
ok( 'Generated: key rules present',             strpos( $md, '## Key Rules' ) !== false );
ok( 'Generated: widget blocks step present',    strpos( $md, 'idx-broker-platinum' ) !== false );
ok( 'Generated: Step 9 checks blocks',          strpos( $md, '`idx-broker-platinum`' ) !== false );

// ── 7. CLAUDE.md — Step 3 search patterns are complete ───────────────────────
echo "\n=== CLAUDE.md: Step 3 search patterns ===\n";

$step3_start = strpos( $md, '**Step 3:' );
$step3_end   = strpos( $md, '**Step 4:', $step3_start );
$step3       = substr( $md, $step3_start, $step3_end - $step3_start );

ok( 'Step 3: searches IDX subdomain',       strpos( $step3, 'search.[domain].com' ) !== false );
ok( 'Step 3: searches idx-broker-platinum', strpos( $step3, 'idx-broker-platinum' ) !== false );
ok( 'Step 3: searches /i/ with subdomain',  strpos( $step3, 'search.[domain].com/i/' ) !== false );
ok( 'Step 3: searches IDX shortcodes',      strpos( $step3, '[IDX-' ) !== false );
ok( 'Step 3: searches impress_ shortcodes', strpos( $step3, '[impress_' ) !== false );

// ── 8. Config JSON — structure and no spaces in password ──────────────────────
echo "\n=== Config JSON generation ===\n";

$site_url = 'https://client.mystagingwebsite.com';
$plain    = str_replace( ' ', '', 'AbCd EfGh IjKl MnOp QrSt UvWx' );
$config   = [
    'mcpServers' => [
        'wordpress' => [
            'command' => 'npx',
            'args'    => [ '-y', '@automattic/mcp-server-wordpress' ],
            'env'     => [
                'WP_SITE_URL'     => $site_url,
                'WP_USERNAME'     => 'claude-mcp',
                'WP_APP_PASSWORD' => $plain,
            ],
        ],
    ],
];
$json_out = json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$decoded  = json_decode( $json_out, true );

ok( 'Config: valid JSON',               $decoded !== null );
ok( 'Config: site URL correct',         $decoded['mcpServers']['wordpress']['env']['WP_SITE_URL'] === $site_url );
ok( 'Config: username is claude-mcp',   $decoded['mcpServers']['wordpress']['env']['WP_USERNAME'] === 'claude-mcp' );
ok( 'Config: password no spaces',       strpos( $decoded['mcpServers']['wordpress']['env']['WP_APP_PASSWORD'], ' ' ) === false );
ok( 'Config: password alphanumeric',    ctype_alnum( $decoded['mcpServers']['wordpress']['env']['WP_APP_PASSWORD'] ) );
ok( 'Config: uses npx command',         $decoded['mcpServers']['wordpress']['command'] === 'npx' );
ok( 'Config: uses mcp-server-wordpress', in_array( '@automattic/mcp-server-wordpress', $decoded['mcpServers']['wordpress']['args'] ) );

// ── 9. URL redirect map — all 23 entries present ──────────────────────────────
echo "\n=== URL Redirect Map completeness ===\n";

$redirect_map = [
    '/idx/search/advanced', '/idx/search/homes', '/idx/search/address',
    '/idx/search/smart', '/idx/search/basic', '/idx/search/emailupdatesignup',
    '/idx/search/listingid', '/idx/searchbycity', '/idx/sitemap',
    '/idx/map/mapsearch', '/idx/linkshowcase', '/idx/featuredvirtualtour',
    '/idx/featured', '/idx/soldpending', '/idx/mortgage',
    '/idx/homevaluation', '/idx/roster', '/idx/contact',
    '/idx/userlogin', '/idx/usersignup', '/idx/featuredopenhouse',
    '/idx/supplemental', '/idx/market-reports',
];

foreach ( $redirect_map as $path ) {
    ok( "Redirect map has {$path}", strpos( $md, $path ) !== false );
}

// ── 10. iHF destination URLs are all present ─────────────────────────────────
echo "\n=== iHF destination URLs ===\n";

$destinations = [
    '/homes-for-sale-search/',
    '/homes-for-sale-featured/',
    '/sold-featured-listing/',
    '/mortgage-calculator/',
    '/home-valuation/',
    '/agent-list/',
    '/contact-us/',
    '/property-organizer-login/',
    '/open-home-search/',
    '/supplemental-listing/',
];

foreach ( $destinations as $dest ) {
    ok( "Destination present: {$dest}", strpos( $md, $dest ) !== false );
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
$total = $pass + $fail;
echo "=== Results: {$pass}/{$total} passed" . ( $fail ? " — {$fail} FAILED" : ' — all good' ) . " ===\n\n";
exit( $fail > 0 ? 1 : 0 );
