/* global IMS, jQuery */
( function ( $ ) {
    'use strict';

    function showResult( $el, data, isSuccess ) {
        $el.removeClass( 'success error' ).show()
            .addClass( isSuccess ? 'success' : 'error' )
            .text( typeof data === 'object' ? JSON.stringify( data, null, 2 ) : String( data ) );
    }

    function ajaxAction( action, $btn, originalLabel, $resultEl, extraData ) {
        $resultEl = $resultEl || $( '#ims-ajax-result' );
        $btn.prop( 'disabled', true ).text( originalLabel + '…' );
        var data = $.extend( { action: action, _ajax_nonce: IMS.nonce }, extraData || {} );
        $.post( IMS.ajaxUrl, data, null, 'json' )
            .done( function ( r ) {
                showResult( $resultEl, r.data, r.success );
                if ( r.success && action === 'ims_rerun_setup' ) {
                    setTimeout( function () { location.reload(); }, 1500 );
                }
            } )
            .fail( function () {
                showResult( $resultEl, 'Request failed. Check browser console.', false );
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( originalLabel );
            } );
    }

    // Setup buttons
    $( '#ims-btn-rerun' ).on( 'click', function () {
        ajaxAction( 'ims_rerun_setup', $( this ), 'Re-run Full Setup' );
    } );
    $( '#ims-btn-markets' ).on( 'click', function () {
        ajaxAction( 'ims_refresh_markets', $( this ), 'Refresh + Re-scan' );
    } );

    // Diagnostics
    $( '#ims-btn-diag' ).on( 'click', function () {
        ajaxAction( 'ims_diagnostics', $( this ), 'Diagnostics' );
    } );

    // IDX domain override
    $( '#ims-btn-save-domain' ).on( 'click', function () {
        var $btn    = $( this );
        var domain  = $( '#ims-idx-domain' ).val().trim();
        var $result = $( '#ims-domain-result' );
        $btn.prop( 'disabled', true ).text( 'Saving…' );
        $.post( IMS.ajaxUrl, { action: 'ims_save_idx_domain', _ajax_nonce: IMS.nonce, idx_domain: domain }, null, 'json' )
            .done( function ( r ) {
                showResult( $result, r.data, r.success );
                if ( r.success ) setTimeout( function () { location.reload(); }, 1500 );
            } )
            .fail( function () { showResult( $result, 'Request failed.', false ); } )
            .always( function () { $btn.prop( 'disabled', false ).text( 'Save' ); } );
    } );

    // Migration buttons
    var $migResult = $( '#ims-migration-result' );

    function dryRunFlag() {
        return $( '#ims-dry-run' ).is( ':checked' ) ? '1' : '0';
    }

    $( '#ims-btn-migrate-menus' ).on( 'click', function () {
        ajaxAction( 'ims_migrate_menus', $( this ), 'Migrate Menus', $migResult, { dry_run: dryRunFlag() } );
    } );
    $( '#ims-btn-migrate-pages' ).on( 'click', function () {
        ajaxAction( 'ims_migrate_pages', $( this ), 'Migrate Pages', $migResult, { dry_run: dryRunFlag() } );
    } );
    $( '#ims-btn-migrate-posts' ).on( 'click', function () {
        ajaxAction( 'ims_migrate_posts', $( this ), 'Migrate Posts', $migResult, { dry_run: dryRunFlag() } );
    } );
    $( '#ims-btn-migrate-all' ).on( 'click', function () {
        ajaxAction( 'ims_migrate_all', $( this ), 'Full Migration', $migResult, { dry_run: dryRunFlag() } );
    } );
    $( '#ims-btn-verify' ).on( 'click', function () {
        ajaxAction( 'ims_verify_migration', $( this ), 'Verify (Check for Remaining IDX)', $migResult );
    } );

}( jQuery ) );
