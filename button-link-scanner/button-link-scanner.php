<?php
/**
 * Plugin Name: Button Link Scanner
 * Plugin URI:  https://github.com/brandonemmons25/brandon-
 * Description: Scans all WordPress pages, posts, and custom post types for buttons. Checks for missing hyperlinks and SEO title attributes, tracks link usage trends, allows bulk link assignment via a Button Map, verifies Gravity Forms confirmations redirect to child thank-you pages, and monitors linked URLs in the background — emailing and posting a dashboard notice the moment a link actually breaks (404/error/timeout).
 * Version:     1.43
 * Author:      Brandon Emmons
 * License:     GPL-2.0+
 * Text Domain: button-link-scanner
 */

defined( 'ABSPATH' ) || exit;

define( 'BLS_VERSION',     '1.43' );
define( 'BLS_PLUGIN_FILE', __FILE__ );
define( 'BLS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BLS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once BLS_PLUGIN_DIR . 'includes/class-bls-database.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-gf-database.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-scanner.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-gf-scanner.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-link-checker.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-updater.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-render-injector.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-admin.php';

BLS_Render_Injector::init();

register_activation_hook( __FILE__, function () {
    BLS_Database::install();
    BLS_GF_Database::install();
} );
register_deactivation_hook( __FILE__, function () {
    BLS_Database::deactivate();
    wp_clear_scheduled_hook( 'bls_scheduled_scan' ); // cleanup for anyone upgrading from an older version
} );

add_action( 'plugins_loaded', function () {
    BLS_Admin::init();
} );

// Broken-link monitoring: cron hooks + keep the schedule in sync with
// whatever frequency is set on the dashboard.
add_action( 'bls_link_check_start', [ 'BLS_Link_Checker', 'start_check' ] );
add_action( 'bls_link_check_tick',  [ 'BLS_Link_Checker', 'run_tick' ] );
add_action( 'admin_init', [ 'BLS_Link_Checker', 'maybe_schedule' ] );
