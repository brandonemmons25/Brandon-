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
				'per_page'     => 24, // ~24, then Load More.
				'card'         => 'card-building.php',
				'search'       => true,
				'search_label' => 'Search Buildings',
				'count_label'  => array( 'Building', 'Buildings' ),
				// Alphabetical headers are off: the grid runs continuously.
				// Set this to 'letter' via the ao_directories filter to
				// bring them back.
				'group_by'     => '',
				'facets'       => array(
					'neighborhood' => array(
						'taxonomy' => 'ao_neighborhood',
						'label'    => 'Neighborhood',
						'operator' => 'IN',  // OR within the group.
					),
					'height'       => array(
						'taxonomy' => 'ao_height',
						'label'    => 'Height',
						'operator' => 'IN',
					),
					'construction' => array(
						'taxonomy' => 'ao_construction',
						'label'    => 'Construction',
						'operator' => 'IN',
					),
					'features'     => array(
						'taxonomy' => 'ao_feature',
						'label'    => 'Features',
						'operator' => 'AND', // Must have every selected feature.
					),
				),
				'sorts'        => array(
					'name_asc'  => 'A–Z',
					'name_desc' => 'Z–A',
					'nbhd_asc'  => 'Neighborhood A–Z',
				),
				'default_sort' => 'name_asc',
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
