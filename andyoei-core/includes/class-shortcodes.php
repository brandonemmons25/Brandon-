<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes, so both directories drop into any page builder.
 */
class AO_Shortcodes {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 25 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	public static function register() {
		add_shortcode( 'ao_buildings', array( __CLASS__, 'buildings' ) );
		add_shortcode( 'ao_neighborhoods', array( __CLASS__, 'neighborhoods' ) );
		add_shortcode( 'ao_featured_buildings', array( __CLASS__, 'featured_buildings' ) );
		add_shortcode( 'ao_featured_neighborhoods', array( __CLASS__, 'featured_neighborhoods' ) );
	}

	public static function register_assets() {
		wp_register_style( 'ao-core', AO_URL . 'assets/css/andyoei.css', array(), AO_VERSION );
		wp_register_script( 'ao-filters', AO_URL . 'assets/js/filters.js', array(), AO_VERSION, true );
		wp_register_script( 'ao-carousel', AO_URL . 'assets/js/carousel.js', array(), AO_VERSION, true );
		wp_localize_script( 'ao-filters', 'aoFilters', array(
			'root' => esc_url_raw( rest_url( 'andyoei/v1/directory/' ) ),
		) );
	}

	private static function assets( $carousel = false ) {
		wp_enqueue_style( 'ao-core' );
		wp_enqueue_script( 'ao-filters' );

		if ( $carousel ) {
			wp_enqueue_script( 'ao-carousel' );
		}
	}

	/**
	 * [ao_buildings]
	 *
	 * 02A — search, sort, filter drawer, featured strip, alphabetical
	 * headers and Load More. The first page renders server-side, so the
	 * directory works without JavaScript and stays indexable.
	 */
	public static function buildings( $atts ) {
		$atts = shortcode_atts( array(
			'featured'       => '1',
			'featured_title' => 'Featured Condominium Buildings',
			'featured_limit' => 6,
			'width'          => '', // e.g. "1280px" or "100%".
		), $atts, 'ao_buildings' );

		$config = AO_Directories::get( 'buildings' );

		if ( ! $config ) {
			return '';
		}

		self::assets( true );

		// Deep links and no-JS pagination read straight from the URL.
		$request  = AO_Query::parse_request( $config, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$filtered = AO_Query::is_filtered( $config, $request );
		$result   = AO_Query::render( $config, $request );

		$featured = '';

		if ( '1' === (string) $atts['featured'] ) {
			$featured = self::featured_buildings( array(
				'limit'  => $atts['featured_limit'],
				'title'  => $atts['featured_title'],
				'hidden' => $filtered ? '1' : '0',
			) );
		}

		return ao_template( 'directory.php', array(
			'config'   => $config,
			'request'  => $request,
			'result'   => $result,
			'filtered' => $filtered,
			'featured' => $featured,
			'width'    => $atts['width'],
		) );
	}

	/**
	 * [ao_featured_buildings]
	 *
	 * Curated strip of 4–6. Andy sets Featured and Featured Rank on each
	 * building; nothing here is automatic.
	 */
	public static function featured_buildings( $atts ) {
		$atts = shortcode_atts( array(
			'limit'  => 6,
			'title'  => 'Featured Condominium Buildings',
			'note'   => 'Andy selects + orders',
			'hidden' => '0',
		), $atts, 'ao_featured_buildings' );

		// Ordering in PHP, not SQL: ordering by the rank meta would drop any
		// building marked featured but left unranked.
		$posts = get_posts( array(
			'post_type'      => 'ao_building',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => AO_Curation::FEATURED, 'value' => '1' ),
			),
		) );

		usort( $posts, function ( $a, $b ) {
			$rank_a = (int) get_post_meta( $a->ID, AO_Curation::RANK, true );
			$rank_b = (int) get_post_meta( $b->ID, AO_Curation::RANK, true );

			// Unranked records fall to the end, then sort by title.
			$rank_a = $rank_a ? $rank_a : PHP_INT_MAX;
			$rank_b = $rank_b ? $rank_b : PHP_INT_MAX;

			return $rank_a === $rank_b
				? strcasecmp( $a->post_title, $b->post_title )
				: $rank_a - $rank_b;
		} );

		$posts = array_slice( $posts, 0, (int) $atts['limit'] );

		if ( ! $posts ) {
			return '';
		}

		self::assets( true );

		$cards = '';
		foreach ( $posts as $post ) {
			$cards .= ao_template( 'card-building.php', array( 'post_id' => $post->ID ) );
		}

		return ao_template( 'featured-strip.php', array(
			'title'  => $atts['title'],
			'note'   => $atts['note'],
			'cards'  => $cards,
			'key'    => 'buildings',
			'hidden' => '1' === (string) $atts['hidden'],
		) );
	}

	/**
	 * [ao_neighborhoods]
	 *
	 * 03A — sixteen terms at launch, so the whole set renders at once and
	 * search and sort run in the browser.
	 */
	public static function neighborhoods( $atts ) {
		$atts = shortcode_atts( array(
			'featured'       => '1',
			'featured_title' => 'Featured Neighborhoods',
			'featured_limit' => 6,
			'count_label'    => 'At Launch',
			'hide_empty'     => '0',
			'width'          => '',
		), $atts, 'ao_neighborhoods' );

		self::assets( true );

		$terms = get_terms( array(
			'taxonomy'   => 'ao_neighborhood',
			'hide_empty' => (bool) (int) $atts['hide_empty'],
			'orderby'    => 'name',
		) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		$featured = '';

		if ( '1' === (string) $atts['featured'] ) {
			$featured = self::featured_neighborhoods( array(
				'limit' => $atts['featured_limit'],
				'title' => $atts['featured_title'],
			) );
		}

		return ao_template( 'neighborhoods.php', array(
			'terms'       => $terms,
			'featured'    => $featured,
			'count_label' => $atts['count_label'],
			'width'       => $atts['width'],
		) );
	}

	/**
	 * [ao_featured_neighborhoods]
	 */
	public static function featured_neighborhoods( $atts ) {
		$atts = shortcode_atts( array(
			'limit' => 6,
			'title' => 'Featured Neighborhoods',
			'note'  => 'Andy selects neighborhoods and display order',
		), $atts, 'ao_featured_neighborhoods' );

		// Ordered in PHP for the same reason as buildings: a featured but
		// unranked neighborhood must still appear.
		$terms = get_terms( array(
			'taxonomy'   => 'ao_neighborhood',
			'hide_empty' => false,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => AO_Curation::TERM_FEATURED, 'value' => '1' ),
			),
		) );

		if ( ! is_wp_error( $terms ) && $terms ) {
			usort( $terms, function ( $a, $b ) {
				$rank_a = (int) get_term_meta( $a->term_id, AO_Curation::TERM_RANK, true );
				$rank_b = (int) get_term_meta( $b->term_id, AO_Curation::TERM_RANK, true );

				$rank_a = $rank_a ? $rank_a : PHP_INT_MAX;
				$rank_b = $rank_b ? $rank_b : PHP_INT_MAX;

				return $rank_a === $rank_b
					? strcasecmp( $a->name, $b->name )
					: $rank_a - $rank_b;
			} );

			$terms = array_slice( $terms, 0, (int) $atts['limit'] );
		}

		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}

		self::assets( true );

		$cards = '';
		foreach ( $terms as $term ) {
			$cards .= ao_template( 'card-neighborhood.php', array( 'term' => $term, 'featured' => true ) );
		}

		return ao_template( 'featured-strip.php', array(
			'title'  => $atts['title'],
			'note'   => $atts['note'],
			'cards'  => $cards,
			'key'    => 'neighborhoods',
			'hidden' => false,
		) );
	}
}
