( function ( $, document ) {
	class giftyAdminOrder {
		constructor() {
			this.refundType = null;

			document.addEventListener( 'DOMContentLoaded', () => {
				this.init();
			} );
		}

		// Initialize
		init() {
			// If .gifty-order-totals is available, render the payments
			if ( $( '.gifty-order-totals' ).length > 0 ) {
				this.renderPaymentsOnOrderPage();
			}

			// If we are on the order page, render refund options
			if (
				$( '.wc-order-refund-items' ).length > 0 &&
				$( '.wc-gifty-refund-available' ).data( 'amount' ) > 0
			) {
				this.renderRefundOptionsOnOrderPage();
			}
		}

		renderPaymentsOnOrderPage() {
			// Hide the total without gift card payment
			$( '.gifty-order-totals' ).next( 'tr' ).hide();
		}

		renderRefundOptionsOnOrderPage() {
			// Find <label for="refund_amount"> in table wc-order-totals
			const baseRow = $( '.wc-order-totals' )
				.find( 'label[for="refund_amount"]' )
				.parentsUntil( 'tr' )
				.parent();

			// Move the rows above the base row
			$( '.wc-gifty-refund-applied' ).insertBefore( baseRow );
			$( '.wc-gifty-refund-available' ).insertAfter(
				'.wc-gifty-refund-applied'
			);

			// Prepend the .wc-gifty-refund-button button to the .refund-actions div
			$( '.wc-gifty-refund-button' ).prependTo( '.refund-actions' );

			// Add on click handler to the refund button
			$( '#woocommerce-order-items' ).on(
				'click',
				'.wc-gifty-refund-button',
				this.handle_refund_event.bind( this )
			);

			// Add a trigger to hook into woocommerce_order_meta_box_do_refund_ajax_data
			// This is used to filter the data sent to the server
			$( '#woocommerce-order-items' ).on(
				'woocommerce_order_meta_box_do_refund_ajax_data',
				this.filter_refund_data.bind( this )
			);

			// Show the elements
			$( '.wc_gifty_refund_applied' ).show();
			$( '.wc-gifty-refund-available' ).show();
			$( '.wc-gifty-refund-button' ).show();
		}

		handle_refund_event( event ) {
			// If the refund type is already gifty, return
			if ( this.refundType !== null ) {
				return;
			}

			// Prevent the default action
			event.stopImmediatePropagation();

			// Set the refund type to be picked up in filter_refund_data()
			this.refundType = 'gifty';

			// Trigger this event again to trigger the default action
			$( event.target ).trigger( 'click' );
		}

		filter_refund_data( event, data ) {
			// If the refund type is not gifty, return the data
			if ( this.refundType !== 'gifty' ) {
				return data;
			}

			// Reset the refund type
			this.refundType = null;

			// Set the gifty_refund flag
			data.gifty_refund = true;

			return data;
		}
	}

	new giftyAdminOrder();
} )( jQuery, document );
