<?php
defined( 'ABSPATH' ) || exit;

/**
 * Directory configuration.
 *
 * One entry per filterable listing on the site. Everything else — the filter
 * bar, the query, the REST endpoint, the load-more — is driven from here, so
 * adding a filter means adding a line, not writing code.
 */
class AO_Directories {

	private static $directories = array();

	public static function register() {
		self::$directories = array(

			// 02A — Condominium Building Directory.
			'buildings'    => array(
				'post_type'    => 'ao_building',
				'per_page'     => 24,
				'card'         => 'card-building.php',
				'search'       => true,
				'search_label' => 'Search building name or address',
				'group_by'     => 'letter',
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
						'operator' => 'AND', // Must have all selected.
					),
				),
				'sorts'        => array(
					'name_asc'  => 'A–Z',
					'name_desc' => 'Z–A',
					'nbhd_asc'  => 'Neighborhood A–Z',
				),
				'default_sort' => 'name_asc',
			),

			// 01 — Sold Properties, high price → low.
			'sold'         => array(
				'post_type'    => 'ao_sold',
				'per_page'     => 18,
				'card'         => 'card-sold.php',
				'search'       => true,
				'search_label' => 'Search address or building',
				'facets'       => array(
					'neighborhood' => array(
						'taxonomy' => 'ao_neighborhood',
						'label'    => 'Neighborhood',
						'operator' => 'IN',
					),
				),
				'sorts'        => array(
					'price_desc' => 'Price: High to Low',
					'price_asc'  => 'Price: Low to High',
				),
				'default_sort' => 'price_desc',
			),

			// 07C — Press & Media archive.
			'press'        => array(
				'post_type'    => 'ao_press',
				'per_page'     => 20,
				'card'         => 'card-press.php',
				'search'       => true,
				'search_label' => 'Search press coverage',
				'facets'       => array(
					'category' => array(
						'taxonomy' => 'ao_press_category',
						'label'    => 'Category',
						'operator' => 'IN',
					),
					'topic'    => array(
						'taxonomy' => 'ao_topic',
						'label'    => 'Topic',
						'operator' => 'IN',
					),
				),
				'sorts'        => array(
					'date_desc' => 'Newest First',
					'date_asc'  => 'Oldest First',
				),
				'default_sort' => 'date_desc',
				'year_filter'  => true,
			),

			// 06 — Market Insights archive. Phase 1 keeps the UI simple; the
			// structure is here for when the filters are exposed.
			'insights'     => array(
				'post_type'    => 'ao_insight',
				'per_page'     => 12,
				'card'         => 'card-insight.php',
				'search'       => true,
				'search_label' => 'Search insights',
				'facets'       => array(
					'category' => array(
						'taxonomy' => 'ao_insight_category',
						'label'    => 'Category',
						'operator' => 'IN',
					),
					'topic'    => array(
						'taxonomy' => 'ao_topic',
						'label'    => 'Topic',
						'operator' => 'IN',
					),
				),
				'sorts'        => array(
					'date_desc' => 'Newest First',
					'date_asc'  => 'Oldest First',
				),
				'default_sort' => 'date_desc',
			),

			// 07B — Testimonials collection.
			'testimonials' => array(
				'post_type'    => 'ao_testimonial',
				'per_page'     => 12,
				'card'         => 'card-testimonial.php',
				'search'       => false,
				'facets'       => array(
					'client' => array(
						'taxonomy' => 'ao_client_type',
						'label'    => 'Client',
						'operator' => 'IN',
					),
				),
				'sorts'        => array( 'menu_order' => 'Curated Order', 'date_desc' => 'Newest First' ),
				'default_sort' => 'menu_order',
			),

			// 05D — Seller Case Studies.
			'case_studies' => array(
				'post_type'    => 'ao_case_study',
				'per_page'     => 12,
				'card'         => 'card-case-study.php',
				'search'       => false,
				'facets'       => array(
					'neighborhood' => array(
						'taxonomy' => 'ao_neighborhood',
						'label'    => 'Neighborhood',
						'operator' => 'IN',
					),
				),
				'sorts'        => array( 'menu_order' => 'Curated Order', 'price_desc' => 'Price: High to Low' ),
				'default_sort' => 'menu_order',
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

		$config = self::$directories[ $key ];
		$config['key'] = $key;

		return wp_parse_args( $config, array(
			'per_page'     => 12,
			'card'         => 'card-building.php',
			'search'       => false,
			'search_label' => 'Search',
			'facets'       => array(),
			'sorts'        => array(),
			'default_sort' => '',
			'group_by'     => '',
			'year_filter'  => false,
		) );
	}
}
