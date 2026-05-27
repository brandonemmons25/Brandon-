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
 * unique title. City/area landing pages (/i/slug) are titled
 * automatically from the slug — no manual list needed.
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
		'home-valuation'           => 'Home Valuation',
		'agent-list'               => 'Agent Directory',
		'property-organizer-login' => 'Property Organizer Login',
		'contact-us'               => 'Contact Us',
	);

	$path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
	$slug = basename( $path );
	$site = get_bloginfo( 'name' );

	// Bail early if this isn't an iHF page at all
	$is_ihf_path = isset( $known_pages[ $slug ] ) || preg_match( '#(?:^|/)i/#', $path );
	if ( ! $is_ihf && ! $is_ihf_path ) {
		return $title;
	}

	if ( isset( $known_pages[ $slug ] ) ) {
		// Known main iHF page
		$page_title = $known_pages[ $slug ];

	} elseif ( preg_match( '#(?:^|/)i/#', $path ) ) {
		// City / area landing page — auto-generate from slug
		// e.g. "aliso-viejo-homes-for-sale" → "Aliso Viejo Homes for Sale"
		$small_words = array( 'and', 'for', 'in', 'of', 'the', 'a', 'an', 'at', 'by', 'or' );
		$words       = explode( '-', $slug );
		$titled      = array();
		foreach ( $words as $i => $word ) {
			$titled[] = ( $i === 0 || ! in_array( $word, $small_words, true ) )
				? ucfirst( $word )
				: $word;
		}
		$page_title = implode( ' ', $titled );

	} else {
		// Unknown path — let Yoast handle it
		return $title;
	}

	// Append city or zip context when present in the URL
	if ( ! empty( $_GET['city'] ) ) {
		$city = sanitize_text_field( wp_unslash( $_GET['city'] ) );
		$page_title .= ' in ' . ucwords( strtolower( $city ) );
	} elseif ( ! empty( $_GET['zipCode'] ) ) {
		$page_title .= ' ' . sanitize_text_field( wp_unslash( $_GET['zipCode'] ) );
	}

	// phpcs:enable

	return $page_title . ' | ' . $site;
}
