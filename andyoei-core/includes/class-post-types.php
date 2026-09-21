<?php
defined( 'ABSPATH' ) || exit;

/**
 * Buildings are the only post type. Neighborhoods are a taxonomy, so a single
 * record drives the building filter, the directory card and the neighborhood
 * page.
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
	}
}
