<?php
/**
 * Plugin Name: Button Link Scanner
 * Plugin URI:  https://github.com/brandonemmons25/brandon-
 * Description: Scans all WordPress pages, posts, and custom post types for buttons. Checks for missing hyperlinks and SEO title attributes, analyzes link trends, allows bulk link assignment via a Button Map, and verifies Gravity Forms confirmations redirect to child thank-you pages.
 * Version:     1.1.1
 * Author:      Brandon Emmons
 * License:     GPL-2.0+
 * Text Domain: button-link-scanner
 */

defined( 'ABSPATH' ) || exit;

define( 'BLS_VERSION',     '1.1.1' );
define( 'BLS_PLUGIN_FILE', __FILE__ );
define( 'BLS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BLS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once BLS_PLUGIN_DIR . 'includes/class-bls-database.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-gf-database.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-scanner.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-gf-scanner.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-updater.php';
require_once BLS_PLUGIN_DIR . 'includes/class-bls-admin.php';

register_activation_hook( __FILE__, function () {
    BLS_Database::install();
    BLS_GF_Database::install();
} );
register_deactivation_hook( __FILE__, [ 'BLS_Database', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    BLS_Admin::init();
} );

// Scheduled scan hook.
add_action( 'bls_scheduled_scan', function () {
    $scanner = new BLS_Scanner();
    $scanner->run_full_scan();
} );
