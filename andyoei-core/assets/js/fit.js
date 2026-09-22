/**
 * Width and centring.
 *
 * The CSS widens the directory with negative margins, which assumes the
 * theme wraps it in something centred that does not clip. Themes break both
 * assumptions, so this measures the page and places the directory directly:
 * exactly --ao-width, centred on the viewport, wherever it happens to sit.
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

	/**
	 * An ancestor hiding horizontal overflow crops the directory no matter
	 * what width it is given, and theme wrappers reach for `overflow: hidden`
	 * routinely — to contain floats or clip rounded corners, not because the
	 * content must be cut off. So lift it on the ancestors between the
	 * directory and the body.
	 *
	 * Both axes are released together: setting only overflow-x back to
	 * visible while overflow-y stays hidden makes the browser treat the
	 * vertical axis as auto, which adds a scrollbar.
	 */
	function unclip( root ) {
		for ( var node = root.parentElement; node && node !== document.body; node = node.parentElement ) {
			var overflowX = window.getComputedStyle( node ).overflowX;

			if ( overflowX === 'hidden' || overflowX === 'clip' ) {
				node.style.overflow = 'visible';
			}
		}
	}

	function fit( root ) {
		// Zero the stylesheet's own widening before measuring — clearing the
		// inline styles instead would let it reapply and compound.
		root.style.width = 'auto';
		root.style.marginLeft = '0px';
		root.style.marginRight = '0px';

		var declared = window.getComputedStyle( root ).getPropertyValue( '--ao-width' ).trim();
		var viewport = document.documentElement.clientWidth;
		var gutter = viewport < 700 ? 16 : 32;

		// Where the directory sits naturally, which already accounts for the
		// container's padding and borders — measuring the parent's box does
		// not, and leaves it off-centre by that padding.
		var natural = root.getBoundingClientRect();
		var want;

		if ( declared.slice( -1 ) === '%' ) {
			want = natural.width * ( parseFloat( declared ) / 100 );
		} else {
			want = parseFloat( declared );
		}

		if ( ! want || isNaN( want ) ) {
			return;
		}

		want = Math.min( want, viewport - gutter * 2 );

		// Nothing to do when it already has the room.
		if ( want <= natural.width + 1 ) {
			return;
		}

		root.style.width = Math.round( want ) + 'px';
		root.style.marginLeft = Math.round( ( viewport - want ) / 2 - natural.left ) + 'px';
		root.style.marginRight = 'auto';
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
