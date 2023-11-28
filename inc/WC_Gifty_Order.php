<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

use Gifty\Client\Exceptions\ApiException;
use Gifty\Client\GiftyClient;
use WC_Abstract_Order;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WC_Gifty_Order {
    private GiftCardManager $gift_card_manager;
    private GiftyClient $client;

    public function __construct( GiftyClient $client ) {
        $this->client = $client;
        $this->gift_card_manager = new GiftCardManager();

        // Revalidate gift card balances while checkout is started to prevent errors later on
        add_action( 'woocommerce_checkout_process', [ $this, 'revalidate_gift_card_balances' ] );

        // Redeem the gift cards and store the gift card data to the order meta
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'redeem_and_store_gift_cards' ] );

        // Release redeem transactions when the order is cancelled or failed (for example using other PSP)
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'release_redeem_transactions' ] );
        add_action( 'woocommerce_order_status_failed', [ $this, 'release_redeem_transactions' ] );

        // Capture redeem transactions after the order is finalized
        add_action( 'woocommerce_order_status_completed', [ $this, 'capture_redeem_transactions' ] );

        // Include gift card totals when calculating order totals
        add_action( 'woocommerce_order_after_calculate_totals', [ $this, 'calculate_order_totals' ], 10, 2 );

        // Display gift cards in total tables on order views
        add_action( 'woocommerce_get_order_item_totals',
                    [ $this, 'display_gift_cards_in_order_totals' ],
                    10,
                    2 );

        // Display gift cards in total tables on admin order views
        add_action( 'woocommerce_admin_order_totals_after_tax', [ $this, 'display_total_in_admin_order_totals' ] );
    }

    /**
     * Revalidates the gift card balances in the session
     * We do this to show potential (balance) errors early on before the order is placed.
     * This is just an extra check for the users experience, the gift card will be validated again
     * before the (rest) payment starts.
     *
     * @return void|WP_Error
     */
    public function revalidate_gift_card_balances() {
        if ( $this->gift_card_manager->session_has_gift_cards() === false ) {
            return;
        }

        // Update calculations
        $this->gift_card_manager->update_amount_used_per_gift_card_for_order_total(
            (float) WC()->session->get( 'gifty_original_cart_total' )
        );

        $session_gift_cards = $this->gift_card_manager->get_gift_cards_from_session();

        foreach ( $session_gift_cards as $session_gift_card ) {
            try {
                $gift_card = $this->client->giftCards->get( $session_gift_card->get_code() );
            } catch ( ApiException $e ) {
                wc_add_notice( 'Gifty: ' . $e->getMessage(), 'error' );
                $this->send_ajax_failure_response();
                exit();
            }

            $gift_card_balance = (float) $gift_card->getBalance() / 100;

            if ( $gift_card_balance !== $session_gift_card->get_balance() ) {
                $session_gift_card->set_balance( $gift_card_balance );
                $this->gift_card_manager->upsert_gift_card_to_session( $session_gift_card );

                // Return the user to the checkout page with the updated gift card data
                wc_add_notice( __( 'The balance of one or more gift cards has changed, please review the gift cards before placing the order.',
                                   'gifty-woocommerce' ),
                               'error' );
                $this->send_ajax_failure_response();
            }
        }
    }

    /**
     * Store the session data containing the gift card data to the order meta and
     * redeem the used gift cards to make sure the used balance is reserved
     *
     * @param int $order_id
     *
     * @return void
     * @throws \Exception
     */
    public function redeem_and_store_gift_cards( int $order_id ): void {
        if ( $this->gift_card_manager->session_has_gift_cards() === false ) {
            return;
        }

        $order = wc_get_order( $order_id );

        /*
         * Update the order status if the current status is 'failed'
         * We do does so that we will receive the 'woocommerce_order_status_changed' hook if the (rest)payment fails
         * again, so that we can release the gift card redeem transaction.
         * It seems this currently is the best way to hook in to failed payments.
         */
        if ( $order->get_status() === 'failed' ) {
            $order->update_status( 'pending' );
        }

        // Save the gift card data on the order
        $this->gift_card_manager->save_session_gift_cards_to_order_meta( $order_id );

        // Redeem the gift cards
        $gift_cards = $this->gift_card_manager->get_gift_cards_from_session();
        $transaction_ids = [];

        try {
            foreach ( $gift_cards as $key => $gift_card ) {
                // Make a reservation on the gift card through the Gifty API
                $transaction = $this->client->giftCards->redeem( $gift_card->get_code(), [
                    'amount' => $gift_card->get_amount_used() * 100,
                    'currency' => 'EUR',
                    'capture' => false
                ] );

                // Store the transaction id for error handling
                $transaction_ids[] = $transaction->getId();

                // Save the transaction id to the gift card
                $gift_card->set_transaction_id_redeem( $transaction->getId() );
                $gift_cards[ $key ] = $gift_card;

                // Add a note to the order
                $this->save_order_notice(
                    $order,
                    sprintf(
                        __( 'Gift card %s redeemed for %s (%s)', 'gifty-woocommerce' ),
                        $gift_card->get_masked_code(),
                        wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                        $transaction->getId()
                    )
                );
            }
        } catch ( ApiException $e ) {
            // Add error message to the order
            $this->save_order_notice(
                $order,
                sprintf(
                    __( 'Error while redeeming gift cards. Error: %s', 'gifty-woocommerce' ),
                    $e->getMessage()
                )
            );

            // Release previous transactions
            foreach ( $transaction_ids as $transaction_id ) {
                try {
                    $transaction = $this->client->transactions->release( $transaction_id );

                    // Save the transaction id to the gift card
                    $gift_card->set_transaction_id_release( $transaction->getId() );
                    $gift_cards[ $gift_card->get_id() ] = $gift_card;

                    // Add a note to the order
                    $this->save_order_notice(
                        $order,
                        sprintf(
                            __( 'Gift card %s released for %s (%s)', 'gifty-woocommerce' ),
                            $gift_card->get_masked_code(),
                            wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                            $transaction->getId()
                        )
                    );
                } catch ( ApiException $e ) {
                    // Add a note to the order
                    $this->save_order_notice(
                        $order,
                        sprintf(
                            __( 'Gift card %s could not be released for %s (%s). Error: ', 'gifty-woocommerce' ),
                            $gift_card->get_masked_code(),
                            wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                            $transaction_id,
                            $e->getMessage()
                        )
                    );
                }
            }

            wc_add_notice( 'Gifty: ' . $e->getMessage(), 'error' );
            $this->send_ajax_failure_response();
            exit();
        }

        // Save the updated data that now contains the redeem transaction id
        $this->gift_card_manager->upsert_gift_cards_to_order_meta( $order_id, $gift_cards );
    }

    /**
     * Release the redeem transactions when the order fails
     *
     * @param int $order_id
     *
     * @return void
     * @throws \Exception
     */
    public function release_redeem_transactions( int $order_id ): void {
        $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $order_id );

        if ( $gift_cards === null ) {
            return;
        }

        $order = wc_get_order( $order_id );

        foreach ( $gift_cards as $key => $gift_card ) {
            // Check if it is possible to release the transaction still
            if ( $gift_card->get_transaction_id_capture() !== null ||
                 $gift_card->get_transaction_id_redeem() === null ||
                 $gift_card->get_transaction_id_release() !== null
            ) {
                continue;
            }

            try {
                $transaction = $this->client->transactions->release( $gift_card->get_transaction_id_redeem() );
                $gift_card->set_transaction_id_release( $transaction->getId() );
                $gift_card->set_amount_refunded( $gift_card->get_amount_used() );

                $gift_cards[ $key ] = $gift_card;

                // Add a note to the order
                $this->save_order_notice(
                    $order,
                    sprintf(
                        __( 'Gift card %s released for %s (%s)', 'gifty-woocommerce' ),
                        $gift_card->get_masked_code(),
                        wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                        $transaction->getId()
                    )
                );
            } catch ( ApiException $e ) {
                // Add a note to the order
                $this->save_order_notice(
                    $order,
                    sprintf(
                        __( 'Gift card %s could not be released for %s (%s). Error: ', 'gifty-woocommerce' ),
                        $gift_card->get_masked_code(),
                        wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                        $gift_card->get_transaction_id_redeem(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->gift_card_manager->upsert_gift_cards_to_order_meta( $order_id, $gift_cards );
    }

    public function capture_redeem_transactions( int $order_id ): void {
        $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $order_id );

        if ( $gift_cards === null ) {
            return;
        }

        $order = wc_get_order( $order_id );

        foreach ( $gift_cards as $key => $gift_card ) {
            if ( $gift_card->get_transaction_id_release() !== null ) {
                // Add a note to the order this gift card was released before
                $this->save_order_notice(
                    $order,
                    sprintf(
                        __( 'Gift card %s was already released for %s (%s). Payment not applied.',
                            'gifty-woocommerce' ),
                        $gift_card->get_masked_code(),
                        wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                        $gift_card->get_transaction_id_release()
                    )
                );
            }

            if (
                $gift_card->get_transaction_id_redeem() === null ||
                $gift_card->get_transaction_id_capture() !== null
            ) {
                continue;
            }

            try {
                $transaction = $this->client->transactions->capture( $gift_card->get_transaction_id_redeem() );
                $gift_card->set_transaction_id_capture( $transaction->getId() );

                $gift_cards[ $key ] = $gift_card;

                // Add a note to the order
                $this->save_order_notice(
                    $order,
                    sprintf(
                        __( 'Gift card %s captured for %s (%s)', 'gifty-woocommerce' ),
                        $gift_card->get_masked_code(),
                        wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                        $transaction->getId()
                    )
                );
            } catch ( ApiException $e ) {
                // Add a note to the order
                $this->save_order_notice(
                    $order,
                    sprintf(
                        __( 'Gift card %s could not be captured for %s (%s). Error: ', 'gifty-woocommerce' ),
                        $gift_card->get_masked_code(),
                        wc_price( $gift_card->get_amount_used(), [ 'currency' => $order->get_currency() ] ),
                        $gift_card->get_transaction_id_redeem(),
                        $e->getMessage()
                    )
                );
            }
        }

        $this->gift_card_manager->upsert_gift_cards_to_order_meta( $order_id, $gift_cards );
    }

    public function calculate_order_totals( bool $andTaxes, WC_Abstract_Order $order ): void {
        // Check if type is WC_Order or WC_Order_Refund
        if ( ! is_a( $order, 'WC_Order' ) &&
             ! is_a( $order, 'WC_Order_Refund' ) ) {
            return;
        }

        $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $order->get_id() );

        if ( ! $gift_cards ) {
            return;
        }

        $gift_cards_total = 0;
        foreach ( $gift_cards as $gift_card ) {
            $gift_cards_total += $gift_card->get_amount_used();
        }

        $order->set_total( max( 0, $order->get_total() - $gift_cards_total ) );
    }

    /**
     * Get totals for display on pages and in emails
     */
    public function display_gift_cards_in_order_totals(
        ?array $total_rows,
        WC_Abstract_Order $order
    ): ?array {
        if ( $total_rows === null ) {
            return null;
        }

        $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $order->get_id() );

        if ( ! $gift_cards ) {
            return $total_rows;
        }

        // Remove the order total row, so we can add gift cards before this line
        $total_row = $total_rows['order_total'] ?? [];

        if ( isset( $total_rows['order_total'] ) ) {
            unset( $total_rows['order_total'] );
        }

        foreach ( $gift_cards as $gift_card ) {
            $total_rows[] = [
                'label' => __( 'Gift Card', 'gifty-woocommerce' ) . ' ' . ' (' . $gift_card->get_masked_code() . ')',
                'value' => wp_kses_post( wc_price( $gift_card->get_amount_used() * - 1,
                                                   [ 'currency' => $order->get_currency() ] ) ),
            ];
        }

        // Reapply the total row
        $total_rows['order_total'] = $total_row;

        return $total_rows;
    }

    public function display_total_in_admin_order_totals( int $orderId ): void {
        $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $orderId );

        if ( ! $gift_cards ) {
            return;
        }

        $order = wc_get_order( $orderId );

        $gift_cards_total = 0;
        $gift_cards_refund_total = 0;

        foreach ( $gift_cards as $gift_card ) {
            $gift_cards_total += $gift_card->get_amount_used();
            $gift_cards_refund_total += $gift_card->get_amount_refunded();
        }

        $gift_card_payment_date = null;
        if ( $order->get_date_paid() ) {
            $gift_card_payment_date = $order->get_date_paid()->date_i18n( get_option( 'date_format' ) );
        }

        wc_get_template(
            'admin/order/gift-card-payment.php',
            [
                'gift_cards' => $gift_cards,
                'gift_cards_total' => $gift_cards_total,
                'gift_cards_refund_total' => $gift_cards_refund_total,
                'gift_card_payment_date' => $gift_card_payment_date,
                'order' => $order,
                'order_total' => $order->get_total() + $gift_cards_total,
            ],
            '',
            WC_Gifty()->get_plugin_root_path() . '/templates/'
        );
    }

    /**
     * If checkout failed during an AJAX call, send failure response.
     * From: class-wc-checkout.php
     */
    protected function send_ajax_failure_response(): void {
        if ( wp_doing_ajax() ) {
            // Only print notices if not reloading the checkout, otherwise they're lost in the page reload.
            if ( ! isset( WC()->session->reload_checkout ) ) {
                $messages = wc_print_notices( true );
            }

            $response = [
                'result' => 'failure',
                'messages' => isset( $messages ) ? $messages : '',
                'refresh' => isset( WC()->session->refresh_totals ),
                'reload' => isset( WC()->session->reload_checkout ),
            ];

            unset( WC()->session->refresh_totals, WC()->session->reload_checkout );

            wp_send_json( $response );
        }
    }

    private function save_order_notice( \WC_Order $order, string $notice ): void {
        // Log to order notes
        $order->add_order_note(
            sprintf(
                __( 'Gifty: %s', 'gifty-woocommerce' ),
                $notice
            )
        );
    }
}
