<?php
/**
 * Plugin Name: Button Link Scanner
 * Plugin URI:  https://github.com/brandonemmons25/brandon-
 * Description: Audits every button and hyperlink across pages, posts and custom post types by reading the live page as a visitor sees it. Flags buttons with no link and links with no SEO title attribute, then fills those titles in bulk — into content, at render time where there is no stored anchor. Monitors every linked URL in the background and emails the moment one breaks, grouped by cause, with tools to repoint an address site-wide, bulk-upgrade http to https, or unlink a dead destination while keeping the words. Includes Gravity Forms confirmation checks, a Button Map for bulk link assignment, usage trends, and CSV exports.
 * Version:     1.75
 * Author:      Brandon Emmons
 * License:     GPL-2.0+
 * Text Domain: button-link-scanner
 */

defined( 'ABSPATH' ) || exit;

define( 'BLS_VERSION',     '1.75' );
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

    // Keep the schema current on upgrade, not only on activation.
    // Replacing the plugin folder or updating in place does not always fire
    // the activation hook, so a release that adds a column would otherwise
    // write to a table that has not got it yet. dbDelta is safe to re-run and
    // this only does anything when the stored version differs.
    if ( get_option( 'bls_db_version' ) !== BLS_VERSION ) {
        BLS_Database::install();
        BLS_GF_Database::install();
    }
} );

// Broken-link monitoring: cron hooks + keep the schedule in sync with
// whatever frequency is set on the dashboard.
add_action( 'bls_link_check_start', [ 'BLS_Link_Checker', 'start_check' ] );
add_action( 'bls_link_check_tick',  [ 'BLS_Link_Checker', 'run_tick' ] );
add_action( 'admin_init', [ 'BLS_Link_Checker', 'maybe_schedule' ] );
