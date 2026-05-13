<?php
/**
 * Individual health checks run against Kadence blocks and environment.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class KBS_Checks {

	/** Known Kadence remote hostnames that may be unreachable during an outage */
	const KADENCE_HOSTS = array(
		'https://www.kadencewp.com',
		'https://kadencewp.com',
		'https://api.kadencewp.com',
		'https://content.kadencewp.com',
		'https://licensing.kadencewp.com',
	);

	/** Kadence block name prefixes */
	const BLOCK_PREFIXES = array(
		'kadence/',
		'kt/',
	);

	/** Deprecated / renamed block slugs (old => new) */
	const DEPRECATED_BLOCKS = array(
		'kadence/column'              => 'kadence/column (use kadence/advancedcolumn)',
		'kadence/tabs'                => 'kadence/tabs (check for updated API)',
		'kadence/spacer'              => 'kadence/spacer (check for updated API)',
		'kt/advancedgallery'          => 'kadence/advancedgallery',
		'kadence/accordion'           => 'kadence/accordion (verify nesting)',
	);

	/** ---------------------------------------------------------------
	 * 1. Connectivity check — can we reach Kadence servers?
	 * --------------------------------------------------------------- */
	public static function check_kadence_connectivity() : array {
		$results = array();

		foreach ( self::KADENCE_HOSTS as $url ) {
			$host    = wp_parse_url( $url, PHP_URL_HOST );
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

			$code = wp_remote_retrieve_response_code( $response );
			// 301/302 to Liquid Web or non-Kadence domain = outage redirect
			$redirect_url = wp_remote_retrieve_header( $response, 'location' );
			$redirected   = ! empty( $redirect_url ) && strpos( $redirect_url, 'liquidweb' ) !== false;

			if ( $redirected ) {
				$results[] = array(
					'status'   => 'error',
					'host'     => $host,
					'message'  => sprintf( 'Redirected to Liquid Web hosting page (%s). Kadence servers appear offline.', esc_url( $redirect_url ) ),
					'redirect' => $redirect_url,
				);
			} elseif ( $code >= 200 && $code < 400 ) {
				$results[] = array(
					'status'  => 'ok',
					'host'    => $host,
					'message' => "Reachable (HTTP $code)",
				);
			} else {
				$results[] = array(
					'status'  => 'warning',
					'host'    => $host,
					'message' => "Unexpected HTTP response: $code",
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

		$all_plugins = get_plugins();
		$kadence     = array();

		foreach ( $all_plugins as $path => $data ) {
			$slug = strtolower( $data['Name'] . ' ' . $path );
			if ( strpos( $slug, 'kadence' ) !== false || strpos( $slug, 'kadencewp' ) !== false ) {
				$kadence[ $path ] = array(
					'name'    => $data['Name'],
					'version' => $data['Version'],
					'active'  => is_plugin_active( $path ),
					'pro'     => ( strpos( strtolower( $data['Name'] ), 'pro' ) !== false ),
				);
			}
		}

		// Theme
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
	 * 3. License key transients / options — detect expired/invalid state
	 * --------------------------------------------------------------- */
	public static function check_license_status() : array {
		$issues = array();

		// Common Kadence license option patterns
		$license_keys = array(
			'kadence_blocks_pro_license_key',
			'kadence_theme_pro_license_key',
			'kadence_pro_license_key',
			'ktp_license_key',
			'kb_pro_license_key',
		);

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
			if ( in_array( $val_lower, array( 'invalid', 'expired', 'deactivated', 'failed', 'error', '' ), true ) ) {
				$issues[] = array(
					'option'  => $opt,
					'status'  => $val ?: '(empty)',
					'message' => "License status may be invalid — likely caused by inability to reach Kadence servers.",
				);
			}
		}

		// Also check for transient errors stored by Kadence license pinger
		$transient_patterns = array(
			'_kadence_license_',
			'kadence_activation_',
		);

		global $wpdb;
		foreach ( $transient_patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 20",
					'%' . $wpdb->esc_like( $pattern ) . '%'
				)
			);
			foreach ( $rows as $row ) {
				$issues[] = array(
					'option'  => $row->option_name,
					'status'  => substr( $row->option_value, 0, 120 ),
					'message' => 'Kadence license transient found — review for error state.',
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 4. Detect blocks that load remote Kadence assets
	 * --------------------------------------------------------------- */
	public static function check_block_for_remote_assets( array $block ) : array {
		$issues = array();
		$attrs  = $block['attrs'] ?? array();
		$name   = $block['blockName'] ?? '';

		// Flatten attrs to a searchable string
		$attr_str = wp_json_encode( $attrs );

		foreach ( self::KADENCE_HOSTS as $host ) {
			if ( strpos( $attr_str, $host ) !== false || strpos( $attr_str, 'kadencewp.com' ) !== false ) {
				$issues[] = array(
					'type'    => 'remote_asset',
					'block'   => $name,
					'message' => 'Block attribute references a kadencewp.com URL which may be unreachable during the outage.',
					'detail'  => self::extract_kadence_urls( $attr_str ),
				);
				break;
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 5. Check for broken image attachments inside a block
	 * --------------------------------------------------------------- */
	public static function check_block_for_missing_images( array $block ) : array {
		$issues = array();
		$attrs  = $block['attrs'] ?? array();
		$name   = $block['blockName'] ?? '';

		$img_id_keys = array( 'mediaID', 'imgID', 'imageID', 'id', 'mediaId' );

		foreach ( $img_id_keys as $key ) {
			if ( ! isset( $attrs[ $key ] ) ) continue;
			$id = (int) $attrs[ $key ];
			if ( $id <= 0 ) continue;

			$post = get_post( $id );
			if ( ! $post || $post->post_status !== 'inherit' ) {
				$issues[] = array(
					'type'    => 'missing_image',
					'block'   => $name,
					'message' => "Image attachment ID {$id} (attr: {$key}) does not exist or has been deleted.",
					'detail'  => "Attachment ID: $id",
				);
			}
		}

		// Check URL-based images
		$url_keys = array( 'mediaURL', 'imgURL', 'url', 'mediaUrl' );
		foreach ( $url_keys as $key ) {
			if ( empty( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) continue;
			$url = $attrs[ $key ];
			// Only check local URLs
			if ( strpos( $url, home_url() ) === false && strpos( $url, site_url() ) === false ) continue;
			$attachment_id = attachment_url_to_postid( $url );
			if ( ! $attachment_id ) {
				$issues[] = array(
					'type'    => 'missing_image_url',
					'block'   => $name,
					'message' => "Local image URL in attr '{$key}' has no matching attachment: {$url}",
					'detail'  => $url,
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 6. Detect malformed / unparseable block attributes
	 * --------------------------------------------------------------- */
	public static function check_block_attributes_valid( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$raw    = $block['innerHTML'] ?? '';

		// Try to extract and parse the JSON comment header
		if ( preg_match( '/<!--\s*wp:' . preg_quote( ltrim( $name, 'kadence/' ), '/' ) . '\s+(\{.*?\})\s*(?:\/)?-->/s', $raw, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$issues[] = array(
					'type'    => 'malformed_attrs',
					'block'   => $name,
					'message' => 'Block JSON attributes are malformed: ' . json_last_error_msg(),
					'detail'  => substr( $m[1], 0, 200 ),
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 7. Check for deprecated block names
	 * --------------------------------------------------------------- */
	public static function check_deprecated_block( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';

		if ( isset( self::DEPRECATED_BLOCKS[ $name ] ) ) {
			$issues[] = array(
				'type'    => 'deprecated_block',
				'block'   => $name,
				'message' => 'This block name is deprecated. Replacement: ' . self::DEPRECATED_BLOCKS[ $name ],
				'detail'  => '',
			);
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 8. Check Kadence Element (CPT) references
	 * --------------------------------------------------------------- */
	public static function check_kadence_element_reference( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$attrs  = $block['attrs'] ?? array();

		// kadence/element and shortcode-based Kadence Elements use an ID
		$id_keys = array( 'id', 'element', 'elementId', 'postId' );
		if ( in_array( $name, array( 'kadence/element', 'kadence/header', 'kadence/footer' ), true ) ) {
			foreach ( $id_keys as $key ) {
				if ( empty( $attrs[ $key ] ) ) continue;
				$id   = (int) $attrs[ $key ];
				$post = get_post( $id );
				if ( ! $post ) {
					$issues[] = array(
						'type'    => 'missing_element',
						'block'   => $name,
						'message' => "Kadence Element/Header/Footer with ID {$id} does not exist.",
						'detail'  => "Post ID: $id",
					);
				} elseif ( $post->post_status !== 'publish' ) {
					$issues[] = array(
						'type'    => 'unpublished_element',
						'block'   => $name,
						'message' => "Kadence Element ID {$id} exists but has status '{$post->post_status}'.",
						'detail'  => "Post ID: $id, Status: {$post->post_status}",
					);
				}
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 9. Check for broken links in button / link blocks
	 * --------------------------------------------------------------- */
	public static function check_broken_links( array $block ) : array {
		$issues = array();
		$name   = $block['blockName'] ?? '';
		$attrs  = $block['attrs'] ?? array();

		$url_attrs = array( 'link', 'linkURL', 'url', 'href' );
		foreach ( $url_attrs as $key ) {
			if ( empty( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) continue;
			$url = $attrs[ $key ];
			// Only flag internal links that 404
			if ( strpos( $url, home_url() ) === false && strpos( $url, site_url() ) === false ) continue;
			$path    = str_replace( array( home_url(), site_url() ), '', $url );
			$post_id = url_to_postid( $url );
			if ( ! $post_id && ! empty( $path ) && $path !== '/' ) {
				$issues[] = array(
					'type'    => 'broken_internal_link',
					'block'   => $name,
					'message' => "Internal link in attr '{$key}' may be broken (no matching post): {$url}",
					'detail'  => $url,
				);
			}
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * 10. Check enqueueing — are Kadence scripts actually loading?
	 * --------------------------------------------------------------- */
	public static function check_kadence_assets_enqueued() : array {
		$issues  = array();
		$scripts = wp_scripts();
		$styles  = wp_styles();

		$found_script = false;
		$found_style  = false;

		foreach ( $scripts->registered as $handle => $script ) {
			if ( strpos( $handle, 'kadence' ) !== false ) {
				$found_script = true;
				break;
			}
		}
		foreach ( $styles->registered as $handle => $style ) {
			if ( strpos( $handle, 'kadence' ) !== false ) {
				$found_style = true;
				break;
			}
		}

		if ( ! $found_script && ! $found_style ) {
			$issues[] = array(
				'type'    => 'no_assets',
				'message' => 'No Kadence scripts or styles appear to be registered. Kadence Blocks may not be active or assets may have failed to load.',
			);
		}

		return $issues;
	}

	/** ---------------------------------------------------------------
	 * Helper: extract kadencewp.com URLs from a JSON string
	 * --------------------------------------------------------------- */
	private static function extract_kadence_urls( string $json ) : string {
		preg_match_all( '/"(https?:\/\/[^"]*kadencewp\.com[^"]*)"/', $json, $matches );
		return implode( ', ', array_unique( $matches[1] ?? array() ) );
	}
}
