<?php
/**
 * Plugin Name: Mobile CSS Audit (MCA)
 * Description: Scans pages for mobile layout issues at 375px. Attach ?mca_probe=1 to any URL to scan it. Visit WP Admin > Tools > Mobile CSS Audit to run a full-site scan.
 * Version: 2.5.1
 * Author: iMFORZA
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MCA_VERSION', '2.5.1' );
define( 'MCA_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCA_URL', plugin_dir_url( __FILE__ ) );

require_once MCA_DIR . 'includes/class-mca-probe.php';
require_once MCA_DIR . 'includes/class-mca-admin.php';

add_action( 'plugins_loaded', function () {
    MCA_Probe::init();
    MCA_Admin::init();
} );
