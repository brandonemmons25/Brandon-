<?php
/**
 * Registers the Scroll Section custom post type.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSD_Post_Type {

    /**
     * Register the scroll_section post type.
     */
    public static function register() {
        $labels = array(
            'name'               => 'Scroll Sections',
            'singular_name'      => 'Scroll Section',
            'add_new'            => 'Add New Section',
            'add_new_item'       => 'Add New Scroll Section',
            'edit_item'          => 'Edit Scroll Section',
            'new_item'           => 'New Scroll Section',
            'view_item'          => 'View Scroll Section',
            'search_items'       => 'Search Scroll Sections',
            'not_found'          => 'No scroll sections found',
            'not_found_in_trash' => 'No scroll sections found in Trash',
            'menu_name'          => 'Scroll Sections',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-slides',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
            'has_archive'        => false,
            'rewrite'            => false,
            'show_in_rest'       => true,
            'hierarchical'       => false,
        );

        register_post_type( 'scroll_section', $args );

        // Register the "Section Group" taxonomy for organizing sections into pages.
        register_taxonomy( 'section_group', 'scroll_section', array(
            'labels' => array(
                'name'          => 'Section Groups',
                'singular_name' => 'Section Group',
                'add_new_item'  => 'Add New Section Group',
                'edit_item'     => 'Edit Section Group',
                'search_items'  => 'Search Section Groups',
            ),
            'public'            => false,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'hierarchical'      => true,
            'rewrite'           => false,
        ) );
    }
}
