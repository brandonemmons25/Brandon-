<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes, so the directories can be dropped into any page builder.
 */
class AO_Shortcodes {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register() {
		add_shortcode( 'ao_directory', array( __CLASS__, 'directory' ) );
		add_shortcode( 'ao_neighborhoods', array( __CLASS__, 'neighborhoods' ) );
		add_shortcode( 'ao_featured', array( __CLASS__, 'featured' ) );
		add_shortcode( 'ao_proof', array( __CLASS__, 'proof' ) );
	}

	public static function register_assets() {
		wp_register_style( 'ao-filters', AO_URL . 'assets/css/filters.css', array(), AO_VERSION );
		wp_register_script( 'ao-filters', AO_URL . 'assets/js/filters.js', array(), AO_VERSION, true );
		wp_localize_script( 'ao-filters', 'aoFilters', array(
			'root' => esc_url_raw( rest_url( 'andyoei/v1/directory/' ) ),
		) );
	}

	/**
	 * [ao_directory key="buildings"]
	 *
	 * Renders the filter bar and the first page server-side so the directory
	 * works without JavaScript and stays indexable; JavaScript takes over for
	 * filtering and Load More.
	 */
	public static function directory( $atts ) {
		$atts   = shortcode_atts( array( 'key' => 'buildings' ), $atts, 'ao_directory' );
		$config = AO_Directories::get( $atts['key'] );

		if ( ! $config ) {
			return '';
		}

		wp_enqueue_style( 'ao-filters' );
		wp_enqueue_script( 'ao-filters' );

		// Deep links and no-JS pagination read straight from the URL.
		$request = AO_Query::parse_request( $config, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$result  = AO_Query::render( $config, $request );

		return ao_template( 'directory.php', array(
			'config'   => $config,
			'request'  => $request,
			'result'   => $result,
			'filtered' => AO_Query::is_filtered( $config, $request ),
		) );
	}

	/**
	 * [ao_neighborhoods]
	 *
	 * 03A — the neighborhood directory. Sixteen terms at launch, so the whole
	 * set renders at once and search/sort happen in the browser.
	 */
	public static function neighborhoods( $atts ) {
		$atts = shortcode_atts( array( 'hide_empty' => '0' ), $atts, 'ao_neighborhoods' );

		wp_enqueue_style( 'ao-filters' );
		wp_enqueue_script( 'ao-filters' );

		$terms = get_terms( array(
			'taxonomy'   => 'ao_neighborhood',
			'hide_empty' => (bool) (int) $atts['hide_empty'],
			'orderby'    => 'name',
		) );

		if ( is_wp_error( $terms ) ) {
			return '';
		}

		return ao_template( 'neighborhoods.php', array( 'terms' => $terms ) );
	}

	/**
	 * [ao_featured type="ao_building" limit="6"]
	 *
	 * The curated strips. Andy sets Featured and Featured Rank on the record;
	 * nothing here is automatic.
	 */
	public static function featured( $atts ) {
		$atts = shortcode_atts( array(
			'type'               => 'ao_building',
			'limit'              => 6,
			'card'               => '',
			'hide_when_filtered' => '', // Directory key, e.g. "buildings".
		), $atts, 'ao_featured' );

		$cards = array(
			'ao_building'    => 'card-building.php',
			'ao_sold'        => 'card-sold.php',
			'ao_press'       => 'card-press.php',
			'ao_insight'     => 'card-insight.php',
			'ao_testimonial' => 'card-testimonial.php',
			'ao_case_study'  => 'card-case-study.php',
		);

		$card = $atts['card'] ? $atts['card'] : ( isset( $cards[ $atts['type'] ] ) ? $cards[ $atts['type'] ] : 'card-building.php' );

		$posts = get_posts( array(
			'post_type'      => $atts['type'],
			'posts_per_page' => (int) $atts['limit'],
			'meta_key'       => 'ao_featured_rank', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'        => array( 'meta_value_num' => 'ASC', 'title' => 'ASC' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => 'ao_featured', 'value' => '1' ),
			),
		) );

		if ( ! $posts ) {
			return '';
		}

		wp_enqueue_style( 'ao-filters' );

		$html = '';
		foreach ( $posts as $post ) {
			$html .= ao_template( $card, array( 'post_id' => $post->ID, 'config' => array() ) );
		}

		// 02A/03A — the curated strip steps aside while a directory on the
		// same page is being filtered or sorted.
		$attr = $atts['hide_when_filtered']
			? ' data-ao-hide-when-filtered="' . esc_attr( $atts['hide_when_filtered'] ) . '"'
			: '';

		return '<div class="ao-featured-strip"' . $attr . '>' . $html . '</div>';
	}

	/**
	 * [ao_proof context="seller" limit="4"]
	 *
	 * 07D — the stats written once and reused on About, Buy, Sell and
	 * Credentials.
	 */
	public static function proof( $atts ) {
		$atts = shortcode_atts( array( 'context' => '', 'limit' => 6 ), $atts, 'ao_proof' );

		$args = array(
			'post_type'      => 'ao_proof',
			'posts_per_page' => (int) $atts['limit'],
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		);

		if ( $atts['context'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'ao_proof_context',
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', explode( ',', $atts['context'] ) ),
				),
			);
		}

		$posts = get_posts( $args );

		if ( ! $posts ) {
			return '';
		}

		wp_enqueue_style( 'ao-filters' );

		$html = '';
		foreach ( $posts as $post ) {
			$html .= ao_template( 'proof-point.php', array( 'post_id' => $post->ID ) );
		}

		return '<div class="ao-proof-row">' . $html . '</div>';
	}
}
