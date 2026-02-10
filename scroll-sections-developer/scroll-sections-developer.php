<?php
/**
 * Plugin Name: Scroll Sections Developer
 * Plugin URI:  https://github.com/brandonemmons25/Brandon-
 * Description: Full-screen parallax scroll sections with GSAP ScrollTrigger animations. Inspired by ericprydz.com. Adds a custom post type for content entry and a shortcode to render immersive scrolling pages.
 * Version:     1.0.0
 * Author:      Brandon Emmons
 * License:     GPL-2.0-or-later
 * Text Domain: scroll-sections-developer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SSD_VERSION', '1.0.0' );
define( 'SSD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SSD_PLUGIN_DIR . 'includes/class-ssd-post-type.php';
require_once SSD_PLUGIN_DIR . 'includes/class-ssd-meta-boxes.php';
require_once SSD_PLUGIN_DIR . 'includes/class-ssd-renderer.php';

/**
 * Initialize the plugin.
 */
function ssd_init() {
    SSD_Post_Type::register();
    SSD_Meta_Boxes::init();
    SSD_Renderer::init();
}
add_action( 'init', array( 'SSD_Post_Type', 'register' ) );
add_action( 'add_meta_boxes', array( 'SSD_Meta_Boxes', 'add' ) );
add_action( 'save_post', array( 'SSD_Meta_Boxes', 'save' ), 10, 2 );
add_action( 'init', array( 'SSD_Renderer', 'init' ) );

/**
 * Enqueue admin assets.
 */
function ssd_admin_enqueue( $hook ) {
    $screen = get_current_screen();
    if ( $screen && $screen->post_type === 'scroll_section' ) {
        wp_enqueue_media();
        wp_enqueue_style(
            'ssd-admin',
            SSD_PLUGIN_URL . 'admin/admin.css',
            array(),
            SSD_VERSION
        );
        wp_enqueue_script(
            'ssd-admin',
            SSD_PLUGIN_URL . 'admin/admin.js',
            array( 'jquery' ),
            SSD_VERSION,
            true
        );
    }
}
add_action( 'admin_enqueue_scripts', 'ssd_admin_enqueue' );

/**
 * On activation, flush rewrite rules so the CPT permalink works.
 */
function ssd_activate() {
    SSD_Post_Type::register();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ssd_activate' );

/**
 * On deactivation, clean up rewrite rules.
 */
function ssd_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ssd_deactivate' );
