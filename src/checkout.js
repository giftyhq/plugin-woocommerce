import './checkout.scss';
import blockUI from 'jquery-blockui';

( function ( $, document ) {
	class giftyGiftCards {
		// Constructor
		constructor() {
			document.addEventListener( 'DOMContentLoaded', () => {
				this.init();
			} );
		}

		// Initialize
		init() {
			// Bind (form) events
			this.bindEvents();
		}

		/**
		 * Bind events
		 * Note that we actually bind to the document. This is on purpose, as certain plugins and
		 * WooCommerce replace entire dom sections on certain events. This would cause our event
		 * listeners to be removed, and thus not work anymore.
		 */
		bindEvents() {
			// Bind to #gifty_cart_code field
			$( document ).on(
				'input',
				'#gifty_cart_code',
				this.handleGiftCardCodeInput.bind( this )
			);
			$( document ).on(
				'keypress',
				'#gifty_cart_code',
				this.handleGiftCardCodeInput.bind( this )
			);

			// Bind to #gifty_cart_submit button
			$( document ).on(
				'click',
				'#gifty_cart_submit',
				this.handleGiftCardSubmit.bind( this )
			);

			// Bind to .gifty-gift-card-remove button
			$( document ).on(
				'click',
				'.gifty-gift-card-remove',
				this.handleGiftCardRemove.bind( this )
			);
		}

		// Display a notice in the cart
		displayCartNotice( message, type ) {
			const messageType = type || 'success';

			// Get the notices container (fallback to .woocommerce-notices-wrapper)
			const noticesContainer =
				document.getElementById( 'gifty_gift_card_notices_wrapper' ) ||
				document.querySelector( '.woocommerce-notices-wrapper' );

			// Remove existing notices
			noticesContainer.innerHTML = '';

			// Return if message is empty
			if ( message === '' ) {
				return;
			}

			// Create a new notice element with close button
			const noticeElement = document.createElement( 'div' );
			noticeElement.classList.add( 'woocommerce-' + messageType );
			noticeElement.innerHTML += message;

			// Append the notice to the notices container
			noticesContainer.appendChild( noticeElement );

			// Scroll to the notices container
			noticesContainer.scrollIntoView();

			// Hide the notices container after 8 seconds
			setTimeout( () => {
				noticesContainer.innerHTML = '';
			}, 8000 );
		}

		handleGiftCardCodeInput( event ) {
			// Handle form submission
			if ( event.which === 13 ) {
				event.preventDefault(); // Prevent the default form submit action

				// Trigger the click event on the submit button
				$( '#gifty_cart_submit' ).click();
			}

			// Get input from #gifty_cart_code field
			const codeInput =
				document.getElementById( 'gifty_cart_code' ).value;

			// Disable submit button if input is empty or < 16 characters
			if ( codeInput.length < 16 ) {
				document.getElementById( 'gifty_cart_submit' ).disabled = true;

				return;
			}

			// Else, enable submit button
			document.getElementById( 'gifty_cart_submit' ).disabled = false;
		}

		// Handle gift card submit
		handleGiftCardSubmit( event ) {
			event.preventDefault();

			// Block the forms
			this.block( $( 'div.cart_totals' ) );
			this.block( $( 'div.woocommerce-checkout-review-order' ) );

			// Get input from #gifty_cart_code field
			const codeInput =
				document.getElementById( 'gifty_cart_code' ).value;

			// Filter out non-alphanumeric characters
			const codeInputFiltered = codeInput.replace( /[^a-zA-Z0-9]/g, '' );

			// Make API request to the AJAX handler to apply the gift card to the cart
			const form = new FormData();
			form.append( 'action', 'gifty_apply_gift_card' );
			form.append( 'security', wc_params.apply_gift_card_nonce );
			form.append( 'card_code', codeInputFiltered );

			const request = new XMLHttpRequest();
			request.open( 'POST', wc_params.ajax_url );
			request.responseType = 'json';
			request.send( form );

			// Handle request response
			request.onload = () => {
				if (
					request.status < 200 ||
					request.status >= 300 ||
					request.response?.success === false
				) {
					const errorMessage =
						request.response?.data || request.response;

					// Display error message
					this.displayCartNotice( errorMessage, 'error' );

					return;
				}

				// Empty the input
				document.getElementById( 'gifty_cart_code' ).value = '';

				// Refresh the totals on the cart page
				$( document.body ).trigger( 'wc_update_cart' );

				// Refresh the totals on the checkout page
				$( document.body ).trigger( 'update_checkout', {
					update_shipping_method: false,
				} );

				// Display success message
				this.displayCartNotice( request.response?.data, 'message' );
			};

			request.onloadend = () => {
				// Unblock the div.cart_totals form
				this.unblock( $( 'div.cart_totals' ) );
				this.unblock( $( 'div.woocommerce-checkout-review-order' ) );
			};
		}

		// Handle gift card remove
		handleGiftCardRemove( event ) {
			// Block the div.cart_totals form
			this.block( $( 'div.cart_totals' ) );
			this.block( $( 'div.woocommerce-checkout-review-order' ) );

			// Get gift card code from data-gift-card-code attribute
			const giftCardId = event.target.dataset.giftCardId;

			// Make API request to the AJAX handler to remove the gift card from the cart
			const form = new FormData();
			form.append( 'action', 'gifty_remove_gift_card' );
			form.append( 'security', wc_params.remove_gift_card_nonce );
			form.append( 'card_id', giftCardId );

			const request = new XMLHttpRequest();
			request.open( 'POST', wc_params.ajax_url );
			request.responseType = 'json';
			request.send( form );

			// Handle request response
			request.onload = () => {
				if (
					request.status < 200 ||
					request.status >= 300 ||
					request.response?.success === false
				) {
					const errorMessage =
						request.response?.data || request.response;

					// Display error message
					this.displayCartNotice( errorMessage, 'error' );

					return;
				}

				// Display success message
				this.displayCartNotice( request.response?.data, 'message' );

				// Refresh the totals on the cart page
				$( document.body ).trigger( 'wc_update_cart' );

				// Refresh the totals on the checkout page
				$( document.body ).trigger( 'update_checkout', {
					update_shipping_method: false,
				} );
			};

			request.onloadend = () => {
				// Unblock the div.cart_totals form
				this.unblock( $( 'div.cart_totals' ) );
				this.unblock( $( 'div.woocommerce-checkout-review-order' ) );
			};
		}

		/*
        The blocked functions are derived from the WooCommerce cart.js file to mimic the same behaviour.
         */

		// Check if a node is blocked
		is_blocked( $node ) {
			return (
				$node.is( '.processing' ) ||
				$node.parents( '.processing' ).length
			);
		}

		// Block a node
		block( $node ) {
			if ( ! this.is_blocked( $node ) ) {
				$node.addClass( 'processing' ).block( {
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6,
					},
				} );
			}
		}

		// Unblock a node
		unblock( $node ) {
			$node.removeClass( 'processing' ).unblock();
		}
	}

	// Initialize giftyGiftCards
	new giftyGiftCards();
} )( jQuery, document, blockUI );
