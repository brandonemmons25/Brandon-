/**
 * Width and centring.
 *
 * The stylesheet widens the directory with negative margins, which assumes
 * the theme wraps it in something centred and willing to let a child be
 * wider than itself. Themes break both, so this measures the page and places
 * the directory directly — and insists, because a plain inline width still
 * loses to an !important rule or a flex parent that shrinks its items.
 *
 * It writes to the .ao-directory element and nothing else. Anything it
 * cannot achieve from there it gives up on: a plugin that reaches up the
 * tree to restyle its host breaks that host in ways nobody can trace back.
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

		// A parent can still hold it narrower than asked. Earlier versions
		// forced the ancestors wider and lifted their overflow, which reached
		// outside this plugin's own markup and broke theme behaviour that
		// depends on those ancestors — scroll animations among it. Settle for
		// the width the page allows instead.
		if ( Math.abs( root.getBoundingClientRect().width - want ) > 1 ) {
			release( root );
		}
	}

	function apply() {
		document.querySelectorAll( '.ao-directory' ).forEach( fit );
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
