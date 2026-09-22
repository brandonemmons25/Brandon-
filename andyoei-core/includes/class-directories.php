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
				// No search box and no sort control in the bar; the directory
				// still orders A–Z underneath, ignoring a leading "The".
				'search'       => false,
				'search_label' => 'Search Buildings',
				'count_label'  => array( 'Building', 'Buildings' ),
				// Alphabetical headers are off: the grid runs continuously.
				// Set this to 'letter' via the ao_directories filter to
				// bring them back.
				'group_by'     => '',
				'facets'       => array(

					// In the bar, in this order.
					'completion'   => array(
						'type'      => 'range', // Buckets over a numeric field.
						'meta_key'  => 'ao_completion',
						'label'     => 'Built Date',
						'all_label' => 'Any Year',
						'single'    => true,
						'in_bar'    => true,
						'options'   => array(
							'2026-plus' => array( 'label' => '2026 & Beyond', 'min' => 2026, 'max' => 0 ),
							'2020-2025' => array( 'label' => '2020 – 2025', 'min' => 2020, 'max' => 2025 ),
							'2010-2019' => array( 'label' => '2010 – 2019', 'min' => 2010, 'max' => 2019 ),
							'2000-2009' => array( 'label' => '2000 – 2009', 'min' => 2000, 'max' => 2009 ),
							'pre-2000'  => array( 'label' => 'Before 2000', 'min' => 0, 'max' => 1999 ),
						),
					),
					'area'         => array(
						'taxonomy'  => 'ao_neighborhood',
						'label'     => 'Areas',
						'operator'  => 'IN',   // OR within the group.
						'all_label' => 'All Areas',
						'in_bar'    => true,
					),
					'views'        => array(
						'taxonomy'  => 'ao_view',
						'label'     => 'Views',
						'operator'  => 'IN',
						'all_label' => 'All',
						'in_bar'    => true,
					),

					// Behind the Filters button.
					'status'       => array(
						'taxonomy'  => 'ao_status',
						'label'     => 'Status',
						'operator'  => 'IN',
						'all_label' => 'All',
					),
					'height'       => array(
						'taxonomy'  => 'ao_height',
						'label'     => 'Height',
						'operator'  => 'IN',
						'all_label' => 'All',
					),
					'construction' => array(
						'taxonomy'  => 'ao_construction',
						'label'     => 'Construction',
						'operator'  => 'IN',
						'all_label' => 'All',
					),
					'features'     => array(
						'taxonomy'  => 'ao_feature',
						'label'     => 'Features',
						'operator'  => 'AND', // Must have every selected feature.
						'all_label' => 'All',
					),
				),
				'sorts'        => array(),
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
