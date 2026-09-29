<?php
defined( 'ABSPATH' ) || exit;

/**
 * Destination URLs.
 *
 * A building or neighborhood can point at a page built elsewhere. Where one
 * is set, the cards link straight to it and the record's own auto-generated
 * URL redirects there — otherwise the site carries two pages for the same
 * building, and the thin one competes with the real one in search.
 */
class AO_Destinations {

	const KEY = 'ao_destination';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ) );
	}

	/**
	 * The URL a card should point at: the destination if one is set, the
	 * record's own permalink otherwise.
	 */
	public static function for_post( $post_id ) {
		$url = get_post_meta( $post_id, self::KEY, true );

		return $url ? $url : get_permalink( $post_id );
	}

	/**
	 * Send the record's own URL to its destination, so the two do not compete.
	 */
	public static function redirect() {
		$url = '';

		if ( is_singular( array( 'ao_building', 'ao_neighborhood' ) ) ) {
			$url = get_post_meta( get_queried_object_id(), self::KEY, true );
		}

		if ( ! $url ) {
			return;
		}

		// Permanent, so search engines drop the auto-generated URL. Guarded
		// against a destination that points back at this same page.
		if ( untrailingslashit( $url ) === untrailingslashit( self::current_url() ) ) {
			return;
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}

	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $path;
	}
}
