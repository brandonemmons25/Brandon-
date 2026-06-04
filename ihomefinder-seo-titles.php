<?php
/**
 * iHomefinder Kestrel — SEO Title Fix (Yoast SEO)
 *
 * Paste into functions.php or add via WPCode / Code Snippets.
 *
 * Works on all iHF Kestrel sites with no per-site configuration.
 *
 * Problem: iHF Kestrel routes all IDX URLs through one WordPress page,
 * so Yoast always outputs that container page's title. Result: every
 * iHF page shows the same title tag.
 *
 * Fix: read the real URL path from REQUEST_URI (which iHF does not
 * modify), match it against iHF's standard page slugs, and build a
 * unique title. City/area landing pages (/i/slug) and market sub-pages
 * (e.g. /listing-report/market-name/id/) are titled automatically from
 * the slug — no manual list needed.
 *
 * Prerequisite: uncheck "Disable SEO Plugins on IDX Pages" in the
 * iHomefinder plugin settings so Yoast is allowed to run.
 */

add_filter( 'wpseo_title', 'ihf_fix_seo_title', 20 );

function ihf_fix_seo_title( $title ) {

	// phpcs:disable WordPress.Security.NonceVerification.Recommended

	// Only act when iHF query params are present
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

	// iHF's standard page slugs — identical across all Kestrel sites
	$known_pages = array(
		'homes-for-sale-search'    => 'Property Search',
		'homes-for-sale-featured'  => 'Featured Properties',
		'open-home-search'         => 'Open Houses',
		'sold-featured-listing'    => 'Sold Properties',
		'supplemental-listing'     => 'Supplemental Listings',
		'mortgage-calculator'      => 'Mortgage Calculator',
		'valuation-form'           => 'Home Valuation',
		'listing-report'           => 'Listing Report',
		'agent-list'               => 'Agent Directory',
		'property-organizer-login' => 'Property Organizer Login',
		'contact-us'               => 'Contact Us',
	);

	$small_words = array( 'and', 'for', 'in', 'of', 'the', 'a', 'an', 'at', 'by', 'or' );
	$path        = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	$site        = get_bloginfo( 'name' );
	$page_title  = null;

	// Scan all path segments for a known iHF page slug.
	// Handles top-level pages (/listing-report/) and market sub-pages
	// (/listing-report/Market-Name/2979423/).
	$segments = array_values( array_filter( explode( '/', $path ) ) );
	foreach ( $segments as $idx => $segment ) {
		if ( isset( $known_pages[ $segment ] ) ) {
			$next = isset( $segments[ $idx + 1 ] ) ? $segments[ $idx + 1 ] : '';
			if ( $next && ! is_numeric( $next ) ) {
				// Sub-page / market name — format from slug
				$words  = explode( '-', strtolower( $next ) );
				$titled = array();
				foreach ( $words as $i => $word ) {
					$titled[] = ( $i === 0 || ! in_array( $word, $small_words, true ) )
						? ucfirst( $word )
						: $word;
				}
				$page_title = $known_pages[ $segment ] . ': ' . implode( ' ', $titled );
			} else {
				$page_title = $known_pages[ $segment ];
			}
			break;
		}
	}

	// City / area landing pages (/i/slug)
	// e.g. "aliso-viejo-homes-for-sale" → "Aliso Viejo Homes for Sale"
	if ( ! $page_title && preg_match( '#(?:^|/)i/#', $path ) ) {
		$slug   = basename( $path );
		$words  = explode( '-', strtolower( $slug ) );
		$titled = array();
		foreach ( $words as $i => $word ) {
			$titled[] = ( $i === 0 || ! in_array( $word, $small_words, true ) )
				? ucfirst( $word )
				: $word;
		}
		$page_title = implode( ' ', $titled );
	}

	// Bail if this isn't an iHF page at all
	if ( ! $page_title && ! $is_ihf ) {
		return $title;
	}

	if ( ! $page_title ) {
		$page_title = 'Properties';
	}

	// Append city or zip context when present in the URL
	if ( ! empty( $_GET['city'] ) ) {
		$city        = sanitize_text_field( wp_unslash( $_GET['city'] ) );
		$page_title .= ' in ' . ucwords( strtolower( $city ) );
	} elseif ( ! empty( $_GET['zipCode'] ) ) {
		$page_title .= ' ' . sanitize_text_field( wp_unslash( $_GET['zipCode'] ) );
	}

	// phpcs:enable

	return $page_title . ' | ' . $site;
}
