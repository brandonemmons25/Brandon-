<?php
/**
 * Plugin Name: Powder Motion Fix
 * Description: Reveals scroll animations the Powder theme's own observer misses, so no section stays invisible. Keeps every animation.
 * Version:     1.0.0
 * Author:      imFORZA
 */

defined( 'ABSPATH' ) || exit;

/**
 * The theme hides every animated element up front:
 *
 *   .js [data-motion="fadeInUp"]:not(.motion-fadeInUp) { opacity: 0 }
 *
 * and its script adds the matching class when the element scrolls into view.
 * An element taller than the viewport never satisfies that check, so it keeps
 * opacity 0 forever — and because such an element is usually a wrapper, every
 * section inside it is hidden too, however the children are marked.
 *
 * This watches the same elements with a threshold of zero: any part of the
 * element on screen is enough. It adds the class the theme's own CSS is
 * waiting for, so the fade still plays — nothing is skipped or disabled.
 */
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="powder-motion-fix">
	( function () {
		'use strict';

		function reveal( el ) {
			var name = el.getAttribute( 'data-motion' );

			if ( name ) {
				el.classList.add( 'motion-' + name );
			}
		}

		function watch() {
			var targets = document.querySelectorAll( '[data-motion]' );

			if ( ! targets.length ) {
				return;
			}

			// No IntersectionObserver: show everything rather than hide it.
			if ( ! ( 'IntersectionObserver' in window ) ) {
				targets.forEach( reveal );
				return;
			}

			var observer = new IntersectionObserver( function ( entries, self ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						reveal( entry.target );
						self.unobserve( entry.target );
					}
				} );
			}, {
				// Any sliver on screen counts, which is the part the theme
				// gets wrong on a full-height wrapper.
				threshold: 0,
				rootMargin: '0px 0px -40px 0px',
			} );

			targets.forEach( function ( el ) {
				var name = el.getAttribute( 'data-motion' );

				// Already revealed by the theme — leave it alone.
				if ( name && el.classList.contains( 'motion-' + name ) ) {
					return;
				}

				observer.observe( el );
			} );
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', watch );
		} else {
			watch();
		}
	}() );
	</script>
	<?php
}, 99 );
