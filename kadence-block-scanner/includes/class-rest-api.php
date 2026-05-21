<?php
/**
 * REST API endpoints for autonomous agent access.
 *
 * POST /wp-json/kbs/v1/scan    — run a full scan, return results
 * GET  /wp-json/kbs/v1/results — return cached results from last scan
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Rest_API {

	const NAMESPACE = 'kbs/v1';

	public static function init() : void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() : void {
		register_rest_route( self::NAMESPACE, '/scan', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'run_scan' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );

		register_rest_route( self::NAMESPACE, '/results', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_results' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );
	}

	public static function check_permission() : bool {
		return current_user_can( 'manage_options' );
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
