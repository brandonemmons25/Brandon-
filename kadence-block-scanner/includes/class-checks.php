<?php
/**
 * Health checks — only flags things that are actually broken on the front end.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Checks {

	const KADENCE_HOSTS = array(
		'https://www.kadencewp.com',
		'https://kadencewp.com',
		'https://api.kadencewp.com',
		'https://content.kadencewp.com',
		'https://licensing.kadencewp.com',
	);

	const BLOCK_PREFIXES = array( 'kadence/', 'kt/' );

	/**
	 * Blocks where a specific attribute is a real image attachment ID.
	 * Format: block_name => [ attr_key, ... ]
	 * Only these combinations are checked — everything else is ignored.
	 */
	const IMAGE_BLOCKS = array(
		'kadence/image'            => array( 'id', 'mediaID' ),
		'kadence/advancedgallery'  => array( 'mediaID' ),
		'kadence/cover'            => array( 'id', 'mediaID' ),
		'kadence/videopopup'       => array( 'mediaID' ),
		'kadence/singlebtn'        => array( 'mediaID' ),
	);

	/** ---------------------------------------------------------------
	 * 1. Connectivity — detect the Kadence / Liquid Web outage
	 * --------------------------------------------------------------- */
	public static function check_kadence_connectivity() : array {
		$results = array();

		foreach ( self::KADENCE_HOSTS as $url ) {
			$host     = wp_parse_url( $url, PHP_URL_HOST );
			$response = wp_remote_head( $url, array(
				'timeout'    => 8,
				'user-agent' => 'KadenceBlockScanner/' . KBS_VERSION,
				'sslverify'  => false,
			) );

			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'status'  => 'error',
					'host'    => $host,
					'message' => $response->get_error_message(),
				);
				continue;
			}

			$code         = wp_remote_retrieve_response_code( $response );
			$redirect_url = wp_remote_retrieve_header( $response, 'location' );
			$liquidweb    = ! empty( $redirect_url ) && strpos( $redirect_url, 'liquidweb' ) !== false;

			if ( $liquidweb ) {
				$results[] = array(
					'status'  => 'error',
					'host'    => $host,
					'message' => 'Redirecting to Liquid Web — Kadence servers are offline.',
				);
			} elseif ( $code >= 200 && $code < 400 ) {
				$results[] = array(
					'status'  => 'ok',
					'host'    => $host,
					'message' => "OK (HTTP $code)",
				);
			} else {
				$results[] = array(
					'status'  => 'error',
					'host'    => $host,
					'message' => "Unreachable (HTTP $code)",
				);
			}
		}

		return $results;
	}

	/** ---------------------------------------------------------------
	 * 2. Installed Kadence plugins & themes
	 * --------------------------------------------------------------- */
	public static function get_kadence_plugins() : array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$kadence = array();

		foreach ( get_plugins() as $path => $data ) {
			if ( strpos( strtolower( $data['Name'] . $path ), 'kadence' ) !== false ) {
				$kadence[ $path ] = array(
					'name'    => $data['Name'],
					'version' => $data['Version'],
					'active'  => is_plugin_active( $path ),
					'pro'     => strpos( strtolower( $data['Name'] ), 'pro' ) !== false,
				);
			}
		}

		$theme = wp_get_theme();
		if ( strpos( strtolower( $theme->get( 'Name' ) ), 'kadence' ) !== false ) {
			$kadence['theme'] = array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => true,
				'pro'     => false,
			);
		}

		return $kadence;
	}

	/** ---------------------------------------------------------------
	 * 3. Pro license status — only flags actually invalid/expired licenses
	 * --------------------------------------------------------------- */
	public static function check_license_status() : array {
		$issues = array();

		$status_keys = array(
			'kadence_blocks_pro_license_status',
			'kadence_theme_pro_license_status',
			'kadence_pro_license_status',
			'ktp_license_status',
			'kb_pro_license_status',
		);

		foreach ( $status_keys as $opt ) {
			$val = get_option( $opt );
			if ( false === $val ) continue;

			$val_lower = strtolower( (string) $val );
			if ( in_array( $val_lower, array( 'invalid', 'expired', 'deactivated', 'failed', 'error' ), true ) ) {
				$issues[] = array(
					'option'  => $opt,
					'status'  => $val,
					'message' => 'Pro license is ' . $val . '. Pro features may be disabled. This is likely caused by the Kadence server outage.',
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 4. Block: image attachment no longer exists in media library
	 *    Only checks blocks that actually display an image via attachment ID.
	 * --------------------------------------------------------------- */
	public static function check_block_for_missing_images( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$attrs  = $block['attrs'] ?? array();

		if ( ! isset( self::IMAGE_BLOCKS[ $name ] ) ) {
			return $issues;
		}

		foreach ( self::IMAGE_BLOCKS[ $name ] as $key ) {
			if ( empty( $attrs[ $key ] ) ) continue;
			$id   = (int) $attrs[ $key ];
			$post = $id > 0 ? get_post( $id ) : null;

			if ( ! $post || $post->post_status !== 'inherit' ) {
				$issues[] = array(
					'type'    => 'missing_image',
					'block'   => $name,
					'message' => "Image (attachment ID {$id}) no longer exists in the media library.",
					'detail'  => "Attachment ID: $id",
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 5. Block: references a Kadence Element/Header/Footer that is missing
	 * --------------------------------------------------------------- */
	public static function check_kadence_element_reference( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$attrs  = $block['attrs'] ?? array();

		if ( ! in_array( $name, array( 'kadence/element', 'kadence/header', 'kadence/footer' ), true ) ) {
			return $issues;
		}

		foreach ( array( 'id', 'element', 'elementId', 'postId' ) as $key ) {
			if ( empty( $attrs[ $key ] ) ) continue;
			$id   = (int) $attrs[ $key ];
			$post = get_post( $id );

			if ( ! $post ) {
				$issues[] = array(
					'type'    => 'missing_element',
					'block'   => $name,
					'message' => "Kadence Element (ID {$id}) no longer exists — this block will render blank.",
					'detail'  => "Post ID: $id",
				);
			} elseif ( $post->post_status !== 'publish' ) {
				$issues[] = array(
					'type'    => 'unpublished_element',
					'block'   => $name,
					'message' => "Kadence Element (ID {$id}) is not published (status: {$post->post_status}) — this block will render blank.",
					'detail'  => "Post ID: $id",
				);
			}
			break;
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 6. Block: internal link points to a page that doesn't exist
	 * --------------------------------------------------------------- */
	public static function check_broken_links( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$attrs  = $block['attrs'] ?? array();

		foreach ( array( 'link', 'linkURL', 'url', 'href' ) as $key ) {
			if ( empty( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) continue;
			$url = $attrs[ $key ];

			if ( strpos( $url, home_url() ) === false && strpos( $url, site_url() ) === false ) continue;

			$path = str_replace( array( home_url(), site_url() ), '', $url );

			// Skip file downloads and anchors — they're not posts
			if ( strpos( $path, '/wp-content/uploads/' ) !== false ) continue;
			if ( strpos( $path, '#' ) === 0 ) continue;

			if ( ! url_to_postid( $url ) && ! empty( $path ) && $path !== '/' ) {
				$issues[] = array(
					'type'    => 'broken_internal_link',
					'block'   => $name,
					'message' => "Link points to a page that doesn't exist: {$url}",
					'detail'  => $url,
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 7. Block: attribute JSON is malformed (block will show error in editor)
	 * --------------------------------------------------------------- */
	public static function check_block_attributes_valid( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$raw    = $block['innerHTML'] ?? '';

		$short = ltrim( str_replace( 'kadence/', '', $name ), 'kt/' );
		if ( preg_match( '/<!--\s*wp:' . preg_quote( $short, '/' ) . '\s+(\{.*?\})\s*(?:\/)?-->/s', $raw, $m ) ) {
			json_decode( $m[1] );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$issues[] = array(
					'type'    => 'malformed_attrs',
					'block'   => $name,
					'message' => 'Block has malformed JSON attributes — it will show a block error in the editor and may not render correctly.',
					'detail'  => substr( $m[1], 0, 200 ),
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 8. Block: attribute references a kadencewp.com URL (outage risk)
	 * --------------------------------------------------------------- */
	public static function check_block_for_remote_assets( array $block ) : array {
		$issues   = array();
		$name     = $block['blockName'] ?? '';
		$attr_str = wp_json_encode( $block['attrs'] ?? array() );

		if ( strpos( $attr_str, 'kadencewp.com' ) !== false ) {
			preg_match_all( '/"(https?:\/\/[^"]*kadencewp\.com[^"]*)"/', $attr_str, $m );
			$urls = implode( ', ', array_unique( $m[1] ?? array() ) );
			$issues[] = array(
				'type'    => 'remote_asset',
				'block'   => $name,
				'message' => 'Block loads an asset from kadencewp.com which is currently offline.',
				'detail'  => $urls,
			);
		}

		return $issues;
	}
}
