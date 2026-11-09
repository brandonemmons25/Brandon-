<?php
defined( 'ABSPATH' ) || exit;

/**
 * Directory configuration.
 *
 * Adding a filter means adding a line here — the filter bar, the query, the
 * REST endpoint and Load More all read from this.
 */
class AO_Directories {

	private static $directories = array();

	public static function register() {
		self::$directories = array(

			// 02A — Condominium Building Directory.
			'buildings' => array(
				'post_type'    => 'ao_building',
				// Every building renders: the grouped scroll is the page,
				// and a group cut in half by pagination is not.
				'per_page'     => -1,
				'card'         => 'card-building.php',
				'search'       => true,
				'search_label' => 'Search building or address…',
				'count_label'  => array( 'Building', 'Buildings' ),
				// Results are grouped under a neighborhood heading, A–Z, and
				// the buildings inside each run A–Z ignoring a leading "The".
				'group_by'     => 'neighborhood',
				'facets'       => array(

					// ── In the bar, in this order after the search box ──
					// Each is a single choice, so the control reads as a
					// dropdown and its own wording sits inside it.
					'building'     => array(
						'self'      => true, // Narrows to one building.
						'post_type' => 'ao_building',
						'label'     => 'Select condominium',
						'all_label' => 'Select condominium',
						'single'    => true,
						'in_bar'    => true,
					),
					'area'         => array(
						'post_type' => 'ao_neighborhood',
						'meta_key'  => 'ao_neighborhood',
						'label'     => 'All neighborhoods',
						'all_label' => 'All neighborhoods',
						'single'    => true,
						'in_bar'    => true,
					),

					// ── Behind the Filters button ──
					// Only these two. Neighborhood is in the bar, and height
					// is gone entirely.
					'construction' => array(
						// Measured against today rather than stored as a
						// term: a building moves from New to Newer on its own
						// as time passes, with nobody re-tagging it.
						'type'      => 'age',
						'meta_key'  => AO_Index::OCCUPANCY,
						'label'     => 'Construction',
						'all_label' => 'All',
						'single'    => true, // Mutually exclusive.
						'options'   => array(
							'new'   => array(
								'label'     => 'New Construction',
								'note'      => '< 2 years',
								'max_years' => 2,
							),
							'newer' => array(
								'label'     => 'Newer Construction',
								'note'      => '2 to < 15 years',
								'min_years' => 2,
								'max_years' => 15,
							),
						),
					),
					'features'     => array(
						'taxonomy'  => 'ao_feature',
						'label'     => 'Features & Amenities',
						'operator'  => 'AND', // Must have every one selected.
						'all_label' => 'All',
					),
				),
				'sorts'        => array(),
				'default_sort' => 'nbhd_asc',
			),
		);

		/**
		 * Filter the directory configuration.
		 */
		self::$directories = apply_filters( 'ao_directories', self::$directories );
	}

	public static function all() {
		return self::$directories;
	}

	public static function get( $key ) {
		if ( ! isset( self::$directories[ $key ] ) ) {
			return null;
		}

		$config        = self::$directories[ $key ];
		$config['key'] = $key;

		return wp_parse_args( $config, array(
			'per_page'     => 24,
			'card'         => 'card-building.php',
			'search'       => false,
			'search_label' => 'Search',
			'count_label'  => array( 'Result', 'Results' ),
			'facets'       => array(),
			'sorts'        => array(),
			'default_sort' => '',
			'group_by'     => '',
		) );
	}
}
