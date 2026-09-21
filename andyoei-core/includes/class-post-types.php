<?php
defined( 'ABSPATH' ) || exit;

/**
 * Every record type the site manages itself. IDX listings are not here.
 */
class AO_Post_Types {

	public static function register() {
		foreach ( self::definitions() as $type => $args ) {
			register_post_type( $type, $args );
		}
	}

	private static function definitions() {
		return array(

			// 02 Buildings — the condominium building directory + single pages.
			'ao_building'   => self::args( 'Condominium Building', 'Condominium Buildings', array(
				'menu_icon' => 'dashicons-building',
				'rewrite'   => array( 'slug' => 'buildings', 'with_front' => false ),
				'supports'  => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes' ),
			) ),

			// 01 Properties — manually managed sold/selected transactions.
			// Active listings stay in IDX; this is only what IDX cannot show.
			'ao_sold'       => self::args( 'Sold Property', 'Sold Properties', array(
				'menu_icon' => 'dashicons-admin-home',
				'rewrite'   => array( 'slug' => 'sold-properties', 'with_front' => false ),
				'supports'  => array( 'title', 'thumbnail', 'editor', 'page-attributes' ),
			) ),

			// 07B Testimonials — one central source, reused everywhere.
			'ao_testimonial' => self::args( 'Testimonial', 'Testimonials', array(
				'menu_icon'          => 'dashicons-format-quote',
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'supports'           => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
			) ),

			// 07C Press & Media — links out to the publisher by default.
			'ao_press'      => self::args( 'Press Item', 'Press & Media', array(
				'menu_icon'          => 'dashicons-megaphone',
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'supports'           => array( 'title', 'thumbnail', 'editor' ),
			) ),

			// 05D Seller Case Studies.
			'ao_case_study' => self::args( 'Case Study', 'Seller Case Studies', array(
				'menu_icon' => 'dashicons-analytics',
				'rewrite'   => array( 'slug' => 'seller-case-studies', 'with_front' => false ),
				'supports'  => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			) ),

			// 06 Market Insights — original research, not a blog.
			'ao_insight'    => self::args( 'Market Insight', 'Market Insights', array(
				'menu_icon' => 'dashicons-chart-line',
				'rewrite'   => array( 'slug' => 'market-insights', 'with_front' => false ),
				'supports'  => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author' ),
			) ),

			// 07D Proof points — one stat, written once, reused on About / Buy / Sell / Credentials.
			'ao_proof'      => self::args( 'Proof Point', 'Proof Points', array(
				'menu_icon'          => 'dashicons-awards',
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'supports'           => array( 'title', 'page-attributes' ),
			) ),
		);
	}

	/**
	 * Shared defaults so each definition above only states what differs.
	 */
	private static function args( $single, $plural, $args ) {
		$defaults = array(
			'labels'             => array(
				'name'          => $plural,
				'singular_name' => $single,
				'add_new_item'  => 'Add New ' . $single,
				'edit_item'     => 'Edit ' . $single,
				'search_items'  => 'Search ' . $plural,
				'not_found'     => 'No ' . strtolower( $plural ) . ' found',
			),
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_rest'       => true,
			'has_archive'        => false, // Directories are built pages, not core archives.
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail' ),
		);

		return array_merge( $defaults, $args );
	}
}
