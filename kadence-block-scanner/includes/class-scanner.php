<?php
/**
 * Walks all published posts/pages, parses Gutenberg blocks,
 * and runs each KBS_Checks method against every Kadence block found.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Scanner {

	/** Post types to scan */
	const SCAN_POST_TYPES = array(
		'post',
		'page',
		'kadence_element',
		'kadence_form',
		'kt_size_guide',
		'kadence_header',
		'kadence_wootemplate',
	);

	/** Max posts per batch (AJAX) */
	const BATCH_SIZE = 20;

	/** Option key where results are cached */
	const RESULTS_OPTION = 'kbs_scan_results';
	const META_OPTION    = 'kbs_scan_meta';

	/** ---------------------------------------------------------------
	 * Full scan — runs everything, stores results.
	 * Returns summary array.
	 * --------------------------------------------------------------- */
	public static function run_full_scan() : array {
		$start = microtime( true );

		$connectivity = KBS_Checks::check_kadence_connectivity();
		$plugins      = KBS_Checks::get_kadence_plugins();
		$licenses     = KBS_Checks::check_license_status();
		$asset_check  = KBS_Checks::check_kadence_assets_enqueued();

		$post_results = array();
		$offset       = 0;

		do {
			$posts = self::get_posts_batch( $offset );
			foreach ( $posts as $post ) {
				$result = self::scan_post( $post );
				if ( $result ) {
					$post_results[] = $result;
				}
			}
			$offset += self::BATCH_SIZE;
		} while ( count( $posts ) === self::BATCH_SIZE );

		$results = array(
			'connectivity' => $connectivity,
			'plugins'      => $plugins,
			'licenses'     => $licenses,
			'asset_check'  => $asset_check,
			'posts'        => $post_results,
		);

		update_option( self::RESULTS_OPTION, $results, false );
		update_option( self::META_OPTION, array(
			'scanned_at'  => current_time( 'mysql' ),
			'total_posts' => count( $post_results ),
			'duration'    => round( microtime( true ) - $start, 2 ),
		), false );

		return $results;
	}

	/** ---------------------------------------------------------------
	 * Scan a single post — returns result array or null if no issues.
	 * --------------------------------------------------------------- */
	public static function scan_post( WP_Post $post ) : ?array {
		if ( ! has_blocks( $post->post_content ) ) {
			return null;
		}

		$blocks = parse_blocks( $post->post_content );
		$issues = array();

		self::walk_blocks( $blocks, $issues );

		// Always return if there are issues; skip clean posts.
		if ( empty( $issues ) ) {
			return null;
		}

		return array(
			'post_id'    => $post->ID,
			'post_title' => $post->post_title ?: '(no title)',
			'post_type'  => $post->post_type,
			'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
			'view_url'   => get_permalink( $post->ID ),
			'issues'     => $issues,
		);
	}

	/** ---------------------------------------------------------------
	 * Recursive block walker
	 * --------------------------------------------------------------- */
	private static function walk_blocks( array $blocks, array &$issues ) : void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';

			// Only check Kadence blocks (plus null-name freeform blocks for malformed check)
			if ( ! self::is_kadence_block( $name ) && $name !== null ) {
				// Still recurse into inner blocks
				if ( ! empty( $block['innerBlocks'] ) ) {
					self::walk_blocks( $block['innerBlocks'], $issues );
				}
				continue;
			}

			if ( $name ) {
				$issues = array_merge( $issues,
					KBS_Checks::check_block_for_missing_images( $block ),
					KBS_Checks::check_kadence_element_reference( $block ),
					KBS_Checks::check_broken_links( $block ),
					KBS_Checks::check_block_attributes_valid( $block ),
					KBS_Checks::check_block_for_remote_assets( $block )
				);
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				self::walk_blocks( $block['innerBlocks'], $issues );
			}
		}
	}

	/** ---------------------------------------------------------------
	 * Returns true if the block name is a Kadence block.
	 * --------------------------------------------------------------- */
	private static function is_kadence_block( ?string $name ) : bool {
		if ( ! $name ) return false;
		foreach ( KBS_Checks::BLOCK_PREFIXES as $prefix ) {
			if ( strpos( $name, $prefix ) === 0 ) return true;
		}
		return false;
	}

	/** ---------------------------------------------------------------
	 * Fetch a batch of posts across all scanned post types.
	 * --------------------------------------------------------------- */
	private static function get_posts_batch( int $offset ) : array {
		$post_types = array_filter(
			self::SCAN_POST_TYPES,
			fn( $pt ) => post_type_exists( $pt )
		);

		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		return get_posts( array(
			'post_type'      => array_values( $post_types ),
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => self::BATCH_SIZE,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
	}

	/** ---------------------------------------------------------------
	 * Return cached results (or empty array if none).
	 * --------------------------------------------------------------- */
	public static function get_cached_results() : array {
		return get_option( self::RESULTS_OPTION, array() );
	}

	public static function get_scan_meta() : array {
		return get_option( self::META_OPTION, array() );
	}

	/** ---------------------------------------------------------------
	 * Summarise results into counts for the dashboard widget.
	 * --------------------------------------------------------------- */
	public static function summarise( array $results ) : array {
		$connectivity_errors = 0;
		foreach ( $results['connectivity'] ?? array() as $c ) {
			if ( $c['status'] === 'error' ) $connectivity_errors++;
		}

		$total_issues = 0;
		$type_counts  = array();
		foreach ( $results['posts'] ?? array() as $post ) {
			foreach ( $post['issues'] as $issue ) {
				$total_issues++;
				$type = $issue['type'] ?? 'unknown';
				$type_counts[ $type ] = ( $type_counts[ $type ] ?? 0 ) + 1;
			}
		}

		return array(
			'connectivity_errors' => $connectivity_errors,
			'license_issues'      => count( $results['licenses'] ?? array() ),
			'affected_posts'      => count( $results['posts'] ?? array() ),
			'total_issues'        => $total_issues,
			'issue_types'         => $type_counts,
		);
	}
}
