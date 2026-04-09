<?php
/**
 * Plugin Name: Mobile Optimize
 * Description: Hamburger menu and mobile layout fixes for CB Intermountain Realty.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mo_enqueue() {
    wp_enqueue_style(
        'mobile-optimize',
        plugin_dir_url( __FILE__ ) . 'mobile-optimize.css',
        array(),
        '1.0.0'
    );
    wp_enqueue_script(
        'mobile-optimize',
        plugin_dir_url( __FILE__ ) . 'mobile-optimize.js',
        array(),
        '1.0.0',
        true
    );
}
add_action( 'wp_enqueue_scripts', 'mo_enqueue' );
