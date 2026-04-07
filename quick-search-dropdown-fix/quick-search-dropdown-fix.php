<?php
/**
 * Plugin Name: Quick Search Dropdown Fix
 * Description: Fixes iHomeFinder dropdown menus appearing behind the theme's .c-wrap wave element. Pure CSS — removes .ct-tagline from the stacking context so #quick-search naturally appears above it.
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function qsdf_enqueue_styles() {
    wp_enqueue_style(
        'quick-search-dropdown-fix',
        plugin_dir_url( __FILE__ ) . 'quick-search-dropdown-fix.css',
        array(),
        '2.0.0'
    );
}
add_action( 'wp_enqueue_scripts', 'qsdf_enqueue_styles' );
