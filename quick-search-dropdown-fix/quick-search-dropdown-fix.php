<?php
/**
 * Plugin Name: Quick Search Dropdown Fix
 * Description: Fixes iHomeFinder dropdown menus appearing behind the theme's .c-wrap wave (z-index:999). Lowers .c-wrap via CSS and raises the iHF shadow host via JS.
 * Version: 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function qsdf_enqueue_assets() {
    wp_enqueue_style(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.css',
        array(),
        '3.0.0'
    );

    wp_enqueue_script(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.js',
        array(),
        '3.0.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'qsdf_enqueue_assets' );
