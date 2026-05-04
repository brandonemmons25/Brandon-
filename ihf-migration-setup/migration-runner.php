<?php
/**
 * Server-side migration engine.
 * Runs entirely within WordPress — no external HTTP calls required.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── URL Redirect Map ──────────────────────────────────────────────────────────

function ims_get_url_redirect_map(): array {
	return [
		'/idx/search/advanced'          => '/homes-for-sale-search/',
		'/idx/search/homes'             => '/homes-for-sale-search/',
		'/idx/search/address'           => '/homes-for-sale-search/',
		'/idx/search/smart'             => '/homes-for-sale-search/',
		'/idx/search/basic'             => '/homes-for-sale-search/',
		'/idx/search/emailupdatesignup' => '/homes-for-sale-search/',
		'/idx/search/listingid'         => '/homes-for-sale-search/',
		'/idx/searchbycity'             => '/homes-for-sale-search/',
		'/idx/sitemap'                  => '/homes-for-sale-search/',
		'/idx/map/mapsearch'            => '/homes-for-sale-search/',
		'/idx/linkshowcase'             => '/homes-for-sale-search/',
		'/idx/featuredvirtualtour'      => '/homes-for-sale-search/',
		'/idx/featured'                 => '/homes-for-sale-featured/',
		'/idx/soldpending'              => '/sold-featured-listing/',
		'/idx/mortgage'                 => '/mortgage-calculator/',
		'/idx/homevaluation'            => '/home-valuation/',
		'/idx/roster'                   => '/agent-list/',
		'/idx/contact'                  => '/contact-us/',
		'/idx/userlogin'                => '/property-organizer-login/',
		'/idx/usersignup'               => '/property-organizer-login/?section=signin',
		'/idx/featuredopenhouse'        => '/open-home-search/',
		'/idx/supplemental'             => '/supplemental-listing/',
		'/idx/market-reports'           => '/homes-for-sale-search/',
	];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function ims_get_idx_search_domain(): string {
	// 1. Manual override (highest priority)
	$override = get_option( IMS_OPT_IDX_DOMAIN, '' );
	if ( $override ) return $override;

	// 2. Scanner result
	$scan = get_option( IMS_OPT_SCAN, [] );
	if ( ! empty( $scan['search_domain'] ) ) {
		return $scan['search_domain'];
	}

	// 2. Detect from existing menu item URLs
	foreach ( wp_get_nav_menus() as $menu ) {
		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( ! $items ) continue;
		foreach ( $items as $item ) {
			if ( preg_match( '#(?:https?:)?//([\w.-]+)/idx/#', $item->url, $m ) ) {
				return $m[1];
			}
			if ( preg_match( '#(?:https?:)?//([\w.-]+)/i/#', $item->url, $m ) ) {
				return $m[1];
			}
		}
	}

	// 3. Detect from page/post content
	global $wpdb;
	$row = $wpdb->get_var(
		"SELECT post_content FROM {$wpdb->posts}
		 WHERE post_status = 'publish' AND post_content LIKE '%/idx/%'
		 LIMIT 1"
	);
	if ( $row && preg_match( '#(?:https?:)?//([\w.-]+)/idx/#', $row, $m ) ) {
		return $m[1];
	}

	// 4. Last resort: search.[production-host]
	$host = parse_url( home_url(), PHP_URL_HOST ) ?: '';
	return 'search.' . $host;
}

function ims_map_idx_url( string $url ): ?string {
	$redirect_map = ims_get_url_redirect_map();
	$parsed       = parse_url( $url );
	$path         = $parsed['path'] ?? '';
	$qs           = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
	$home         = untrailingslashit( home_url() );

	// Direct match
	if ( isset( $redirect_map[ $path ] ) ) {
		$ihf = $redirect_map[ $path ];
		if ( strpos( $ihf, '?' ) !== false && $qs ) {
			return $home . $ihf . '&' . ltrim( $qs, '?' );
		}
		return $home . $ihf . $qs;
	}

	// Prefix match
	foreach ( $redirect_map as $idx_path => $ihf_path ) {
		if ( strpos( $path, $idx_path ) === 0 ) {
			return $home . $ihf_path . $qs;
		}
	}

	// /i/ saved link
	if ( preg_match( '#^/i/([\w-]+)/?$#', $path, $m ) ) {
		return ims_map_saved_link_slug( $m[1] );
	}

	return null;
}

function ims_map_saved_link_slug( string $slug ): ?string {
	$markets = get_option( IMS_OPT_MARKETS, [] );
	$home    = untrailingslashit( home_url() );
	foreach ( $markets as $market ) {
		$market_url = $market['url'] ?? '';
		if ( ! $market_url ) continue;
		if ( preg_match( '#/listing-report/([^/]+)/#', $market_url, $m ) ) {
			if ( $m[1] === $slug ) {
				return $home . $market_url;
			}
			// Fuzzy: one slug contains the other
			if ( strpos( $slug, $m[1] ) !== false || strpos( $m[1], $slug ) !== false ) {
				return $home . $market_url;
			}
		}
	}
	return null;
}

// ── Menu Migration ────────────────────────────────────────────────────────────

function ims_run_menu_migration( bool $dry_run = false ): array {
	$menus         = wp_get_nav_menus();
	$search_domain = ims_get_idx_search_domain();
	$changes       = [];
	$unmapped      = [];

	foreach ( $menus as $menu ) {
		$items = wp_get_nav_menu_items( $menu->term_id );
		if ( ! $items ) continue;

		foreach ( $items as $item ) {
			$url = trim( $item->url );
			if ( strpos( $url, $search_domain ) === false ) continue;

			$new_url = ims_map_idx_url( $url );

			if ( ! $new_url ) {
				$unmapped[] = [
					'menu'    => $menu->name,
					'item'    => $item->title,
					'item_id' => $item->ID,
					'url'     => $url,
				];
				continue;
			}

			if ( ! $dry_run ) {
				update_post_meta( $item->ID, '_menu_item_url', $new_url );
				clean_post_cache( $item->ID );
			}

			$changes[] = [
				'menu'    => $menu->name,
				'item'    => $item->title,
				'item_id' => $item->ID,
				'before'  => $url,
				'after'   => $new_url,
			];
		}
	}

	return [
		'changes'  => $changes,
		'unmapped' => $unmapped,
		'total'    => count( $changes ),
		'dry_run'  => $dry_run,
	];
}

// ── Content Replacement ───────────────────────────────────────────────────────

function ims_replace_idx_in_content( string $content, string $search_domain ): array {
	$original     = $content;
	$redirect_map = ims_get_url_redirect_map();
	$home         = untrailingslashit( home_url() );
	$replacements = [];

	// 1. Subdomain URL replacements (https://, http://, //)
	foreach ( $redirect_map as $idx_path => $ihf_path ) {
		foreach ( [ "https://{$search_domain}", "http://{$search_domain}", "//{$search_domain}" ] as $prefix ) {
			$target  = $prefix . $idx_path;
			$replace = $home . $ihf_path;
			if ( strpos( $content, $target ) !== false ) {
				$content        = str_replace( $target, $replace, $content );
				$replacements[] = "URL: {$target} → {$replace}";
			}
		}
	}

	// 2. /i/ saved link URLs
	$content = preg_replace_callback(
		'#(https?:)?//' . preg_quote( $search_domain, '#' ) . '/i/([\w-]+)#',
		function ( $m ) use ( $home, &$replacements ) {
			$mapped = ims_map_saved_link_slug( $m[2] );
			if ( $mapped ) {
				$replacements[] = "Saved link: /i/{$m[2]} → {$mapped}";
				return $mapped;
			}
			$replacements[] = "⚠ UNMAPPED saved link: /i/{$m[2]} — needs manual review";
			return $m[0];
		},
		$content
	);

	// 3. IDX Gutenberg blocks (self-closing and paired)
	$content = preg_replace_callback(
		'/<!-- wp:idx-broker-platinum\/([\w-]+)\s*(\{[^}]*\})?\s*\/-->/',
		function ( $m ) use ( &$replacements ) {
			$sc = ims_map_idx_block( $m[1], json_decode( $m[2] ?? '{}', true ) ?: [] );
			if ( $sc !== null ) {
				$replacements[] = "Block (self-closing): idx-broker-platinum/{$m[1]} → {$sc}";
				return $sc;
			}
			$replacements[] = "⚠ UNMAPPED block: idx-broker-platinum/{$m[1]}";
			return $m[0];
		},
		$content
	);
	$content = preg_replace_callback(
		'/<!-- wp:idx-broker-platinum\/([\w-]+)\s*(\{[^}]*\})?\s*-->[\s\S]*?<!-- \/wp:idx-broker-platinum\/[\w-]+ -->/',
		function ( $m ) use ( &$replacements ) {
			$sc = ims_map_idx_block( $m[1], json_decode( $m[2] ?? '{}', true ) ?: [] );
			if ( $sc !== null ) {
				$replacements[] = "Block (paired): idx-broker-platinum/{$m[1]} → {$sc}";
				return $sc;
			}
			$replacements[] = "⚠ UNMAPPED block: idx-broker-platinum/{$m[1]}";
			return $m[0];
		},
		$content
	);

	// 4. IDX shortcodes
	$sc_map = [
		'idx-omnibar'               => '[optima_express_quick_search style="horizontal" showPropertyType="true"]',
		'IDX-search'                => '[optima_express_map_search]',
		'impress_property_showcase' => '[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="active"]',
		'impress_property_carousel' => '[optima_express_gallery_slider rows="1" columns="3" effect="slide" auto="true" status="active" maxResults="25"]',
		'idxbroker'                 => '[optima_express_map_search]',
	];
	foreach ( $sc_map as $idx_sc => $ihf_sc ) {
		if ( strpos( $content, "[{$idx_sc}" ) !== false || strpos( $content, "[{$idx_sc} " ) !== false ) {
			$content        = preg_replace( '/\[' . preg_quote( $idx_sc, '/' ) . '[^\]]*\]/', $ihf_sc, $content );
			$replacements[] = "Shortcode: [{$idx_sc}] → {$ihf_sc}";
		}
	}

	return [
		'content'      => $content,
		'changed'      => $content !== $original,
		'replacements' => $replacements,
	];
}

function ims_map_idx_block( string $block_type, array $attrs ): ?string {
	// Saved link block — needs market lookup
	if ( isset( $attrs['saved_link_id'] ) ) {
		return '[optima_express_toppicks id=MARKET_ID includeMap="true"]';
	}

	$map = [
		'omnibar-search-widget'  => '[optima_express_quick_search style="horizontal" showPropertyType="true"]',
		'advanced-search-widget' => '[optima_express_map_search]',
		'featured-properties'    => '[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="active"]',
		'carousel-widget'        => '[optima_express_gallery_slider rows="1" columns="3" effect="slide" auto="true" status="active" maxResults="25"]',
		'showcase-widget'        => '[optima_express_featured sortBy="ds" displayType="grid" resultsPerPage="25" header="true" includeMap="false" status="active"]',
		'map-search-widget'      => '[optima_express_map_search]',
		'mortgage-calculator'    => '[optima_express_mortgage_calculator]',
		'home-valuation'         => '[optima_express_valuation_form]',
		'lead-login-widget'      => '',
		'lead-signup-widget'     => '',
	];

	return $map[ $block_type ] ?? null;
}

// ── Page / Post Migration ─────────────────────────────────────────────────────

function ims_run_page_migration( bool $dry_run = false ): array {
	return ims_run_content_migration( 'page', $dry_run );
}

function ims_run_post_migration( bool $dry_run = false ): array {
	return ims_run_content_migration( 'post', $dry_run );
}

function ims_run_content_migration( string $post_type, bool $dry_run ): array {
	global $wpdb;
	$search_domain = ims_get_idx_search_domain();
	$scan          = get_option( IMS_OPT_SCAN, [] );
	$changes       = [];
	$skipped       = [];
	$ids           = [];

	$scan_key = $post_type === 'page' ? 'pages' : 'posts';
	foreach ( $scan[ $scan_key ] ?? [] as $entry ) {
		// Scanner returns lowercase 'id'; accommodate both
		$id = $entry['ID'] ?? $entry['id'] ?? null;
		if ( $id ) $ids[] = (int) $id;
	}

	// Fallback: query DB directly
	if ( empty( $ids ) ) {
		$like_patterns = [
			'%' . $wpdb->esc_like( $search_domain ) . '%',
			'%idx-broker-platinum%',
			'%[IDX%',
			'%[impress_%',
		];
		$or_clauses = implode( ' OR ', array_fill( 0, count( $like_patterns ), 'post_content LIKE %s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ({$or_clauses})",
			array_merge( [ $post_type ], $like_patterns )
		) );
		$ids = array_map( 'intval', $rows );
	}

	foreach ( array_unique( $ids ) as $id ) {
		$post = get_post( $id );
		if ( ! $post ) continue;

		$result = ims_replace_idx_in_content( $post->post_content, $search_domain );

		if ( ! $result['changed'] ) {
			$skipped[] = [ 'ID' => $id, 'title' => $post->post_title ];
			continue;
		}

		if ( ! $dry_run ) {
			wp_update_post( [ 'ID' => $id, 'post_content' => $result['content'] ] );
		}

		$changes[] = [
			'ID'           => $id,
			'title'        => $post->post_title,
			'url'          => get_permalink( $id ),
			'replacements' => $result['replacements'],
		];
	}

	return [
		'changes' => $changes,
		'skipped' => $skipped,
		'total'   => count( $changes ),
		'dry_run' => $dry_run,
	];
}

// ── Verification ──────────────────────────────────────────────────────────────

function ims_run_verify(): array {
	global $wpdb;
	$search_domain = ims_get_idx_search_domain();
	$patterns      = [ $search_domain, 'idx-broker-platinum', '[IDX-', '[impress_' ];
	$found         = [];

	foreach ( $patterns as $pattern ) {
		$like = '%' . $wpdb->esc_like( $pattern ) . '%';

		foreach ( [ 'page', 'post' ] as $type ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_content LIKE %s",
				$type, $like
			) );
			foreach ( $rows as $row ) {
				$found[] = [ 'type' => $type, 'ID' => $row->ID, 'title' => $row->post_title, 'pattern' => $pattern ];
			}
		}

		$menu_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT pm.post_id, p.post_title FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_menu_item_url' AND pm.meta_value LIKE %s",
			$like
		) );
		foreach ( $menu_rows as $row ) {
			$found[] = [ 'type' => 'menu_item', 'ID' => $row->post_id, 'title' => $row->post_title, 'pattern' => $pattern ];
		}
	}

	return [
		'clean' => empty( $found ),
		'found' => $found,
		'count' => count( $found ),
	];
}
