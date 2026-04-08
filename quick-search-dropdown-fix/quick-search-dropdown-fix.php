<?php
/**
 * Plugin Name: Quick Search Dropdown Fix
 * Description: Fixes iHomeFinder dropdown menus appearing behind the theme's .c-wrap wave. Wave design preserved.
 * Version: 5.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function qsdf_enqueue_assets() {
    wp_enqueue_style(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.css',
        array(),
        '5.3.0'
    );

    wp_enqueue_script(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.js',
        array(),
        '5.3.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'qsdf_enqueue_assets' );
