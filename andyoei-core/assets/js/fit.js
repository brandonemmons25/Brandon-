/**
 * Width and centring.
 *
 * The stylesheet widens the directory with negative margins, which assumes
 * the theme wraps it in something centred, unclipped, and willing to let a
 * child be wider than itself. Themes break all three, so this measures the
 * page and places the directory directly — and insists, because a plain
 * inline width still loses to an !important rule or a flex parent that
 * shrinks its items.
 */
( function () {
	'use strict';

	var FORCED = [ 'width', 'max-width', 'min-width', 'margin-left', 'margin-right', 'flex', 'box-sizing' ];

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function force( el, prop, value ) {
		el.style.setProperty( prop, value, 'important' );
	}

	function release( el ) {
		FORCED.forEach( function ( prop ) {
			el.style.removeProperty( prop );
		} );
	}

	/**
	 * An ancestor hiding horizontal overflow crops the directory no matter
	 * what width it is given, and theme wrappers reach for `overflow: hidden`
	 * routinely — to contain floats or clip rounded corners, not because the
	 * content must be cut off.
	 *
	 * Both axes are released together: freeing only overflow-x while
	 * overflow-y stays hidden makes the browser treat the vertical axis as
	 * auto, which adds a scrollbar.
	 */
	function unclip( root ) {
		for ( var node = root.parentElement; node && node !== document.body; node = node.parentElement ) {
			var overflowX = window.getComputedStyle( node ).overflowX;

			if ( overflowX === 'hidden' || overflowX === 'clip' ) {
				node.style.setProperty( 'overflow', 'visible', 'important' );
			}
		}
	}

	/**
	 * Last resort: a parent narrower than the directory, or one that shrinks
	 * its children, keeps winning however the directory is styled. Widen the
	 * ancestors that are standing in the way.
	 */
	function widenAncestors( root, want ) {
		for ( var node = root.parentElement; node && node !== document.body; node = node.parentElement ) {
			if ( node.getBoundingClientRect().width >= want - 1 ) {
				continue;
			}

			force( node, 'max-width', 'none' );
			force( node, 'width', 'auto' );
			force( node, 'flex', '0 0 auto' );
		}
	}

	function measure( root ) {
		// Neutralise everything this script and the stylesheet apply, so the
		// reading is of the page itself.
		release( root );
		force( root, 'width', 'auto' );
		force( root, 'margin-left', '0px' );
		force( root, 'margin-right', '0px' );

		return root.getBoundingClientRect();
	}

	function place( root, want, natural ) {
		var viewport = document.documentElement.clientWidth;

		force( root, 'box-sizing', 'border-box' );
		force( root, 'flex', '0 0 auto' );
		force( root, 'min-width', '0' );
		force( root, 'max-width', 'none' );
		force( root, 'width', Math.round( want ) + 'px' );
		force( root, 'margin-left', Math.round( ( viewport - want ) / 2 - natural.left ) + 'px' );
		force( root, 'margin-right', 'auto' );
	}

	function fit( root ) {
		var natural = measure( root );
		var declared = window.getComputedStyle( root ).getPropertyValue( '--ao-width' ).trim();
		var viewport = document.documentElement.clientWidth;
		var gutter = viewport < 700 ? 16 : 32;
		var want;

		if ( declared.slice( -1 ) === '%' ) {
			want = natural.width * ( parseFloat( declared ) / 100 );
		} else {
			want = parseFloat( declared );
		}

		if ( ! want || isNaN( want ) ) {
			release( root );
			return;
		}

		want = Math.min( want, viewport - gutter * 2 );

		// Already has the room.
		if ( want <= natural.width + 1 ) {
			release( root );
			return;
		}

		place( root, want, natural );

		// Verify, because a parent can still be holding it narrow.
		if ( Math.abs( root.getBoundingClientRect().width - want ) > 1 ) {
			widenAncestors( root, want );
			place( root, want, measure( root ) );
		}
	}

	function apply() {
		document.querySelectorAll( '.ao-directory' ).forEach( function ( root ) {
			unclip( root );
			fit( root );
		} );
	}

	ready( function () {
		apply();

		// Late webfonts and lazy images can shift the container under us.
		window.addEventListener( 'load', apply );

		var timer = null;

		window.addEventListener( 'resize', function () {
			clearTimeout( timer );
			timer = setTimeout( apply, 150 );
		} );
	} );
}() );
