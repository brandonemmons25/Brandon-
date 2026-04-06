<?php
/**
 * Plugin Name: iHomeFinder Full Width
 * Description: Makes all iHomeFinder (IDX) pages display full width by hiding the sidebar.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect if the current page contains an iHomeFinder shortcode.
 * Supports both classic shortcodes [ihf-*] and the iHomeFinder
 * block/widget approach where content is stored in post meta.
 */
function ihf_fw_is_ihomefinder_page() {
    if ( ! is_singular() ) {
        return false;
    }

    $post = get_queried_object();
    if ( ! $post || ! isset( $post->post_content ) ) {
        return false;
    }

    $content = $post->post_content;

    // Classic shortcode: [ihf-mortgage-calculator], [ihf-search], etc.
    if ( strpos( $content, '[ihf-' ) !== false ) {
        return true;
    }

    // iHomeFinder v4+ uses a block with class name ihf/widget
    if ( strpos( $content, '"ihf/' ) !== false ) {
        return true;
    }

    // iHomeFinder stores its page type in post meta
    $ihf_meta = get_post_meta( $post->ID, '_ihf_page_type', true );
    if ( ! empty( $ihf_meta ) ) {
        return true;
    }

    // Fallback: check if any registered iHomeFinder shortcode is present
    global $shortcode_tags;
    foreach ( array_keys( (array) $shortcode_tags ) as $tag ) {
        if ( strpos( $tag, 'ihf' ) === 0 && has_shortcode( $content, $tag ) ) {
            return true;
        }
    }

    return false;
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
            '1.1.0'
        );
    }
}
add_action( 'wp_enqueue_scripts', 'ihf_fw_enqueue_styles' );
