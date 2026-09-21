/**
 * Media picker for the neighborhood card image.
 */
jQuery( function ( $ ) {
	'use strict';

	var frame;

	$( document ).on( 'click', '.ao-media-select', function ( e ) {
		e.preventDefault();

		var picker = $( this ).closest( '.ao-media-picker' );

		frame = wp.media( {
			title: 'Select Card Image',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var image = frame.state().get( 'selection' ).first().toJSON();
			var src = image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url;

			picker.find( '.ao-media-id' ).val( image.id );
			picker.find( '.ao-media-preview' ).attr( 'src', src ).show();
			picker.find( '.ao-media-remove' ).show();
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.ao-media-remove', function ( e ) {
		e.preventDefault();

		var picker = $( this ).closest( '.ao-media-picker' );

		picker.find( '.ao-media-id' ).val( '' );
		picker.find( '.ao-media-preview' ).hide();
		$( this ).hide();
	} );

	// The add-term form clears itself over AJAX; clear the preview with it.
	$( document ).on( 'term-added', function () {
		$( '.ao-media-picker' ).each( function () {
			$( this ).find( '.ao-media-id' ).val( '' );
			$( this ).find( '.ao-media-preview' ).hide();
			$( this ).find( '.ao-media-remove' ).hide();
		} );
	} );
} );
