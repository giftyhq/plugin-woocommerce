<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

use Gifty\Client\Exceptions\ApiException;
use Gifty\Client\GiftyClient;
use Gifty\Client\Resources\GiftCard;
use WC_Cart;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Gifty_Cart
 * Responsible for showing the gift card form in the cart and
 * calculating totals.
 */
class WC_Gifty_Cart {

    private GiftyClient $client;
    private GiftCardManager $gift_card_manager;

    public function __construct( GiftyClient $client, bool $form_visible_in_cart, bool $form_visible_in_checkout ) {
        $this->client = $client;
        $this->gift_card_manager = new GiftCardManager();

        // Calculate totals including gift card calculations
        add_action( 'woocommerce_after_calculate_totals', [ $this, 'calculate_gift_card_on_totals' ], 999 );

        // Display gift cards in total tables (cart and checkout)
        add_action( 'woocommerce_cart_totals_before_order_total', [ $this, 'render_applied_gift_cards' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ $this, 'render_applied_gift_cards' ] );

        // Include the gift card form in the cart
        if ( ! ! apply_filters( 'gifty_wc_gc_form_visible_in_cart', $form_visible_in_cart === true ) ) {
            add_action( 'woocommerce_proceed_to_checkout', [ $this, 'render_gift_card_form' ] );
        }

        // Include the gift card form on the checkout page
        if ( ! ! apply_filters( 'gifty_wc_gc_form_visible_in_checkout', $form_visible_in_checkout === true ) ) {
            add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_gift_card_form' ] );
        }

        // Register Ajax callbacks to apply gift card to session
        add_action( 'wp_ajax_gifty_apply_gift_card', [ $this, 'apply_gift_card' ] );
        add_action( 'wp_ajax_nopriv_gifty_apply_gift_card', [ $this, 'apply_gift_card' ] );

        // Register Ajax callbacks to remove gift card from session
        add_action( 'wp_ajax_gifty_remove_gift_card', [ $this, 'remove_gift_card' ] );
        add_action( 'wp_ajax_nopriv_gifty_remove_gift_card', [ $this, 'remove_gift_card' ] );

        // Clean up gift card session data when order is finalized or cart is emptied
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'destroy_cart_session' ] );
        add_action( 'woocommerce_cart_emptied', [ $this, 'destroy_cart_session' ] );
    }

    public function calculate_gift_card_on_totals( WC_Cart $cart ): void {
        // Retrieve applied gift cards from the session using the gift card manager
        $applied_gift_cards = $this->gift_card_manager->get_gift_cards_from_session();

        // Return if there are no gift cards applied
        if ( empty( $applied_gift_cards ) ) {
            return;
        }

        // Calculate the total discount from the gift cards
        $total_discount = 0;

        foreach ( $applied_gift_cards as $gift_card ) {
            $total_discount += $gift_card->get_balance();
        }

        // Retrieve the original total before any gift card discounts
        $original_total = (float) $cart->get_total( 'edit' );

        // Calculate the new cart total after applying the discount
        $cart_total = max( $original_total - $total_discount, 0 );

        // Set the new cart total, making sure it doesn't go below zero
        $cart->set_total( $cart_total );

        // Store the original total in the session to revert to it when the order is finalized
        WC()->session->set( 'gifty_original_cart_total', $original_total );
    }

    public function render_applied_gift_cards(): void {
        // Retrieve applied gift cards from the session using the gift card manager
        $applied_gift_cards = $this->gift_card_manager->get_gift_cards_from_session();

        // Return if there are no gift cards applied
        if ( empty( $applied_gift_cards ) ) {
            return;
        }

        // Update calculations
        $cart_total_before_gift_cards = WC()->session->get( 'gifty_original_cart_total' );

        if ( $cart_total_before_gift_cards === null ) {
            throw new \Exception( 'Unable to retrieve original cart total from session' );
        }

        $this->gift_card_manager->update_amount_used_per_gift_card_for_order_total( (float) $cart_total_before_gift_cards );

        // Update $appliedGiftCards as data might have changed
        $applied_gift_cards = $this->gift_card_manager->get_gift_cards_from_session();

        wc_get_template(
            'cart/gift-card-totals.php',
            [
                'total_before_gift_cards' => $cart_total_before_gift_cards,
                'gift_cards' => $applied_gift_cards,
            ],
            '',
            WC_Gifty()->get_plugin_root_path() . '/templates/'
        );
    }

    public function render_gift_card_form(): void {
        wc_get_template(
            'cart/gift-card-form.php',
            [],
            '',
            WC_Gifty()->get_plugin_root_path() . '/templates/'
        );
    }

    public function apply_gift_card(): void {
        // Validate ajax request
        if ( ! check_ajax_referer( 'gifty_apply_gift_card', 'security', false ) ) {
            wp_send_json_error( __( 'Invalid security token sent', 'gifty-woocommerce' ) );
        }

        // Check if there is a WC session
        if ( ! WC()->session ) {
            wp_send_json_error( __( 'Unable to apply gift card, session not found', 'gifty-woocommerce' ) );
            exit();
        }

        // Normalize the gift card code
        $gift_card_code = GiftCard::cleanCode( $_POST['card_code'] );

        // Validate if the gift card code is not empty
        if ( empty( $gift_card_code ) ) {
            wp_send_json_error( __( 'Please enter a valid gift card code', 'gifty-woocommerce' ) );
            exit();
        }

        // Retrieve the gift card
        $gift_card = $this->get_gift_card( $gift_card_code );

        // Check if the gift card exists
        if ( $gift_card instanceof WP_Error ) {
            $error_message = $gift_card->get_error_message();

            if ( $gift_card->get_error_code() === 404 ) {
                $error_message = __( 'This gift card does not exist', 'gifty-woocommerce' );
            }

            wp_send_json_error( $error_message, $gift_card->get_error_code() );
            exit();
        }

        // Check if this gift card code has already been applied
        if ( $this->gift_card_manager->session_has_gift_card( $gift_card->getId() ) ) {
            wp_send_json_error( __( 'This gift card has already been applied', 'gifty-woocommerce' ) );

            return;
        }

        // Check if the gift card is valid
        if ( ! $gift_card->isRedeemable() ) {
            wp_send_json_error( __( 'This gift card has no available balance', 'gifty-woocommerce' ) );

            return;
        }

        // Add the new gift card to the session
        $this->gift_card_manager->upsert_gift_card_to_session(
            new SessionGiftCard(
                $gift_card->getId(),
                $gift_card_code,
                (float) $gift_card->getBalance() / 100,
                0
            )
        );

        // Return response
        wp_send_json_success( __( 'Gift card applied successfully', 'gifty-woocommerce' ) );
        exit();
    }

    public function remove_gift_card(): void {
        // Validate ajax request
        if ( ! check_ajax_referer( 'gifty_remove_gift_card', 'security', false ) ) {
            wp_send_json_error( __( 'Invalid security token sent', 'gifty-woocommerce' ) );
        }

        // Check if there is a WC session
        if ( ! WC()->session ) {
            wp_send_json_error( __( 'Unable to remove gift card, session not found', 'gifty-woocommerce' ) );
            exit();
        }

        // Normalize the gift card code
        $gift_card_id = $_POST['card_id'];

        // Remove the gift card from the session
        $this->gift_card_manager->remove_gift_card_from_session( $gift_card_id );

        // Return response
        wp_send_json_success( __( 'Gift card removed successfully', 'gifty-woocommerce' ) );
    }

    public function destroy_cart_session(): void {
        $this->gift_card_manager->destroy_session();
        WC()->session->__unset( 'gifty_original_cart_total' );
    }

    private function get_gift_card( string $code ): WP_Error|GiftCard {
        try {
            $gift_card = $this->client
                ->giftCards
                ->get( $code );
        } catch ( ApiException $e ) {
            return new WP_Error( $e->getCode(), $e->getMessage() );
        }

        return $gift_card;
    }
}
