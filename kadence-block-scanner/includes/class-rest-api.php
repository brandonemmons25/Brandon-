<?php
/**
 * REST API endpoints for autonomous agent access.
 *
 * POST /wp-json/kbs/v1/setup   — generate API key (admin Basic Auth required, one time)
 * POST /wp-json/kbs/v1/scan    — run a full scan, return results
 * GET  /wp-json/kbs/v1/results — return cached results from last scan
 *
 * After setup, all requests authenticate via X-KBS-Key header.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Rest_API {

	const NAMESPACE = 'kbs/v1';
	const KEY_OPTION = 'kbs_api_key';

	public static function init() : void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() : void {
		register_rest_route( self::NAMESPACE, '/setup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'setup' ),
			'permission_callback' => array( __CLASS__, 'check_admin' ),
		) );

		register_rest_route( self::NAMESPACE, '/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'run_scan' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );

		register_rest_route( self::NAMESPACE, '/results', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_results' ),
			'permission_callback' => array( __CLASS__, 'check_api_key' ),
		) );
	}

	/** Setup: requires admin Basic Auth, generates and stores a random API key. */
	public static function check_admin() : bool {
		return current_user_can( 'manage_options' );
	}

	/** Scan/results: authenticate via X-KBS-Key header. */
	public static function check_api_key() : bool {
		$stored = get_option( self::KEY_OPTION, '' );
		if ( ! $stored ) return false;

		$header = isset( $_SERVER['HTTP_X_KBS_KEY'] ) ? sanitize_text_field( $_SERVER['HTTP_X_KBS_KEY'] ) : '';
		return hash_equals( $stored, $header );
	}

	/**
	 * Generates a fresh API key, stores it in WP options, returns it once.
	 * Called with admin Basic Auth — after this, use X-KBS-Key for everything.
	 */
	public static function setup( WP_REST_Request $request ) : WP_REST_Response {
		$key = bin2hex( random_bytes( 32 ) );
		update_option( self::KEY_OPTION, $key, false );

		return new WP_REST_Response( array(
			'success' => true,
			'api_key' => $key,
			'note'    => 'Save api_key now — it will not be shown again. Add as WP_API_KEY in GitHub secrets.',
		), 200 );
	}

	public static function run_scan( WP_REST_Request $request ) : WP_REST_Response {
		$results = KBS_Scanner::run_full_scan();
		$meta    = get_option( KBS_Scanner::META_OPTION, array() );

		return new WP_REST_Response( array(
			'success' => true,
			'meta'    => $meta,
			'results' => $results,
		), 200 );
	}

	public static function get_results( WP_REST_Request $request ) : WP_REST_Response {
		$results = get_option( KBS_Scanner::RESULTS_OPTION );
		$meta    = get_option( KBS_Scanner::META_OPTION, array() );

		if ( ! $results ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'No scan results found. Run POST /wp-json/kbs/v1/scan first.',
			), 404 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'meta'    => $meta,
			'results' => $results,
		), 200 );
	}
}
