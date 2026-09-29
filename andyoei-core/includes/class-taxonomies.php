<?php
defined( 'ABSPATH' ) || exit;

/**
 * Controlled vocabularies behind the building filters. Short, fixed lists
 * with no page of their own — unlike neighborhoods, which are a post type.
 */
class AO_Taxonomies {

	public static function register() {
		// 02A filters — internal vocabularies, surfacing only in the drawer.
		// Neighborhoods are not among them: they are a post type, so each one
		// is built in the block editor. See AO_Relations.
		register_taxonomy( 'ao_height', array( 'ao_building' ), self::args( 'Height', 'Height' ) );
		register_taxonomy( 'ao_construction', array( 'ao_building' ), self::args( 'Construction', 'Construction' ) );
		register_taxonomy( 'ao_feature', array( 'ao_building' ), self::args( 'Feature', 'Features' ) );
		register_taxonomy( 'ao_status', array( 'ao_building' ), self::args( 'Status', 'Status' ) );
		register_taxonomy( 'ao_view', array( 'ao_building' ), self::args( 'View', 'Views' ) );
	}

	private static function args( $single, $plural, $args = array() ) {
		return array_merge( array(
			'labels'            => array(
				'name'          => $plural,
				'singular_name' => $single,
				'search_items'  => 'Search ' . $plural,
				'all_items'     => 'All ' . $plural,
				'edit_item'     => 'Edit ' . $single,
				'add_new_item'  => 'Add New ' . $single,
			),
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'hierarchical'      => false,
			'rewrite'           => false,
		), $args );
	}

	/**
	 * The launch vocabularies from the concept document. Runs on activation
	 * only; editors own the lists afterwards.
	 */
	/**
	 * The sixteen neighborhoods from the concept document, as posts. Only
	 * created where one of that name is not already there, so this is safe to
	 * run on every activation.
	 */
	public static function seed_neighborhoods() {
		$names = array(
			'Art Museum Area', 'Avenue of the Arts', 'Bella Vista', 'Chinatown',
			'Fairmount', 'Fishtown', 'Fitler Square', 'Graduate Hospital',
			'Logan Square', 'Northern Liberties', 'Old City', 'Queen Village',
			'Rittenhouse Square', 'Society Hill', 'Washington Square', 'Washington Square West',
		);

		foreach ( $names as $name ) {
			$existing = get_posts( array(
				'post_type'      => 'ao_neighborhood',
				'post_status'    => 'any',
				'name'           => sanitize_title( $name ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );

			if ( $existing ) {
				continue;
			}

			wp_insert_post( array(
				'post_type'   => 'ao_neighborhood',
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_name'   => sanitize_title( $name ),
			) );
		}
	}

	public static function seed_terms() {
		$seeds = array(
			'ao_height'       => array( 'Low-Rise', 'Mid-Rise', 'High-Rise' ),
			'ao_construction' => array( 'New Construction', 'Newer Construction' ),
			'ao_feature'      => array(
				'Doorman / Concierge', 'Fitness Center', 'Parking', 'Elevator',
				'Pets Allowed', 'Swimming Pool', 'Outdoor Space', 'Storage',
			),
			'ao_status'       => array( 'Now Selling', 'Pre-Construction', 'Under Construction', 'Move-In Ready' ),
			'ao_view'         => array( 'Skyline', 'River', 'Park', 'City', 'Courtyard' ),
		);

		foreach ( $seeds as $tax => $terms ) {
			foreach ( $terms as $term ) {
				if ( ! term_exists( $term, $tax ) ) {
					wp_insert_term( $term, $tax );
				}
			}
		}
	}
}
