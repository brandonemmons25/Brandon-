<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read-only endpoint behind the filter bar and Load More.
 */
class AO_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( 'andyoei/v1', '/directory/(?P<key>[a-z_]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true', // Public listings only.
			'callback'            => array( __CLASS__, 'directory' ),
			'args'                => array(
				'key' => array(
					'validate_callback' => function ( $value ) {
						return (bool) AO_Directories::get( $value );
					},
				),
			),
		) );
	}

	public static function directory( WP_REST_Request $req ) {
		$config = AO_Directories::get( $req->get_param( 'key' ) );

		if ( ! $config ) {
			return new WP_Error( 'ao_unknown_directory', 'Unknown directory.', array( 'status' => 404 ) );
		}

		$request    = AO_Query::parse_request( $config, $req->get_params() );
		$last_group = (string) $req->get_param( 'last_group' );
		$result     = AO_Query::render( $config, $request, $last_group );

		return rest_ensure_response( array(
			'html'       => $result['html'],
			'total'      => $result['total'],
			'pages'      => $result['pages'],
			'page'       => $request['page'],
			'has_more'   => $result['has_more'],
			'last_group' => $result['last_group'],
			'filtered'   => AO_Query::is_filtered( $config, $request ),
			'count_text' => self::count_text( $config, $result['total'] ),
		) );
	}

	/**
	 * "49 BUILDINGS" / "1 BUILDING" for the directory header.
	 */
	private static function count_text( $config, $total ) {
		$labels = $config['count_label'];
		$label  = 1 === $total ? $labels[0] : $labels[1];

		return $total . ' ' . $label;
	}
}
