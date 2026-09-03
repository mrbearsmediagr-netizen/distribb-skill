/* global bmsoi, jQuery */
( function ( $ ) {
	'use strict';

	function post( action, data, $button ) {
		var original = $button ? $button.text() : '';
		if ( $button ) {
			$button.prop( 'disabled', true ).text( bmsoi.i18n.working );
		}
		return $.post( bmsoi.ajaxUrl, $.extend( { action: action, nonce: bmsoi.nonce }, data ) )
			.always( function () {
				if ( $button ) {
					$button.prop( 'disabled', false ).text( original );
				}
			} );
	}

	function fail( response ) {
		var message = bmsoi.i18n.failed;
		if ( response && response.data && response.data.message ) {
			message = response.data.message;
		}
		window.alert( message );
	}

	$( function () {

		/* Settings page: copy webhook URL */
		$( '#bmsoi_copy_webhook' ).on( 'click', function () {
			var $input = $( '#bmsoi_webhook_url' );
			$input.trigger( 'select' );
			try {
				navigator.clipboard.writeText( $input.val() );
			} catch ( e ) {
				document.execCommand( 'copy' );
			}
			$( this ).text( bmsoi.i18n.copied );
		} );

		/* Settings page: fetch a single order by code */
		$( '#bmsoi_fetch_order' ).on( 'click', function () {
			var code = $.trim( $( '#bmsoi_fetch_code' ).val() );
			if ( ! code ) {
				return;
			}
			post( 'bmsoi_fetch_order', { order_code: code }, $( this ) ).done( function ( response ) {
				if ( response && response.success ) {
					if ( window.confirm( bmsoi.i18n.imported ) ) {
						window.open( response.data.order_url, '_blank' );
					}
				} else {
					fail( response );
				}
			} ).fail( function () {
				fail( null );
			} );
		} );

		/* Order metabox */
		var $box = $( '.bmsoi-mb' );
		if ( ! $box.length ) {
			return;
		}
		var orderCode = $box.data( 'order' );

		$( '#bmsoi_accept' ).on( 'click', function () {
			post( 'bmsoi_accept_order', {
				order_code: orderCode,
				pickup_location: $( '#bmsoi_pickup_location' ).val(),
				pickup_window: $( '#bmsoi_pickup_window' ).val(),
				parcels: $( '#bmsoi_parcels' ).val()
			}, $( this ) ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					fail( response );
				}
			} ).fail( function () {
				fail( null );
			} );
		} );

		$( '#bmsoi_reject_toggle' ).on( 'click', function () {
			$( '#bmsoi_reject_form' ).prop( 'hidden', ! $( '#bmsoi_reject_form' ).prop( 'hidden' ) );
		} );

		$( '#bmsoi_reject' ).on( 'click', function () {
			if ( ! window.confirm( bmsoi.i18n.confirmReject ) ) {
				return;
			}
			post( 'bmsoi_reject_order', {
				order_code: orderCode,
				reason_id: $( '#bmsoi_reject_reason' ).val(),
				other_reason: $( '#bmsoi_reject_other' ).val(),
				available_quantity: $( '#bmsoi_reject_qty' ).val() || 0
			}, $( this ) ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					fail( response );
				}
			} ).fail( function () {
				fail( null );
			} );
		} );

		$( '#bmsoi_sync_order' ).on( 'click', function () {
			post( 'bmsoi_fetch_order', { order_code: orderCode }, $( this ) ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					fail( response );
				}
			} ).fail( function () {
				fail( null );
			} );
		} );
	} );
} )( jQuery );
