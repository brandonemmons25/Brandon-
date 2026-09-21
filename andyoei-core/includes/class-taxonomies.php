<?php
defined( 'ABSPATH' ) || exit;

/**
 * Controlled vocabularies. Everything a visitor can filter by is a taxonomy —
 * that is what keeps the filter query fast and the counts accurate.
 */
class AO_Taxonomies {

	public static function register() {
		// 03 — Neighborhoods. Public, because each term is a page.
		register_taxonomy( 'ao_neighborhood', array( 'ao_building' ), self::args( 'Neighborhood', 'Neighborhoods', array(
			'public'       => true,
			'hierarchical' => true,
			'rewrite'      => array( 'slug' => 'neighborhoods', 'with_front' => false ),
		) ) );

		// 02A filters. Internal vocabularies; they surface only in the drawer.
		register_taxonomy( 'ao_height', array( 'ao_building' ), self::args( 'Height', 'Height' ) );
		register_taxonomy( 'ao_construction', array( 'ao_building' ), self::args( 'Construction', 'Construction' ) );
		register_taxonomy( 'ao_feature', array( 'ao_building' ), self::args( 'Feature', 'Features' ) );
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
	public static function seed_terms() {
		$seeds = array(
			'ao_neighborhood' => array(
				'Art Museum Area', 'Avenue of the Arts', 'Bella Vista', 'Chinatown',
				'Fairmount', 'Fishtown', 'Fitler Square', 'Graduate Hospital',
				'Logan Square', 'Northern Liberties', 'Old City', 'Queen Village',
				'Rittenhouse Square', 'Society Hill', 'Washington Square', 'Washington Square West',
			),
			'ao_height'       => array( 'Low-Rise', 'Mid-Rise', 'High-Rise' ),
			'ao_construction' => array( 'New Construction', 'Newer Construction' ),
			'ao_feature'      => array(
				'Doorman / Concierge', 'Fitness Center', 'Parking', 'Elevator',
				'Pets Allowed', 'Swimming Pool', 'Outdoor Space', 'Storage',
			),
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
