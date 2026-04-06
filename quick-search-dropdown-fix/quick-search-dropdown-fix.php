<?php
/**
 * Plugin Name: Quick Search Dropdown Fix
 * Description: Fixes iHomeFinder dropdown menus appearing behind the theme's .c-wrap wave element by raising #quick-search's z-index only while a dropdown is open.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function qsdf_enqueue_styles() {
    wp_enqueue_style(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.css',
        array(),
        '1.0.0'
    );
}
add_action( 'wp_enqueue_scripts', 'qsdf_enqueue_styles' );
