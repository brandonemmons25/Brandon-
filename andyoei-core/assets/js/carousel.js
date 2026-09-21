/**
 * Carousel navigation.
 *
 * The track is a native scroll-snap container, so it already works by swipe
 * and keyboard. This only wires the arrows and their disabled states.
 */
( function () {
	'use strict';

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function Carousel( root ) {
		var track = root.querySelector( '.ao-track' );
		var prev = root.querySelector( '.ao-nav--prev' );
		var next = root.querySelector( '.ao-nav--next' );

		if ( ! track ) {
			return;
		}

		function step() {
			var card = track.firstElementChild;

			if ( ! card ) {
				return track.clientWidth;
			}

			var styles = window.getComputedStyle( track );
			var gap = parseFloat( styles.columnGap || styles.gap ) || 0;

			return card.getBoundingClientRect().width + gap;
		}

		function update() {
			// A sub-pixel tolerance keeps the end state from flickering.
			var max = track.scrollWidth - track.clientWidth - 2;

			if ( prev ) {
				prev.disabled = track.scrollLeft <= 2;
			}

			if ( next ) {
				next.disabled = track.scrollLeft >= max;
			}
		}

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				track.scrollBy( { left: -step(), behavior: 'smooth' } );
			} );
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				track.scrollBy( { left: step(), behavior: 'smooth' } );
			} );
		}

		track.addEventListener( 'scroll', update, { passive: true } );
		window.addEventListener( 'resize', update );
		update();
	}

	ready( function () {
		document.querySelectorAll( '[data-ao-carousel]' ).forEach( function ( root ) {
			new Carousel( root );
		} );
	} );
}() );
