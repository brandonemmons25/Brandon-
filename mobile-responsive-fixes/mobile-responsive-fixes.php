<?php
/**
 * Plugin Name: Mobile Responsive Fixes
 * Description: Targeted mobile CSS fixes derived from Mobile CSS Auditor scan. Eliminates horizontal overflow on the homepage (1040 px) and Maps – Fall River Valley (545 px), and applies global mobile best-practices for images and floats.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function mrf_enqueue_assets() {
    wp_enqueue_style(
        'mobile-responsive-fixes',
        plugin_dir_url( __FILE__ ) . 'mobile-responsive-fixes.css',
        array(),
        '1.0.0'
    );
}
add_action( 'wp_enqueue_scripts', 'mrf_enqueue_assets' );
