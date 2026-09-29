<?php
defined( 'ABSPATH' ) || exit;

/**
 * Buildings and neighborhoods are both post types, so both are built in the
 * block editor with the same sections and blocks. A building points at its
 * neighborhood through the ao_neighborhood meta key, holding that
 * neighborhood's post ID — see AO_Relations.
 */
class AO_Post_Types {

	public static function register() {
		register_post_type( 'ao_building', array(
			'labels'             => array(
				'name'          => 'Condominium Buildings',
				'singular_name' => 'Condominium Building',
				'add_new_item'  => 'Add New Building',
				'edit_item'     => 'Edit Building',
				'search_items'  => 'Search Buildings',
				'not_found'     => 'No buildings found',
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_rest'       => true,
			'menu_icon'          => 'dashicons-building',
			'has_archive'        => false, // The directory is a built page.
			'hierarchical'       => false,
			'rewrite'            => array( 'slug' => 'buildings', 'with_front' => false ),
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
		) );

		register_post_type( 'ao_neighborhood', array(
			'labels'             => array(
				'name'          => 'Neighborhoods',
				'singular_name' => 'Neighborhood',
				'add_new_item'  => 'Add New Neighborhood',
				'edit_item'     => 'Edit Neighborhood',
				'search_items'  => 'Search Neighborhoods',
				'not_found'     => 'No neighborhoods found',
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_rest'       => true,
			'menu_icon'          => 'dashicons-location-alt',
			'has_archive'        => false,
			'hierarchical'       => false,
			'rewrite'            => array( 'slug' => 'neighborhoods', 'with_front' => false ),
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
		) );
	}
}
