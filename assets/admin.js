/* CX SMS admin: character counter, message switches, reveal secrets, tick all, refresh while sending. */
( function ( $ ) {
	'use strict';

	// "92 characters · about 1 SMS": English fits 160 per SMS (153 when split), Bangla 70 (67).
	function length( text ) {
		var count = Array.from( text ).length;
		var unicode = /[^\x20-\x7E\n\r]/.test( text );
		var single = unicode ? 70 : 160;
		var multi = unicode ? 67 : 153;
		var parts = count <= single ? 1 : Math.ceil( count / multi );

		return count + ' characters · about ' + parts + ' SMS' + ( unicode ? ' (Bangla/Unicode)' : '' ) + ', before the details are filled in.';
	}

	$( document ).on( 'input', 'textarea.cxsms-count', function () {
		$( this ).nextAll( '.cxsms-counter' ).first().text( length( this.value ) );
	} );

	// A message's text can be edited only while it's switched on.
	$( document ).on( 'change', '.cxsms-toggle', function () {
		$( this ).closest( 'tr' ).find( 'textarea' ).prop( 'readonly', ! this.checked );
	} );

	$( document ).on( 'click', '.cxsms-reveal', function () {
		var input = document.getElementById( $( this ).data( 'target' ) );
		var show = input.type === 'password';

		input.type = show ? 'text' : 'password';
		$( this ).text( show ? 'Hide' : 'Show' );
	} );

	$( document ).on( 'change', '.cxsms-check-all', function () {
		$( this ).closest( 'table' ).find( 'tbody input[name="ticked[]"]' ).prop( 'checked', this.checked );
	} );

	// Order page: post the typed SMS on its own, not with the order form around it.
	$( document ).on( 'click', '.cxsms-send-order-sms', function () {
		var button = $( this );
		var message = button.closest( '.cxsms-order-box' ).find( 'textarea[name="cxsms_message"]' ).val() || '';

		if ( ! message.trim() ) {
			window.alert( 'Write a message first.' );
			return;
		}

		var form = $( '<form method="post"></form>' ).attr( 'action', button.data( 'url' ) );
		var fields = {
			action: 'cxsms_order_sms',
			cxsms_order_id: button.data( 'order' ),
			cxsms_nonce: button.data( 'nonce' ),
			cxsms_message: message
		};

		$.each( fields, function ( name, value ) {
			form.append( $( '<input type="hidden">' ).attr( 'name', name ).val( value ) );
		} );

		button.prop( 'disabled', true );
		// Leaving the order page on purpose: skip WooCommerce's "unsaved changes" prompt.
		$( window ).off( 'beforeunload' );
		form.appendTo( document.body ).trigger( 'submit' );
	} );

	// Settings page: the SMS balance at the top, read in the background so the page never waits on CloudXino.
	function loadBalance( card, refresh ) {
		var button = card.find( '.cxsms-balance-refresh' );

		button.prop( 'disabled', true );
		card.removeClass( 'cxsms-balance-error' );
		card.find( '.cxsms-balance-status' ).text( 'Checking CloudXino…' );

		$.post( card.data( 'url' ), {
			action: 'cxsms_balance',
			_ajax_nonce: card.data( 'nonce' ),
			refresh: refresh ? 1 : 0
		} ).done( function ( response ) {
			if ( response && response.success ) {
				card.find( '.cxsms-balance-value' ).text( response.data.balance );
				card.find( '.cxsms-balance-status' ).text( response.data.status );
			} else {
				card.addClass( 'cxsms-balance-error' );
				card.find( '.cxsms-balance-value' ).text( '—' );
				card.find( '.cxsms-balance-status' ).text( response && response.data ? response.data.message : 'Couldn\'t read the balance.' );
			}
		} ).fail( function () {
			card.addClass( 'cxsms-balance-error' );
			card.find( '.cxsms-balance-value' ).text( '—' );
			card.find( '.cxsms-balance-status' ).text( 'Couldn\'t read the balance. Reload the page to try again.' );
		} ).always( function () {
			button.prop( 'disabled', false );
		} );
	}

	$( '.cxsms-balance' ).each( function () {
		if ( String( $( this ).data( 'load' ) ) === '1' ) {
			loadBalance( $( this ), false );
		}
	} );

	$( document ).on( 'click', '.cxsms-balance-refresh', function () {
		loadBalance( $( this ).closest( '.cxsms-balance' ), true );
	} );

	// Keep a sending campaign's counts current.
	if ( $( '.cxsms-refresh' ).length ) {
		setTimeout( function () {
			window.location.reload();
		}, 15000 );
	}
} )( jQuery );
