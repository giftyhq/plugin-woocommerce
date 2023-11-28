<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Admin;

use Exception;
use Gifty\Client\GiftyClient;
use Gifty\WooCommerce\GiftCardManager;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class WC_Gifty_Refunds {
    private GiftCardManager $gift_card_manager;
    private GiftyClient $client;

    public function __construct( GiftyClient $client ) {
        $this->client = $client;
        $this->gift_card_manager = new GiftCardManager();

        // Render refund lines and button in order totals
        add_action( 'woocommerce_admin_order_totals_after_total', [ $this, 'display_gift_cards_in_refund_overview' ] );

        // Hook into wp_ajax_woocommerce_refund_line_items ajax request to handle refund
        add_action( 'wp_ajax_woocommerce_refund_line_items', [ $this, 'handle_ajax_refund' ], 1 );
    }

    public function display_gift_cards_in_refund_overview( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Get gift card totals
        $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $order_id );

        if ( ! $gift_cards ) {
            return;
        }

        $available_to_refund = 0;
        $already_refunded = 0;

        foreach ( $gift_cards as $gift_card ) {
            $available_to_refund += $gift_card->get_amount_used();
            $already_refunded += $gift_card->get_amount_refunded();
        }

        $available_to_refund -= $already_refunded;

        // Return gift-card-refund-totals template
        wc_get_template(
            'admin/order/gift-card-refund-totals.php',
            [
                'order' => $order,
                'already_refunded' => $already_refunded,
                'available_to_refund' => $available_to_refund,
            ],
            'gifty-woocommerce',
            WC_Gifty()->get_plugin_root_path() . '/templates/'
        );
    }

    /**
     * Based on \WC_AJAX::refund_line_items()
     * @return void
     */
    public function handle_ajax_refund(): void {
        $is_gifty_payment = isset( $_POST['gifty_refund'] ) && $_POST['gifty_refund'] === 'true';

        if ( $is_gifty_payment !== true ) {
            return;
        }

        ob_start();

        check_ajax_referer( 'order-item', 'security' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( - 1 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $refund_amount = (float) ( isset( $_POST['refund_amount'] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['refund_amount'] ) ),
                                                                                         wc_get_price_decimals() ) : 0 );
        $refund_reason = isset( $_POST['refund_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['refund_reason'] ) ) : '';
        $line_item_qtys = isset( $_POST['line_item_qtys'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['line_item_qtys'] ) ),
                                                                           true ) : [];
        $line_item_totals = isset( $_POST['line_item_totals'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['line_item_totals'] ) ),
                                                                               true ) : [];
        $line_item_tax_totals = isset( $_POST['line_item_tax_totals'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['line_item_tax_totals'] ) ),
                                                                                       true ) : [];
        $restock_refunded_items = isset( $_POST['restock_refunded_items'] ) && 'true' === $_POST['restock_refunded_items'];
        $response = [];

        try {
            $order = wc_get_order( $order_id );
            $gift_cards = $this->gift_card_manager->get_gift_cards_from_order( $order_id );
            $max_refund = 0;
            $already_refunded = 0;

            foreach ( $gift_cards as $gift_card ) {
                $max_refund += $gift_card->get_amount_used();
                $already_refunded += $gift_card->get_amount_refunded();

                // Validate that the transaction is not captured yet
                if ( $gift_card->get_transaction_id_capture() !== null ) {
                    throw new Exception( sprintf(
                                             __( 'Gift card %s has already been captured, so it cannot be refunded. Process this refund manually.',
                                                 'gifty-woocommerce' ),
                                             $gift_card->get_masked_code()
                                         ) );
                }
            }

            $max_refund -= $already_refunded;
            $max_refund = (float) wc_format_decimal( $max_refund, wc_get_price_decimals() );

            // It is only possible to refund the full gift card payment at once
            if ( $refund_amount !== $max_refund ) {
                throw new Exception( sprintf(
                                         __( 'It is only supported to refund the full gift card payment at once. The refund should be %s.',
                                             'gifty-woocommerce' ),
                                         $max_refund
                                     ) );
            }

            // Validate remotely that the transaction is not captured yet
            foreach ( $gift_cards as $gift_card ) {
                $transaction = $this->client->transactions->get( $gift_card->get_transaction_id_redeem() );

                if ( $transaction->isCapturable() === false ) {
                    throw new Exception( sprintf(
                                             __( 'Gift card %s has already been captured, so it cannot be refunded.',
                                                 'gifty-woocommerce' ),
                                             $gift_card->get_masked_code()
                                         ) );
                }
            }

            if ( $max_refund < $refund_amount || 0 > $refund_amount ) {
                throw new Exception( __( 'Invalid refund amount', 'woocommerce' ) );
            }

            // Prepare line items which we are refunding.
            $line_items = [];
            $item_ids = array_unique( array_merge( array_keys( $line_item_qtys ), array_keys( $line_item_totals ) ) );

            foreach ( $item_ids as $item_id ) {
                $line_items[ $item_id ] = [
                    'qty' => 0,
                    'refund_total' => 0,
                    'refund_tax' => [],
                ];
            }
            foreach ( $line_item_qtys as $item_id => $qty ) {
                $line_items[ $item_id ]['qty'] = max( $qty, 0 );
            }
            foreach ( $line_item_totals as $item_id => $total ) {
                $line_items[ $item_id ]['refund_total'] = wc_format_decimal( $total );
            }
            foreach ( $line_item_tax_totals as $item_id => $tax_totals ) {
                $line_items[ $item_id ]['refund_tax'] = array_filter( array_map( 'wc_format_decimal', $tax_totals ) );
            }

            $refund_description = [];
            $amount_refunded = 0;
            $amount_to_refund = $refund_amount;

            // Create the refund object per gift card
            foreach ( $gift_cards as $key => $gift_card ) {
                if ( $amount_to_refund <= 0 ) {
                    continue;
                }

                $gc_refund_amount = min( $amount_to_refund, $gift_card->get_amount_used() );
                $amount_to_refund -= $gc_refund_amount;
                $amount_refunded += $gc_refund_amount;
                $refund_description[] = sprintf(
                    __( 'Refunded %s to gift card %s.', 'gifty-woocommerce' ),
                    $gc_refund_amount,
                    $gift_card->get_masked_code()
                );

                // Release the gift card reservation
                $release_transaction = $this->client->transactions->release( $gift_card->get_transaction_id_redeem() );

                // Update local gift card data
                $gift_card->set_transaction_id_release( $release_transaction->getId() );
                $gift_card->set_amount_refunded( $gc_refund_amount );
                $gift_cards[ $key ] = $gift_card;
            }

            // Update gift card data in order meta
            $this->gift_card_manager->upsert_gift_cards_to_order_meta( $order_id, $gift_cards );

            /*
             * Update the order total to include the gift card payment
             * This prevents calculation issues for the allowed refund amount and the order total
             */
            $order_total = $order->get_total();
            $order->set_total( $order_total + $amount_refunded );
            $order->save();

            // Persist the WC refund
            try {
                $refund = wc_create_refund(
                    [
                        'amount' => $amount_refunded,
                        'reason' => $refund_reason . ' ' . implode( ' ', $refund_description ),
                        'order_id' => $order_id,
                        'line_items' => $line_items,
                        'refund_payment' => false,
                        'restock_items' => $restock_refunded_items,
                    ]
                );
            } catch ( Exception $e ) {
                // Reset the order total to the original value
                $order->set_total( $order_total );
                $order->save();

                throw $e;
            }

            // Reset the order total to the original value
            $order->set_total( $order_total );
            $order->save();

            if ( is_wp_error( $refund ) ) {
                throw new Exception( $refund->get_error_message() );
            }

            // Add the gift card refund to the refunded (negative) order total
            $refund->set_total( $refund->get_total() + $amount_refunded );
            $refund->save();

            $this->gift_card_manager->upsert_gift_cards_to_order_meta( $refund->get_id(), $gift_cards );

            // Log refund info
            $this->save_order_notice( $order, implode( ' ', $refund_description ) );

            if ( did_action( 'woocommerce_order_fully_refunded' ) ) {
                $response['status'] = 'fully_refunded';
            }
        } catch ( Exception $e ) {
            wp_send_json_error( [ 'error' => $e->getMessage() ] );
        }

        // wp_send_json_success must be outside the try block not to break phpunit tests.
        wp_send_json_success( $response );
    }

    private function save_order_notice( WC_Order $order, string $notice ): void {
        // Log to order notes
        $order->add_order_note(
            sprintf(
                __( 'Gifty: %s', 'gifty-woocommerce' ),
                $notice
            )
        );
    }
}
