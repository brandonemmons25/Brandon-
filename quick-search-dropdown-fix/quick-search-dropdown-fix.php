<?php
/**
 * Plugin Name: Quick Search Dropdown Fix
 * Description: Fixes iHomeFinder dropdown menus appearing behind the theme's .c-wrap wave element by raising #quick-search's z-index only while a dropdown is open.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function qsdf_enqueue_assets() {
    wp_enqueue_style(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.css',
        array(),
        '1.1.0'
    );

    wp_enqueue_script(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.js',
        array(),
        '1.1.0',
        true // footer
    );
}
add_action( 'wp_enqueue_scripts', 'qsdf_enqueue_assets' );
