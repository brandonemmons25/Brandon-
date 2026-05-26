<?php
/**
 * Fix iHomefinder SEO Title Tags (Yoast SEO)
 *
 * Paste into functions.php or add via Code Snippets plugin.
 *
 * Problem: Yoast outputs URL query strings (boardId, status, sort, etc.)
 * as part of the <title> tag on iHomefinder-powered pages.
 *
 * Fix: When iHomefinder params are detected, build the title from:
 *   1. The WordPress page title (set in the editor — the cleanest source)
 *   2. Optional location enrichment (city / zip from URL params)
 *   3. Site name
 */

add_filter( 'wpseo_title', 'ihf_clean_seo_title', 20 );

function ihf_clean_seo_title( $title ) {

	// Params that identify an iHomefinder-driven page request
	$ihf_params = array(
		'boardId', 'listingId', 'propertyType', 'status', 'city',
		'zipCode', 'minPrice', 'maxPrice', 'minBeds', 'maxBeds',
		'featuredOnlyYn', 'openHouseYn', 'sort', 'searchType',
		'startIndex', 'soldDaysBack',
	);

	$is_ihf_page = false;
	foreach ( $ihf_params as $param ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ $param ] ) ) {
			$is_ihf_page = true;
			break;
		}
	}

	if ( ! $is_ihf_page ) {
		return $title;
	}

	$segments = array();

	// 1. WordPress page title — set this in Pages > Edit for each iHomefinder page
	//    e.g. "Featured Homes for Sale", "Search Results", "Open Houses"
	$post_title = get_the_title( get_queried_object_id() );
	if ( $post_title ) {
		$segments[] = $post_title;
	}

	// 2. Location context (only appended when present in the URL)
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_GET['city'] ) ) {
		$city       = sanitize_text_field( wp_unslash( $_GET['city'] ) );
		$segments[] = 'in ' . ucwords( strtolower( $city ) );
	} elseif ( ! empty( $_GET['zipCode'] ) ) {
		$segments[] = sanitize_text_field( wp_unslash( $_GET['zipCode'] ) );
	}
	// phpcs:enable

	// 3. Site name
	$segments[] = get_bloginfo( 'name' );

	// If we somehow have nothing, return the original Yoast title unchanged
	if ( count( $segments ) < 2 ) {
		return $title;
	}

	return implode( ' | ', $segments );
}
