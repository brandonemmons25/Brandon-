<?php
/**
 * Plugin Name: Kadence Block Scanner
 * Description: Scans all posts and pages for Kadence blocks and identifies broken or problematic elements.
 * Version: 1.1.0
 * Author: Brandon
 * Text Domain: kadence-block-scanner
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'KBS_VERSION', '1.1.0' );
define( 'KBS_DIR', plugin_dir_path( __FILE__ ) );
define( 'KBS_URL', plugin_dir_url( __FILE__ ) );

require_once KBS_DIR . 'includes/class-scanner.php';
require_once KBS_DIR . 'includes/class-checks.php';
require_once KBS_DIR . 'includes/class-admin.php';
require_once KBS_DIR . 'includes/class-rest-api.php';

add_action( 'plugins_loaded', array( 'KBS_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'KBS_Rest_API', 'init' ) );
