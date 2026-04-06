<?php
/**
 * Plugin Name: iHomeFinder Full Width
 * Description: Makes all iHomeFinder (IDX) pages display full width by hiding the sidebar.
 * Version: 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Always enqueue the CSS and the JS detector on every singular page.
 * The JS checks for iHomeFinder's rendered markup at runtime and
 * adds the `ihf-full-width` body class when found — no PHP shortcode
 * detection required.
 */
function ihf_fw_enqueue_assets() {
    if ( ! is_singular() ) {
        return;
    }

    wp_enqueue_style(
        'ihf-full-width',
        plugin_dir_url( __FILE__ ) . 'ihomefinder-full-width.css',
        array(),
        '1.2.0'
    );

    wp_enqueue_script(
        'ihf-full-width',
        plugin_dir_url( __FILE__ ) . 'ihomefinder-full-width.js',
        array(),
        '1.2.0',
        false // load in <head> so class is set before paint
    );
}
add_action( 'wp_enqueue_scripts', 'ihf_fw_enqueue_assets' );
