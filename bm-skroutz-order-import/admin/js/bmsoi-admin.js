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

	/**
	 * Orders list: place the Skroutz icon right before the buyer name.
	 * Rows are marked server-side with the .bmsoi-skroutz class; the cell
	 * content is "#44 Name", so the icon is inserted after the "#44 " prefix.
	 */
	function markSkroutzRows() {
		$( 'tr.bmsoi-skroutz' ).each( function () {
			var $row = $( this );
			if ( $row.data( 'bmsoiMarked' ) ) {
				return;
			}
			$row.data( 'bmsoiMarked', true );

			var $target = $row.find( 'td.column-order_number a.order-view strong' ).first();
			if ( ! $target.length ) {
				$target = $row.find( 'td.column-order_number a.order-view' ).first();
			}
			if ( ! $target.length ) {
				$target = $row.find( 'td.column-order_number' ).first();
			}
			if ( ! $target.length ) {
				return;
			}

			var title = bmsoi.i18n.skroutzOrder;
			if ( $row.hasClass( 'bmsoi-express' ) ) {
				title += ' • ' + bmsoi.i18n.express;
			}
			if ( $row.hasClass( 'bmsoi-fbs' ) ) {
				title += ' • ' + bmsoi.i18n.fbs;
			}

			var $icon = $( '<img>', {
				src: bmsoi.iconUrl,
				alt: 'Skroutz',
				title: title,
				'class': 'bmsoi-inline-icon',
				width: 18,
				height: 18
			} );

			// First text node usually reads "#44 Name" — split it so the icon
			// lands exactly before the name.
			var textNode = $target.contents().filter( function () {
				return this.nodeType === 3 && $.trim( this.nodeValue ) !== '';
			} ).first()[ 0 ];

			if ( textNode ) {
				var match = textNode.nodeValue.match( /^(\s*#\S+\s+)([\s\S]+)$/ );
				if ( match ) {
					var nameNode = document.createTextNode( match[ 2 ] );
					textNode.nodeValue = match[ 1 ];
					$( textNode ).after( nameNode );
					$( nameNode ).before( $icon );
					return;
				}
			}
			$target.prepend( $icon );
		} );
	}

	$( function () {

		markSkroutzRows();

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
