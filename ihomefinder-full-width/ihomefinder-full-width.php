<?php
/**
 * Plugin Name: iHomeFinder Full Width
 * Description: Makes all iHomeFinder (IDX) pages display full width by hiding the sidebar.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect if the current page contains an iHomeFinder shortcode.
 */
function ihf_fw_is_ihomefinder_page() {
    if ( ! is_singular() ) {
        return false;
    }

    $post = get_queried_object();
    if ( ! $post || ! isset( $post->post_content ) ) {
        return false;
    }

    // iHomeFinder shortcodes all begin with [ihf-
    return has_shortcode( $post->post_content, 'ihf-home' )
        || strpos( $post->post_content, '[ihf-' ) !== false;
}

/**
 * Add a body class so CSS can target iHomeFinder pages.
 */
function ihf_fw_body_class( $classes ) {
    if ( ihf_fw_is_ihomefinder_page() ) {
        $classes[] = 'ihf-full-width';
    }
    return $classes;
}
add_filter( 'body_class', 'ihf_fw_body_class' );

/**
 * Enqueue the full-width stylesheet on iHomeFinder pages.
 */
function ihf_fw_enqueue_styles() {
    if ( ihf_fw_is_ihomefinder_page() ) {
        wp_enqueue_style(
            'ihf-full-width',
            plugin_dir_url( __FILE__ ) . 'ihomefinder-full-width.css',
            array(),
            '1.0.0'
        );
    }
}
add_action( 'wp_enqueue_scripts', 'ihf_fw_enqueue_styles' );
