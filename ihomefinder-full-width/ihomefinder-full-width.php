<?php
/**
 * Plugin Name: iHomeFinder Full Width
 * Description: Makes all iHomeFinder (IDX) pages and the homepage display full width by hiding the sidebar.
 * Version: 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Add ihf-full-width body class on the front page (PHP-detected)
 * and on any singular page (JS-detected at runtime via iHF markup).
 */
function ihf_fw_body_class( $classes ) {
    if ( is_front_page() ) {
        $classes[] = 'ihf-full-width';
    }
    return $classes;
}
add_filter( 'body_class', 'ihf_fw_body_class' );

/**
 * Enqueue assets on the front page and all singular pages.
 */
function ihf_fw_enqueue_assets() {
    if ( ! is_front_page() && ! is_singular() ) {
        return;
    }

    wp_enqueue_style(
        'ihf-full-width',
        plugin_dir_url( __FILE__ ) . 'ihomefinder-full-width.css',
        array(),
        '1.3.0'
    );

    wp_enqueue_script(
        'ihf-full-width',
        plugin_dir_url( __FILE__ ) . 'ihomefinder-full-width.js',
        array(),
        '1.3.0',
        false // load in <head> so class is set before paint
    );
}
add_action( 'wp_enqueue_scripts', 'ihf_fw_enqueue_assets' );
