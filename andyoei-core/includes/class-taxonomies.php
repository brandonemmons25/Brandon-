<?php
defined( 'ABSPATH' ) || exit;

/**
 * Controlled vocabularies. Anything a visitor can filter by is a taxonomy —
 * that is what makes the directory filters fast and the counts accurate.
 *
 * Neighborhoods are a taxonomy, not a post type: one record drives the
 * building filter, the neighborhood card, and the individual neighborhood
 * page (rendered from the term archive with ACF term fields).
 */
class AO_Taxonomies {

	public static function register() {
		foreach ( self::definitions() as $tax => $def ) {
			register_taxonomy( $tax, $def['object_types'], $def['args'] );
		}
	}

	private static function definitions() {
		$building = array( 'ao_building' );

		return array(

			// 03 Neighborhoods — filter + directory + individual page, one record.
			'ao_neighborhood' => array(
				'object_types' => array( 'ao_building', 'ao_sold', 'ao_press', 'ao_insight', 'ao_case_study', 'ao_testimonial' ),
				'args'         => self::args( 'Neighborhood', 'Neighborhoods', array(
					'rewrite'      => array( 'slug' => 'neighborhoods', 'with_front' => false ),
					'public'       => true,
					'hierarchical' => true,
				) ),
			),

			// 02A filter: Height — OR within the group.
			'ao_height'       => array(
				'object_types' => $building,
				'args'         => self::args( 'Height', 'Height', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 02A filter: Construction — OR within the group.
			'ao_construction' => array(
				'object_types' => $building,
				'args'         => self::args( 'Construction', 'Construction', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 02A filter: Features — AND (a building must have all selected).
			'ao_feature'      => array(
				'object_types' => $building,
				'args'         => self::args( 'Feature', 'Features', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 07C — exactly three press categories. Featured is a field, not a category.
			'ao_press_category' => array(
				'object_types' => array( 'ao_press' ),
				'args'         => self::args( 'Press Category', 'Press Categories', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 06 — five insight categories.
			'ao_insight_category' => array(
				'object_types' => array( 'ao_insight' ),
				'args'         => self::args( 'Insight Category', 'Insight Categories', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 06 — controlled topics, shared across insights and press (Relocation lives here).
			'ao_topic'        => array(
				'object_types' => array( 'ao_insight', 'ao_press' ),
				'args'         => self::args( 'Topic', 'Topics', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 07B — buyer vs seller, so the right testimonial surfaces on the right page.
			'ao_client_type'  => array(
				'object_types' => array( 'ao_testimonial', 'ao_case_study' ),
				'args'         => self::args( 'Client Type', 'Client Types', array( 'public' => false, 'show_ui' => true ) ),
			),

			// 07D — where a proof point is allowed to appear.
			'ao_proof_context' => array(
				'object_types' => array( 'ao_proof' ),
				'args'         => self::args( 'Context', 'Contexts', array( 'public' => false, 'show_ui' => true ) ),
			),
		);
	}

	private static function args( $single, $plural, $args ) {
		$defaults = array(
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
		);

		return array_merge( $defaults, $args );
	}

	/**
	 * The launch vocabularies from the concept document.
	 * Runs on activation only; editors own the lists afterwards.
	 */
	public static function seed_terms() {
		$seeds = array(
			'ao_neighborhood'     => array(
				'Art Museum Area', 'Avenue of the Arts', 'Bella Vista', 'Chinatown',
				'Fairmount', 'Fishtown', 'Fitler Square', 'Graduate Hospital',
				'Logan Square', 'Northern Liberties', 'Old City', 'Queen Village',
				'Rittenhouse Square', 'Society Hill', 'Washington Square', 'Washington Square West',
			),
			'ao_height'           => array( 'Low-Rise', 'Mid-Rise', 'High-Rise' ),
			'ao_construction'     => array( 'New Construction', 'Newer Construction' ),
			'ao_feature'          => array(
				'Doorman / Concierge', 'Fitness Center', 'Parking', 'Elevator',
				'Pets Allowed', 'Swimming Pool', 'Outdoor Space', 'Storage',
			),
			'ao_press_category'   => array( 'Expert Commentary', 'Recognition', 'Property & Transaction Coverage' ),
			'ao_insight_category' => array(
				'Market Reports & Analysis', 'Condominium Insights',
				'Buyer Strategy', 'Seller Strategy', 'Luxury Market Trends',
			),
			'ao_topic'            => array( 'Relocation' ),
			'ao_client_type'      => array( 'Buyer', 'Seller' ),
			'ao_proof_context'    => array( 'About', 'Buyer', 'Seller', 'Credentials' ),
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
