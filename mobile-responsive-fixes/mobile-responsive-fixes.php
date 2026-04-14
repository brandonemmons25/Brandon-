<?php
/**
 * Plugin Name: Mobile Responsive Fixes
 * Description: Hamburger nav + correct mobile layout order for Intermountain Realty. Derived from Mobile CSS Auditor scan.
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mrf_enqueue_assets() {
    wp_enqueue_style(
        'mobile-responsive-fixes',
        plugin_dir_url( __FILE__ ) . 'mobile-responsive-fixes.css',
        array(),
        '2.0.0'
    );

    wp_enqueue_script(
        'mobile-responsive-fixes',
        plugin_dir_url( __FILE__ ) . 'mobile-responsive-fixes.js',
        array(),
        '2.0.0',
        true // load in footer
    );
}
add_action( 'wp_enqueue_scripts', 'mrf_enqueue_assets' );
