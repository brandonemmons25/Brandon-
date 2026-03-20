<?php
/**
 * Direct test of the migration plugin's replacement engine.
 * Run via WP-CLI:  wp --allow-root eval-file /var/www/html/wp-content/plugins/idx-to-ihf-migration/../../../../../../test/test_replacement.php
 * Or:             docker compose run --rm wpcli wp --allow-root eval-file /test/test_replacement.php
 *
 * Tests idxihf_process_pairs() against known seeded posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Allow running from CLI via wp eval-file
    define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/wp-content/../' );
}

// Make sure the plugin functions are loaded
if ( ! function_exists( 'idxihf_process_pairs' ) ) {
    require_once __DIR__ . '/../idx-to-ihf-migration/idx-to-ihf-migration.php';
}

global $wpdb;

echo "\n=== IDX → iHF Migration: Replacement Engine Tests ===\n\n";

$pass = 0;
$fail = 0;

function test( $label, $condition, $detail = '' ) {
    global $pass, $fail;
    if ( $condition ) {
        echo "  ✓ PASS  {$label}\n";
        $pass++;
    } else {
        echo "  ✗ FAIL  {$label}" . ( $detail ? " — {$detail}" : '' ) . "\n";
        $fail++;
    }
}

// ── 1. Dry-run: verify posts would be modified ───────────────────────────────
echo "-- Test 1: Dry run (no DB writes) --\n";

$pairs = [
    [ 'idx_id' => '1001', 'idx_name' => 'College Station Homes', 'ihf_id' => '9001', 'ihf_url' => 'https://testsite.com/college-station/' ],
    [ 'idx_id' => '1002', 'idx_name' => 'Luxury Homes',          'ihf_id' => '9002', 'ihf_url' => 'https://testsite.com/luxury/'           ],
    [ 'idx_id' => '1003', 'idx_name' => 'Waterfront Properties',  'ihf_id' => '9003', 'ihf_url' => 'https://testsite.com/waterfront/'       ],
];

$result = idxihf_process_pairs( $pairs, 'search.testsite.com', true );

test( 'Dry run returns dry_run=true',          $result['dry_run'] === true );
test( 'Dry run finds College Station post',    $result['posts_updated'] >= 1 );
test( 'Dry run finds Luxury Homes post',       $result['posts_updated'] >= 2 );
test( 'Dry run finds Waterfront post',         $result['posts_updated'] >= 3 );
test( 'Dry run detects widget id=1001',        $result['widgets_converted'] >= 1 );
test( 'Log is non-empty',                      count( $result['log'] ) > 0 );

// ── 2. Verify post content was NOT changed (dry run) ─────────────────────────
echo "\n-- Test 2: Post content unchanged after dry run --\n";

$posts_with_idx = $wpdb->get_results(
    "SELECT ID, post_content FROM {$wpdb->posts}
     WHERE post_status = 'publish'
       AND post_content LIKE '%impress_property_showcase%'"
);

test( 'Seeded posts still contain original shortcodes', count( $posts_with_idx ) >= 2 );

foreach ( $posts_with_idx as $p ) {
    $has_old = strpos( $p->post_content, 'impress_property_showcase' ) !== false;
    $has_new = strpos( $p->post_content, 'optima_express_toppicks' )   !== false;
    test( "Post #{$p->ID} still has impress_property_showcase (not replaced)", $has_old );
    test( "Post #{$p->ID} does NOT yet have optima_express_toppicks",          ! $has_new );
}

// ── 3. Live run: apply replacements ──────────────────────────────────────────
echo "\n-- Test 3: Live run (writes to DB) --\n";

$result2 = idxihf_process_pairs( $pairs, 'search.testsite.com', false );

test( 'Live run: posts_updated >= 3',   $result2['posts_updated'] >= 3 );
test( 'Live run: widget converted',     $result2['widgets_converted'] >= 1 );
test( 'Live run: dry_run=false',        $result2['dry_run'] === false );

echo "\n-- Test 4: Verify post content was updated --\n";

$updated_posts = $wpdb->get_results(
    "SELECT ID, post_title, post_content FROM {$wpdb->posts}
     WHERE post_status = 'publish'
       AND post_content LIKE '%optima_express_toppicks%'"
);

test( 'At least 3 posts now contain optima_express_toppicks', count( $updated_posts ) >= 3 );

foreach ( $updated_posts as $p ) {
    $has_old = strpos( $p->post_content, 'impress_property_showcase' ) !== false;
    $has_new = strpos( $p->post_content, 'optima_express_toppicks' )   !== false;
    test( "Post #{$p->ID} \"{$p->post_title}\" has new shortcode",    $has_new );
    test( "Post #{$p->ID} \"{$p->post_title}\" old shortcode removed", ! $has_old );
}

// ── 5. Verify specific shortcode content ─────────────────────────────────────
echo "\n-- Test 5: Verify shortcode IDs are correct --\n";

foreach ( $updated_posts as $p ) {
    if ( strpos( $p->post_title, 'College Station' ) !== false ) {
        test( 'College Station post has id=9001', strpos( $p->post_content, 'id=9001' ) !== false );
    }
    if ( strpos( $p->post_title, 'Luxury' ) !== false ) {
        test( 'Luxury post has id=9002',          strpos( $p->post_content, 'id=9002' ) !== false );
    }
    if ( strpos( $p->post_title, 'Waterfront' ) !== false ) {
        test( 'Waterfront post has id=9003',      strpos( $p->post_content, 'id=9003' ) !== false );
    }
}

// ── 6. URL replacement ────────────────────────────────────────────────────────
echo "\n-- Test 6: Saved-link URLs replaced --\n";

$luxury = $wpdb->get_row(
    "SELECT post_content FROM {$wpdb->posts}
     WHERE post_title = 'Luxury Homes' AND post_status = 'publish'",
    ARRAY_A
);

if ( $luxury ) {
    test( 'Luxury post: old URL removed',   strpos( $luxury['post_content'], 'search.testsite.com/i/luxury-homes' ) === false );
    test( 'Luxury post: new URL inserted',  strpos( $luxury['post_content'], 'testsite.com/luxury/' ) !== false );
}

// ── 7. About Us post untouched ────────────────────────────────────────────────
echo "\n-- Test 7: Clean post not modified --\n";

$about = $wpdb->get_row(
    "SELECT post_content FROM {$wpdb->posts}
     WHERE post_title = 'About Us' AND post_status = 'publish'",
    ARRAY_A
);

if ( $about ) {
    test( 'About Us: no IDX or iHF shortcodes', strpos( $about['post_content'], 'optima_express' ) === false );
}

// ── 8. Widget converted ───────────────────────────────────────────────────────
echo "\n-- Test 8: Widget conversion --\n";

$text_widgets = get_option( 'widget_text', [] );
$found_sc     = false;
foreach ( $text_widgets as $inst ) {
    if ( is_array( $inst ) && strpos( $inst['text'] ?? '', 'optima_express_toppicks' ) !== false ) {
        $found_sc = true;
        break;
    }
}
test( 'A Text widget with optima_express_toppicks shortcode was created', $found_sc );

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n";
echo "===========================================\n";
echo "  Results: {$pass} passed, {$fail} failed\n";
echo "===========================================\n\n";

exit( $fail > 0 ? 1 : 0 );
