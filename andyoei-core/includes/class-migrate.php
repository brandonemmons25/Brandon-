<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-time move from the neighborhood taxonomy to the neighborhood post type.
 *
 * Runs once, on the first load after upgrading to 2.0. The old terms are read
 * straight from the tables — the taxonomy is no longer registered, so
 * get_terms() would refuse them — and are left in place afterwards rather
 * than deleted, so nothing is destroyed if this has to be looked at again.
 */
class AO_Migrate {

	const OPTION = 'ao_migrated_neighborhoods';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
	}

	public static function maybe_run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}

		self::run();

		update_option( self::OPTION, gmdate( 'c' ) );
	}

	/**
	 * @return array{terms:int,buildings:int} What was moved.
	 */
	public static function run() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT t.term_id, tt.term_taxonomy_id, t.name, t.slug
			   FROM {$wpdb->terms} AS t
			   JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id
			  WHERE tt.taxonomy = 'ao_neighborhood'"
		);

		$moved     = 0;
		$buildings = 0;

		foreach ( $rows as $row ) {
			$post_id = self::post_for( $row );

			if ( ! $post_id ) {
				continue;
			}

			$moved++;
			$buildings += self::attach_buildings( (int) $row->term_taxonomy_id, $post_id );
		}

		return array( 'terms' => $moved, 'buildings' => $buildings );
	}

	/**
	 * The neighborhood post for one old term: an existing post with that slug,
	 * or a new one. Either way the term's card image, price and destination
	 * are carried over — the activation seeder may have created the post
	 * moments earlier, and an empty seeded post must not shadow real data.
	 */
	private static function post_for( $row ) {
		$existing = get_posts( array(
			'post_type'      => 'ao_neighborhood',
			'post_status'    => 'any',
			'name'           => $row->slug,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		if ( $existing ) {
			$post_id = (int) $existing[0];
		} else {
			$post_id = wp_insert_post( array(
				'post_type'   => 'ao_neighborhood',
				'post_status' => 'publish',
				'post_title'  => $row->name,
				'post_name'   => $row->slug,
			) );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return 0;
			}
		}

		self::carry_meta( $row->term_id, (int) $post_id );

		return (int) $post_id;
	}

	/**
	 * Copy one term's stored values onto its post, never overwriting a value
	 * already set there — an edit made after the upgrade wins.
	 */
	private static function carry_meta( $term_id, $post_id ) {
		// Card image became the featured image; the rest are post meta under
		// the same keys they had as term meta.
		$image = (int) get_term_meta( $term_id, 'ao_card_image', true );

		if ( $image && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $image );
		}

		foreach ( array( 'ao_median_price', 'ao_destination' ) as $key ) {
			$value = get_term_meta( $term_id, $key, true );

			if ( '' !== $value && null !== $value && '' === get_post_meta( $post_id, $key, true ) ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		// Featured + rank moved from the term-only keys onto the post keys the
		// buildings already use.
		if ( '1' === (string) get_term_meta( $term_id, 'ao_nbhd_featured', true )
			&& '' === get_post_meta( $post_id, AO_Curation::FEATURED, true ) ) {
			update_post_meta( $post_id, AO_Curation::FEATURED, '1' );
		}

		$rank = (int) get_term_meta( $term_id, 'ao_nbhd_rank', true );

		if ( $rank && ! get_post_meta( $post_id, AO_Curation::RANK, true ) ) {
			update_post_meta( $post_id, AO_Curation::RANK, $rank );
		}
	}

	/**
	 * Point every building that carried this term at the new post.
	 */
	private static function attach_buildings( $term_taxonomy_id, $neighborhood_id ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
				$term_taxonomy_id
			)
		);

		$count = 0;

		foreach ( $ids as $id ) {
			if ( 'ao_building' !== get_post_type( $id ) ) {
				continue;
			}

			update_post_meta( (int) $id, AO_Relations::KEY, $neighborhood_id );
			AO_Index::index( (int) $id );
			$count++;
		}

		return $count;
	}
}
