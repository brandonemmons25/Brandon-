/* global IMS, jQuery */
( function ( $ ) {
    'use strict';

    function showResult( $el, data, isSuccess ) {
        $el.removeClass( 'success error' ).show()
            .addClass( isSuccess ? 'success' : 'error' )
            .text( typeof data === 'object' ? JSON.stringify( data, null, 2 ) : String( data ) );
    }

    function ajaxAction( action, $btn, originalLabel ) {
        var $result = $( '#ims-ajax-result' );
        $btn.prop( 'disabled', true ).text( originalLabel + '…' );
        $.post( IMS.ajaxUrl, { action: action, _ajax_nonce: IMS.nonce }, null, 'json' )
            .done( function ( r ) {
                showResult( $result, r.data, r.success );
                if ( r.success ) {
                    setTimeout( function () { location.reload(); }, 1500 );
                }
            } )
            .fail( function () {
                showResult( $result, 'Request failed. Check browser console.', false );
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( originalLabel );
            } );
    }

    $( '#ims-btn-rerun' ).on( 'click', function () {
        ajaxAction( 'ims_rerun_setup', $( this ), 'Re-run Full Setup' );
    } );

    $( '#ims-btn-markets' ).on( 'click', function () {
        ajaxAction( 'ims_refresh_markets', $( this ), 'Refresh Markets Only' );
    } );

}( jQuery ) );
