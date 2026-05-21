<?php
/**
 * REST API endpoints for autonomous agent access.
 *
 * POST /wp-json/kbs/v1/setup   — create claude-mcp user + app password (admin auth required)
 * POST /wp-json/kbs/v1/scan    — run a full scan, return results
 * GET  /wp-json/kbs/v1/results — return cached results from last scan
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Rest_API {

	const NAMESPACE  = 'kbs/v1';
	const MCP_USER   = 'claude-mcp';
	const MCP_APP    = 'claude-agent';

	public static function init() : void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() : void {
		register_rest_route( self::NAMESPACE, '/setup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'setup_mcp_user' ),
			'permission_callback' => array( __CLASS__, 'check_permission' ),
		) );

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

	/**
	 * Creates the claude-mcp user and generates a fresh Application Password.
	 * Returns the credentials — store them immediately, the password is shown once.
	 */
	public static function setup_mcp_user( WP_REST_Request $request ) : WP_REST_Response {
		if ( ! function_exists( 'wp_create_application_password' ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => 'Application Passwords require WordPress 5.6+.',
			), 400 );
		}

		// Create or fetch the claude-mcp user.
		$user_id = username_exists( self::MCP_USER );

		if ( ! $user_id ) {
			$user_id = wp_insert_user( array(
				'user_login' => self::MCP_USER,
				'user_pass'  => wp_generate_password( 32 ),
				'user_email' => self::MCP_USER . '@' . parse_url( home_url(), PHP_URL_HOST ),
				'role'       => 'administrator',
				'first_name' => 'Claude',
				'last_name'  => 'MCP',
			) );

			if ( is_wp_error( $user_id ) ) {
				return new WP_REST_Response( array(
					'success' => false,
					'message' => $user_id->get_error_message(),
				), 500 );
			}
		}

		// Revoke any existing app password with the same name, then create a fresh one.
		$existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
		foreach ( $existing as $app ) {
			if ( $app['name'] === self::MCP_APP ) {
				WP_Application_Passwords::delete_application_password( $user_id, $app['uuid'] );
			}
		}

		[ $plain_password, $item ] = wp_create_application_password( $user_id, array(
			'name' => self::MCP_APP,
		) );

		return new WP_REST_Response( array(
			'success'    => true,
			'username'   => self::MCP_USER,
			'app_password' => $plain_password,
			'note'       => 'Save app_password now — it will not be shown again. Add as WP_MCP_USERNAME and WP_MCP_PASSWORD in GitHub secrets.',
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
