<?php
/**
 * iHomefinder Kestrel — SEO Title Fix (Yoast SEO)
 *
 * Paste into functions.php or add via WPCode / Code Snippets.
 *
 * Works on all iHF Kestrel sites with no per-site configuration.
 *
 * Problem: iHF Kestrel routes all IDX URLs through one WordPress page,
 * so Yoast always outputs that container page's title.
 *
 * Fix: detect iHF virtual pages via two signals and auto-generate
 * a unique title from the URL path — no hardcoded slug list needed.
 *
 * Prerequisite: uncheck "Disable SEO Plugins on IDX Pages" in the
 * iHomefinder plugin settings so Yoast is allowed to run.
 */

add_filter( 'wpseo_title', 'ihf_fix_seo_title', 20 );

function ihf_fix_seo_title( $title ) {

	// phpcs:disable WordPress.Security.NonceVerification.Recommended

	$path     = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	$segments = array_values( array_filter( explode( '/', $path ) ) );
	$site     = get_bloginfo( 'name' );

	// Signal 1: iHF query params (search results, filtered pages)
	$ihf_params = array(
		'boardId', 'listingId', 'propertyType', 'status', 'city',
		'zipCode', 'minPrice', 'maxPrice', 'minBeds', 'maxBeds',
		'featuredOnlyYn', 'openHouseYn', 'sort', 'searchType',
		'startIndex', 'soldDaysBack',
	);
	$is_ihf = false;
	foreach ( $ihf_params as $p ) {
		if ( isset( $_GET[ $p ] ) ) {
			$is_ihf = true;
			break;
		}
	}

	// Signal 2: path contains a known iHF page slug (catches cases where
	// the container page slug matches the URL — no virtual page offset to detect)
	$ihf_slugs = array(
		'homes-for-sale-search', 'homes-for-sale-featured', 'homes-for-sale-toppicks',
		'open-home-search', 'sold-featured-listing', 'supplemental-listing',
		'mortgage-calculator', 'valuation-form', 'listing-report',
		'agent-list', 'property-organizer-login', 'contact-us',
	);
	$is_ihf_slug = false;
	foreach ( $segments as $segment ) {
		if ( in_array( $segment, $ihf_slugs, true ) ) {
			$is_ihf_slug = true;
			break;
		}
	}

	// Signal 3: iHF virtual page — WordPress renders the container page
	// but REQUEST_URI is deeper than the container page's own permalink.
	// Handles both /container/virtual-page/ and /virtual-page/SubPage/123/.
	global $post;
	$is_ihf_virtual = false;
	$container_slug = '';
	if ( ! $is_ihf && ! $is_ihf_slug && $post && is_page() ) {
		$container_slug = $post->post_name;
		$page_path      = trim( parse_url( get_permalink( $post->ID ), PHP_URL_PATH ), '/' );
		$is_ihf_virtual = ( $path !== $page_path );
	}

	if ( ! $is_ihf && ! $is_ihf_slug && ! $is_ihf_virtual ) {
		return $title;
	}

	// Auto-generate title from meaningful URL segments.
	// Skip: the container page slug and numeric IDs.
	$small_words = array( 'and', 'for', 'in', 'of', 'the', 'a', 'an', 'at', 'by', 'or' );

	$to_title = function( $slug ) use ( $small_words ) {
		$words  = explode( '-', strtolower( $slug ) );
		$titled = array();
		foreach ( $words as $i => $word ) {
			$titled[] = ( $i === 0 || ! in_array( $word, $small_words, true ) )
				? ucfirst( $word )
				: $word;
		}
		return implode( ' ', $titled );
	};

	$parts = array();
	foreach ( $segments as $segment ) {
		if ( is_numeric( $segment ) || $segment === $container_slug ) {
			continue;
		}
		$parts[] = $to_title( $segment );
		if ( count( $parts ) >= 2 ) {
			break;
		}
	}

	if ( empty( $parts ) ) {
		return $title;
	}

	$page_title = implode( ': ', $parts );

	// Append city or zip context when present
	if ( ! empty( $_GET['city'] ) ) {
		$city        = sanitize_text_field( wp_unslash( $_GET['city'] ) );
		$page_title .= ' in ' . ucwords( strtolower( $city ) );
	} elseif ( ! empty( $_GET['zipCode'] ) ) {
		$page_title .= ' ' . sanitize_text_field( wp_unslash( $_GET['zipCode'] ) );
	}

	// phpcs:enable

	return $page_title . ' | ' . $site;
}
