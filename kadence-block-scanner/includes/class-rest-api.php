<?php
/**
 * REST API endpoints for autonomous agent access.
 *
 * POST /wp-json/kbs/v1/setup        — generate API key (admin Basic Auth, one time)
 * POST /wp-json/kbs/v1/scan/start   — reset and start a new scan
 * POST /wp-json/kbs/v1/scan/batch   — scan one batch of posts, returns progress
 * GET  /wp-json/kbs/v1/results      — return cached results from completed scan
 *
 * After setup, all requests authenticate via X-KBS-Key header.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Rest_API {

	const NAMESPACE    = 'kbs/v1';
	const KEY_OPTION   = 'kbs_api_key';
	const OFFSET_OPTION = 'kbs_scan_offset';
	const BATCH_SIZE   = 5;

	public static function init() : void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() : void {
		register_rest_route( self::NAMESPACE, '/setup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'setup' ),
			'permission_callback' => array( __CLASS__, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/scan/start', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'scan_start' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );

		register_rest_route( self::NAMESPACE, '/scan/batch', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'scan_batch' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );

		register_rest_route( self::NAMESPACE, '/results', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_results' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );
	}

	public static function check_admin() : bool {
		return current_user_can( 'manage_options' );
	}

	public static function check_api_key() : bool {
		$stored = get_option( self::KEY_OPTION, '' );
		if ( ! $stored ) return false;
		$header = isset( $_SERVER['HTTP_X_KBS_KEY'] ) ? sanitize_text_field( $_SERVER['HTTP_X_KBS_KEY'] ) : '';
		return hash_equals( $stored, $header );
	}

	public static function setup( WP_REST_Request $request ) : WP_REST_Response {
		$key = bin2hex( random_bytes( 32 ) );
		update_option( self::KEY_OPTION, $key, false );
		return new WP_REST_Response( array(
			'success' => true,
			'api_key' => $key,
			'note'    => 'Save api_key now — it will not be shown again. Add as WP_API_KEY in GitHub secrets.',
		), 200 );
	}

	/** Reset scan state and return total post count so caller knows how many batches to run. */
	public static function scan_start( WP_REST_Request $request ) : WP_REST_Response {
		global $wpdb;

		delete_option( KBS_Scanner::RESULTS_OPTION );
		delete_option( KBS_Scanner::META_OPTION );
		update_option( self::OFFSET_OPTION, 0, false );

		$types   = KBS_Scanner::SCAN_POST_TYPES;
		$in      = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$total   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($in)",
			...$types
		) );

		$batches = (int) ceil( $total / self::BATCH_SIZE );

		return new WP_REST_Response( array(
			'success' => true,
			'total'   => $total,
			'batches' => $batches,
			'batch_size' => self::BATCH_SIZE,
		), 200 );
	}

	/** Scan one batch. Accumulates results in WP options. Returns done=true on last batch. */
	public static function scan_batch( WP_REST_Request $request ) : WP_REST_Response {
		$offset  = (int) get_option( self::OFFSET_OPTION, 0 );
		$posts   = KBS_Scanner::get_posts_batch( $offset, self::BATCH_SIZE );

		$existing = get_option( KBS_Scanner::RESULTS_OPTION, array(
			'connectivity' => array(),
			'plugins'      => array(),
			'licenses'     => array(),
			'asset_check'  => array(),
			'posts'        => array(),
		) );

		// Only run heavy checks on first batch
		if ( $offset === 0 ) {
			$existing['connectivity'] = KBS_Checks::check_kadence_connectivity();
			$existing['plugins']      = KBS_Checks::get_kadence_plugins();
			$existing['licenses']     = KBS_Checks::check_license_status();
			$existing['asset_check']  = KBS_Checks::check_kadence_assets_enqueued();
		}

		foreach ( $posts as $post ) {
			$result = KBS_Scanner::scan_post( $post );
			if ( $result ) {
				$existing['posts'][] = $result;
			}
		}

		$new_offset = $offset + count( $posts );
		update_option( self::OFFSET_OPTION, $new_offset, false );
		update_option( KBS_Scanner::RESULTS_OPTION, $existing, false );

		$done = count( $posts ) < self::BATCH_SIZE;

		if ( $done ) {
			update_option( KBS_Scanner::META_OPTION, array(
				'scanned_at'  => current_time( 'mysql' ),
				'total_posts' => count( $existing['posts'] ),
			), false );
			delete_option( self::OFFSET_OPTION );
		}

		return new WP_REST_Response( array(
			'success'    => true,
			'offset'     => $new_offset,
			'batch_count' => count( $posts ),
			'done'       => $done,
		), 200 );
	}

	public static function get_results( WP_REST_Request $request ) : WP_REST_Response {
		$results = get_option( KBS_Scanner::RESULTS_OPTION );
		$meta    = get_option( KBS_Scanner::META_OPTION, array() );

		if ( ! $results ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'No scan results found. Run /scan/start then /scan/batch until done=true.',
			), 404 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'meta'    => $meta,
			'results' => $results,
		), 200 );
	}
}
