<?php
/**
 * REST API for the Kadence Block Scanner.
 *
 * Auth: X-KBS-Key header (all routes except /setup).
 * /setup uses WP admin Basic Auth (Application Password).
 *
 * Routes:
 *   POST kbs/v1/setup              — generate / return API key
 *   POST kbs/v1/scan/start         — initialise batched scan
 *   POST kbs/v1/scan/batch         — run one batch
 *   GET  kbs/v1/results            — cached results + summary
 *   POST kbs/v1/fix                — apply auto-fixes from last scan
 *   POST kbs/v1/plugin/install     — install plugin from ZIP URL
 *   POST kbs/v1/plugin/activate    — activate plugin by file path
 *   GET  kbs/v1/plugins            — list all installed plugins
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_API {

	const OPTION_KEY  = 'kbs_api_key';
	const BATCH_STATE = 'kbs_batch_state';

	public static function init() : void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() : void {
		$ns = 'kbs/v1';

		register_rest_route( $ns, '/setup', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'setup' ),
			'permission_callback' => array( __CLASS__, 'require_admin' ),
		) );

		foreach ( array(
			array( 'POST', '/scan/start',      'scan_start' ),
			array( 'POST', '/scan/batch',      'scan_batch' ),
			array( 'GET',  '/results',         'get_results' ),
			array( 'POST', '/fix',             'fix_issues' ),
			array( 'POST', '/plugin/install',  'plugin_install' ),
			array( 'POST', '/plugin/activate', 'plugin_activate' ),
			array( 'GET',  '/plugins',         'list_plugins' ),
		) as [ $method, $route, $cb ] ) {
			register_rest_route( $ns, $route, array(
				'methods'             => $method,
				'callback'            => array( __CLASS__, $cb ),
				'permission_callback' => array( __CLASS__, 'check_key' ),
			) );
		}
	}

	/* ------------------------------------------------------------------
	 * Auth
	 * ------------------------------------------------------------------ */

	public static function require_admin() : bool {
		return current_user_can( 'manage_options' );
	}

	public static function check_key( WP_REST_Request $req ) : bool {
		$stored = get_option( self::OPTION_KEY, '' );
		if ( empty( $stored ) ) return false;
		return hash_equals( $stored, (string) $req->get_header( 'X-KBS-Key' ) );
	}

	/* ------------------------------------------------------------------
	 * POST /setup  — requires WP admin session / Application Password
	 * ------------------------------------------------------------------ */

	public static function setup() : WP_REST_Response {
		$key = get_option( self::OPTION_KEY, '' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 48, false );
			update_option( self::OPTION_KEY, $key, false );
		}
		return new WP_REST_Response( array( 'api_key' => $key ), 200 );
	}

	/* ------------------------------------------------------------------
	 * POST /scan/start
	 * ------------------------------------------------------------------ */

	public static function scan_start() : WP_REST_Response {
		$post_types = self::active_post_types();

		$total = 0;
		foreach ( $post_types as $pt ) {
			$c      = wp_count_posts( $pt );
			$total += ( (int)($c->publish ?? 0) ) + ( (int)($c->draft ?? 0) ) + ( (int)($c->private ?? 0) );
		}

		$batches = (int) ceil( $total / KBS_Scanner::BATCH_SIZE );

		update_option( self::BATCH_STATE, array(
			'offset'       => 0,
			'post_results' => array(),
			'start'        => microtime( true ),
			'connectivity' => KBS_Checks::check_kadence_connectivity(),
			'plugins'      => KBS_Checks::get_kadence_plugins(),
			'licenses'     => KBS_Checks::check_license_status(),
			'asset_check'  => KBS_Checks::check_kadence_assets_enqueued(),
		), false );

		return new WP_REST_Response( array(
			'total'   => $total,
			'batches' => max( 1, $batches ),
		), 200 );
	}

	/* ------------------------------------------------------------------
	 * POST /scan/batch
	 * ------------------------------------------------------------------ */

	public static function scan_batch() : WP_REST_Response {
		$state = get_option( self::BATCH_STATE );
		if ( ! is_array( $state ) ) {
			return new WP_REST_Response( array( 'error' => 'No scan started — call /scan/start first.' ), 400 );
		}

		$posts = get_posts( array(
			'post_type'      => array_values( self::active_post_types() ),
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => KBS_Scanner::BATCH_SIZE,
			'offset'         => $state['offset'],
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		foreach ( $posts as $post ) {
			$r = KBS_Scanner::scan_post( $post );
			if ( $r ) $state['post_results'][] = $r;
		}

		$done = count( $posts ) < KBS_Scanner::BATCH_SIZE;

		if ( $done ) {
			$results = array(
				'connectivity' => $state['connectivity'],
				'plugins'      => $state['plugins'],
				'licenses'     => $state['licenses'],
				'asset_check'  => $state['asset_check'],
				'posts'        => $state['post_results'],
			);
			update_option( KBS_Scanner::RESULTS_OPTION, $results, false );
			update_option( KBS_Scanner::META_OPTION, array(
				'scanned_at'  => current_time( 'mysql' ),
				'total_posts' => count( $state['post_results'] ),
				'duration'    => round( microtime( true ) - $state['start'], 2 ),
			), false );
			delete_option( self::BATCH_STATE );
		} else {
			$state['offset'] += KBS_Scanner::BATCH_SIZE;
			update_option( self::BATCH_STATE, $state, false );
		}

		return new WP_REST_Response( array( 'done' => $done ), 200 );
	}

	/* ------------------------------------------------------------------
	 * GET /results
	 * ------------------------------------------------------------------ */

	public static function get_results() : WP_REST_Response {
		$results = KBS_Scanner::get_cached_results();
		if ( empty( $results ) ) {
			return new WP_REST_Response( array( 'error' => 'No results cached. Run a scan first.' ), 404 );
		}
		return new WP_REST_Response( array(
			'meta'    => KBS_Scanner::get_scan_meta(),
			'summary' => KBS_Scanner::summarise( $results ),
			'results' => $results,
		), 200 );
	}

	/* ------------------------------------------------------------------
	 * POST /fix  — auto-fix issues found in the last scan
	 *
	 * Fixes applied:
	 *   malformed_attrs   — reset malformed Kadence block attributes
	 *   broken_reference  — remove kadence/element blocks pointing to
	 *                       deleted posts
	 *   empty_text        — remove empty text/heading blocks
	 *
	 * Returns a log of every change made.
	 * ------------------------------------------------------------------ */

	public static function fix_issues() : WP_REST_Response {
		$results = KBS_Scanner::get_cached_results();
		if ( empty( $results['posts'] ) ) {
			return new WP_REST_Response( array( 'message' => 'No scan results to fix.' ), 200 );
		}

		$log      = array();
		$fixed    = 0;
		$skipped  = 0;

		foreach ( $results['posts'] as $post_data ) {
			$post_id    = (int) $post_data['post_id'];
			$post       = get_post( $post_id );
			if ( ! $post ) { $skipped++; continue; }

			$issue_types = array_column( $post_data['issues'], 'type' );
			$fixable     = array_intersect( $issue_types, array(
				'malformed_attrs',
				'broken_reference',
				'empty_text_block',
			) );

			if ( empty( $fixable ) ) { $skipped++; continue; }

			$original_content = $post->post_content;
			$blocks           = parse_blocks( $original_content );
			$changed          = false;

			$blocks = self::fix_blocks( $blocks, $fixable, $post_id, $log, $changed );

			if ( $changed ) {
				$new_content = serialize_blocks( $blocks );
				wp_update_post( array(
					'ID'           => $post_id,
					'post_content' => $new_content,
				) );
				$fixed++;
				$log[] = array(
					'post_id'    => $post_id,
					'post_title' => $post->post_title,
					'action'     => 'post_updated',
				);
			}
		}

		// Re-run scan to reflect fixes
		$fresh = KBS_Scanner::run_full_scan();

		return new WP_REST_Response( array(
			'fixed'       => $fixed,
			'skipped'     => $skipped,
			'log'         => $log,
			'new_summary' => KBS_Scanner::summarise( $fresh ),
		), 200 );
	}

	/* ------------------------------------------------------------------
	 * Recursive block fixer
	 * ------------------------------------------------------------------ */

	private static function fix_blocks(
		array $blocks,
		array $fix_types,
		int   $post_id,
		array &$log,
		bool  &$changed
	) : array {
		$out = array();
		foreach ( $blocks as $block ) {
			$name   = $block['blockName'] ?? '';
			$attrs  = $block['attrs'] ?? array();
			$remove = false;

			// Fix: broken kadence/element references (post deleted)
			if (
				in_array( 'broken_reference', $fix_types, true ) &&
				$name === 'kadence/element' &&
				! empty( $attrs['id'] )
			) {
				$ref = get_post( (int) $attrs['id'] );
				if ( ! $ref || $ref->post_status === 'trash' ) {
					$remove = true;
					$log[]  = array(
						'post_id' => $post_id,
						'block'   => $name,
						'action'  => 'removed_broken_reference',
						'ref_id'  => $attrs['id'],
					);
					$changed = true;
				}
			}

			// Fix: malformed attrs — strip non-scalar values that WP can't serialise
			if (
				! $remove &&
				in_array( 'malformed_attrs', $fix_types, true ) &&
				self::block_has_malformed_attrs( $attrs )
			) {
				$block['attrs'] = self::sanitise_attrs( $attrs );
				$log[] = array(
					'post_id' => $post_id,
					'block'   => $name,
					'action'  => 'sanitised_attrs',
				);
				$changed = true;
			}

			// Fix: empty core text / heading blocks
			if (
				! $remove &&
				in_array( 'empty_text_block', $fix_types, true ) &&
				in_array( $name, array( 'core/paragraph', 'core/heading' ), true ) &&
				trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) ) === ''
			) {
				$remove  = true;
				$log[]   = array(
					'post_id' => $post_id,
					'block'   => $name,
					'action'  => 'removed_empty_block',
				);
				$changed = true;
			}

			if ( $remove ) continue;

			// Recurse into inner blocks
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::fix_blocks(
					$block['innerBlocks'], $fix_types, $post_id, $log, $changed
				);
			}

			$out[] = $block;
		}
		return $out;
	}

	private static function block_has_malformed_attrs( array $attrs ) : bool {
		foreach ( $attrs as $v ) {
			if ( is_object( $v ) ) return true;
			if ( is_array( $v ) ) {
				foreach ( $v as $vv ) {
					if ( is_object( $vv ) ) return true;
				}
			}
		}
		return false;
	}

	private static function sanitise_attrs( array $attrs ) : array {
		foreach ( $attrs as $k => $v ) {
			if ( is_object( $v ) ) {
				$attrs[ $k ] = (array) $v;
			}
		}
		return $attrs;
	}

	/* ------------------------------------------------------------------
	 * POST /plugin/install  — install a plugin from a ZIP URL
	 *
	 * Body: { "url": "https://...", "activate": true }
	 * ------------------------------------------------------------------ */

	public static function plugin_install( WP_REST_Request $req ) : WP_REST_Response {
		$url      = esc_url_raw( $req->get_param( 'url' ) );
		$activate = (bool) $req->get_param( 'activate' );

		if ( empty( $url ) ) {
			return new WP_REST_Response( array( 'error' => '"url" is required.' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		WP_Filesystem();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $url );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}
		if ( ! $result ) {
			$errors = $skin->get_errors();
			$msg    = is_wp_error( $errors ) ? $errors->get_error_message() : 'Unknown install error.';
			return new WP_REST_Response( array( 'error' => $msg ), 500 );
		}

		$plugin_file = $upgrader->plugin_info();
		$activated   = false;

		if ( $activate && $plugin_file ) {
			$act = activate_plugin( $plugin_file );
			if ( is_wp_error( $act ) ) {
				return new WP_REST_Response( array(
					'installed' => true,
					'activated' => false,
					'plugin'    => $plugin_file,
					'warning'   => $act->get_error_message(),
				), 200 );
			}
			$activated = true;
		}

		return new WP_REST_Response( array(
			'installed' => true,
			'activated' => $activated,
			'plugin'    => $plugin_file,
		), 200 );
	}

	/* ------------------------------------------------------------------
	 * POST /plugin/activate
	 *
	 * Body: { "plugin": "plugin-folder/plugin-file.php" }
	 * ------------------------------------------------------------------ */

	public static function plugin_activate( WP_REST_Request $req ) : WP_REST_Response {
		$plugin = sanitize_text_field( $req->get_param( 'plugin' ) );
		if ( empty( $plugin ) ) {
			return new WP_REST_Response( array( 'error' => '"plugin" is required.' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_plugin_active( $plugin ) ) {
			return new WP_REST_Response( array( 'activated' => true, 'note' => 'Already active.' ), 200 );
		}

		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'activated' => true, 'plugin' => $plugin ), 200 );
	}

	/* ------------------------------------------------------------------
	 * GET /plugins
	 * ------------------------------------------------------------------ */

	public static function list_plugins() : WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$all    = get_plugins();
		$active = get_option( 'active_plugins', array() );
		$list   = array();

		foreach ( $all as $file => $data ) {
			$list[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ),
			);
		}

		return new WP_REST_Response( array( 'plugins' => $list ), 200 );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function active_post_types() : array {
		$types = array_filter(
			KBS_Scanner::SCAN_POST_TYPES,
			fn( $pt ) => post_type_exists( $pt )
		);
		return empty( $types ) ? array( 'post', 'page' ) : array_values( $types );
	}
}
