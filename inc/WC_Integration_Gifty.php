<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

use Exception;
use Gifty\Client\Exceptions\ApiException;
use Gifty\Client\GiftyClient;
use WC_Admin_Settings;
use WC_Integration;
use WC_Order;
use WC_Order_Item_Coupon;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

final class WC_Integration_Gifty extends WC_Integration {
    /**
     * @var GiftyClient
     */
    private $client;

    public function __construct() {
        $this->id = 'gifty-woocommerce';
        $this->method_title = __( 'Gifty', 'gifty-woocommerce' );
        $this->method_description = __( 'Accept Gifty gift cards in your WooCommerce shop.', 'gifty-woocommerce' );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Initialize the Gifty API
        $this->client = new GiftyClient( $this->get_api_key() );

        // Actions
        add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );

        // Retrieve gift card
        add_action( 'woocommerce_get_shop_coupon_data', [ $this, 'retrieve_gift_card' ], 10, 2 );

        // Redeem gift card
        add_action( 'woocommerce_checkout_create_order', [ $this, 'redeem_gift_card' ], 10, 1 );

        // Redeem gift card from the admin order page
        add_action( 'woocommerce_before_order_object_save', [ $this, 'redeem_gift_card_admin' ], 10, 1 );

        // Capture transaction
        add_action( 'woocommerce_pre_payment_complete', [ $this, 'capture_gift_card' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'capture_gift_card' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'capture_gift_card' ], 10, 1 );

        // Release transaction
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'release_gift_card' ], 10, 1 );
        add_action( 'woocommerce_order_status_failed', [ $this, 'release_gift_card' ], 10, 1 );
    }

    /**
     * Register form fields for the WooCommerce settings page
     */
    public function init_form_fields() {
        $this->form_fields = [
            'gifty_api_key' => [
                'title' => __( 'API Key', 'gifty-woocommerce' ),
                'type' => 'text',
                'description' => __(
                    'Enter your API Key. You can manage API-keys in your Gifty dashboard under the Developer options.',
                    'gifty-woocommerce'
                ),
                'default' => ''
            ],
        ];
    }

    /**
     * Validate the API key field by checking if the key is valid
     *
     * @param string $key
     * @param string $value
     *
     * @return string
     */
    public function validate_gifty_api_key_field( string $key, string $value ): string {
        $value = trim( $value );
        $api_client = new GiftyClient( $value );

        if ( $api_client->validateApiKey() === false ) {
            WC_Admin_Settings::add_error( __( 'The API-key is invalid.', 'gifty-woocommerce' ) );
        }

        return $value;
    }

    /**
     * This method hooks into woocommerce_get_shop_coupon_data and is fired
     * when a user submits a voucher in the WC voucher input.
     *
     * We validate if the entered voucher is known as a gift card at Gifty.
     * If this is the case the corresponding value is returned and applied as discount.
     *
     * @param mixed $data
     * @param string $gift_card_code
     *
     * @return mixed
     */
    public function retrieve_gift_card( $data, string $gift_card_code ) {
        if ( $gift_card_code === "" ) {
            return $data;
        }

        try {
            $gift_card = $this->client->giftCards->get( $gift_card_code );
        } catch ( ApiException $e ) {
            return $data;
        }

        $data = [
            'amount' => $this->cents_to_float( $gift_card->getBalance() ),
            'description' => 'gifty_gift_card'
        ];

        return $data;
    }

    /**
     * This method hooks into woocommerce_checkout_create_order and is fired after the user
     * clicks on checkout, just before saving the order to the database.
     *
     * If the order contains gift cards known in Gifty, the gift cards will be redeemed without
     * being captured. This is to ensure the gift cards value is still available after payment.
     *
     * @param WC_Order $order
     *
     * @return WC_Order
     */
    public function redeem_gift_card( WC_Order $order ): WC_Order {
        foreach ( $order->get_coupons() as $code => $coupon ) {
            if ( $this->is_gifty_gift_card( $coupon ) === false ) {
                continue;
            }

            if ( $coupon->meta_exists( 'gifty_reserve_transaction' ) ) {
                continue;
            }

            try {
                // Reserve the used gift card amount
                $transaction = $this->client->giftCards->redeem(
                    $coupon->get_code(),
                    [
                        'amount' => $this->get_coupon_discount_in_cents( $coupon ),
                        'currency' => 'EUR',
                        'capture' => false
                    ]
                );

                // Save the transaction ID to capture the transaction later on
                $coupon->add_meta_data( 'gifty_reserve_transaction', $transaction->getId() );

                // Add hook to log the transaction after order is saved
                add_action( 'woocommerce_after_order_object_save', [ $this, 'redeem_gift_card_log' ], 10, 1 );
            } catch ( Exception $exception ) {
                wc_add_notice(
                    __( 'The used gift card is not valid anymore. Please try again.', 'gifty-woocommerce' ),
                    'error'
                );
            }
        }

        return $order;
    }

    /**
     * This method hooks into woocommerce_before_order_object_save and is fired after a
     * coupon is applied to an order from the WooCommerce admin.
     *
     * If the order contains gift cards known in Gifty, the gift cards will be redeemed without
     * being captured. This is to ensure the gift cards value is still available after payment.
     *
     * @param WC_Order $order
     *
     * @return WC_Order
     */
    public function redeem_gift_card_admin( WC_Order $order ): WC_Order {
        foreach ( $order->get_coupons() as $code => $coupon ) {
            $meta = $coupon->get_meta( 'coupon_data', true );

            if ( isset( $meta['description'] ) ) {
                continue;
            }

            if ( $this->is_gifty_gift_card( $coupon, true ) === false ) {
                continue;
            }

            $coupon->add_meta_data(
                'coupon_data',
                [
                    'description' => 'gifty_gift_card'
                ]
            );
        }

        return $this->redeem_gift_card( $order );
    }

    /**
     * This method hooks into woocommerce_checkout_order_created and is fired after the user
     * clicks on checkout, after the  order has been saved to the database.
     *
     * If the order contains gift cards known in Gifty, the gift cards have been redeemed
     * before the order was save to the database. Now we need to add a note to the order so
     * this is traceable.
     *
     * @param WC_Order $order
     *
     * @return WC_Order
     */
    public function redeem_gift_card_log( WC_Order $order ): WC_Order {
        foreach ( $order->get_coupons() as $code => $coupon ) {
            if ( $this->is_gifty_gift_card( $coupon ) === false ) {
                continue;
            }

            // Check if the pending transaction exists
            $transaction_id = $coupon->get_meta( 'gifty_reserve_transaction', true );

            try {
                $giftCard = $this->client->giftCards->get( $coupon->get_code() );
            } catch ( ApiException $e ) {
                $message = sprintf(
                    __(
                        'The used gift card (%s) on order (%s) cannot be retrieved from the API. The gift card is not redeemed.',
                        'gifty-woocommerce'
                    ),
                    $coupon->get_code(),
                    $order->get_id()
                );

                $this->log_transaction( $order, $message, true, true );

                continue;
            }

            $transaction = $giftCard->transactions->get( $transaction_id );

            if ( $transaction->getStatus() !== 'pending' ) {
                continue;
            }

            // Write order note to make the reservation traceable
            $message = sprintf(
                __( 'Reserved %s on gift card %s with transaction %s', 'gifty-woocommerce' ),
                $this->cents_to_human( abs( $transaction->getAmount() ) ),
                $coupon->get_code(),
                $transaction_id
            );

            $this->log_transaction( $order, $message );

            // Unhook so that we log only once
            remove_action( 'woocommerce_after_order_object_save', [ $this, 'redeem_gift_card_log' ], 10 );
        }

        return $order;
    }

    /**
     * This method hooks into three different actions to catch order completion:
     * woocommerce_pre_payment_complete
     * woocommerce_order_status_processing
     * woocommerce_order_status_completed
     *
     * If the order contains gift cards known in Gifty, the transactions
     * will be captured.
     *
     * @param int $order_id
     */
    public function capture_gift_card( int $order_id ): void {
        $order = new WC_Order( $order_id );

        foreach ( $order->get_coupons() as $code => $coupon ) {
            if ( $this->is_gifty_gift_card( $coupon ) === false ) {
                continue;
            }

            if ( $coupon->meta_exists( 'gifty_reserve_transaction' ) === false ) {
                $message = sprintf(
                    __(
                        'The used gift card on order (%s) has no reservation transaction registered in WooCommerce, and therefor could not be finalized.',
                        'gifty-woocommerce'
                    ),
                    $order_id
                );

                $this->log_transaction( $order, $message, true, true );

                continue;
            }

            $transaction_id = $coupon->get_meta( 'gifty_reserve_transaction', true );

            try {
                $giftCard = $this->client->giftCards->get( $coupon->get_code() );
            } catch ( ApiException $e ) {
                $message = sprintf(
                    __(
                        'The used gift card (%s) on order (%s) cannot be retrieved from the API. The gift card is not captured.',
                        'gifty-woocommerce'
                    ),
                    $coupon->get_code(),
                    $order->get_id()
                );

                $this->log_transaction( $order, $message, true, true );

                continue;
            }

            $transaction = $giftCard->transactions->get( $transaction_id );

            if ( $transaction->getStatus() !== 'pending' ) {
                continue;
            }

            try {
                // Capture the pending transaction
                if ( $giftCard->transactions->capture( $transaction_id ) ) {
                    // Write order note to make the redeem traceable
                    $message = sprintf(
                        __( 'Redeemed %s on gift card %s with transaction %s', 'gifty-woocommerce' ),
                        $this->cents_to_human( abs( $transaction->getAmount() ) ),
                        $coupon->get_code(),
                        $transaction->getId()
                    );

                    $this->log_transaction( $order, $message );
                }
            } catch ( Exception $exception ) {
                $message = __( 'The used gift card could not be redeemed. Please try again.', 'gifty-woocommerce' );

                $this->log_transaction( $order, $message, true, true );
            }
        }
    }

    /**
     * This method hooks into two different actions to cancel a gift card redeem:
     * woocommerce_order_status_cancelled
     * woocommerce_order_status_failed
     *
     * If the order contains gift cards known in Gifty while the order is being
     * cancelled or failed, the redeem action will be cancelled if still possible.
     *
     * @param int $order_id
     */
    public function release_gift_card( int $order_id ) {
        $order = new WC_Order( $order_id );

        foreach ( $order->get_coupons() as $code => $coupon ) {
            if ( $this->is_gifty_gift_card( $coupon ) === false ) {
                continue;
            }

            $transaction_id = $coupon->get_meta( 'gifty_reserve_transaction', true );

            try {
                $giftCard = $this->client->giftCards->get( $coupon->get_code() );
                $transaction = $giftCard->transactions->get( $transaction_id );
            } catch ( ApiException $e ) {
                $message = sprintf(
                    __(
                        'The used gift card (%s) on order (%s) cannot be retrieved from the API. The gift card is not released.',
                        'gifty-woocommerce'
                    ),
                    $coupon->get_code(),
                    $order->get_id()
                );

                $this->log_transaction( $order, $message, true, true );

                continue;
            }

            if ( $transaction->getStatus() !== 'pending' ) {
                continue;
            }

            try {
                // Cancel the pending transaction
                if ( $giftCard->transactions->release( $transaction_id ) ) {
                    // Remove the applied gift card from the order
                    $order->remove_coupon( $coupon->get_code() );

                    // Write order note to make the redeem traceable
                    $message = sprintf(
                        __( 'Released %s on gift card %s with transaction %s', 'gifty-woocommerce' ),
                        $this->cents_to_human( abs( $transaction->getAmount() ) ),
                        $coupon->get_code(),
                        $transaction_id
                    );

                    $this->log_transaction( $order, $message );
                }
            } catch ( Exception $exception ) {
                $message = __(
                    'The used gift card could not be released. Please try again.',
                    'gifty-woocommerce'
                );

                $this->log_transaction( $order, $message, true, true );
            }
        }
    }

    /**
     * Validate if the submitted coupon is known at Gifty
     *
     * @param WC_Order_Item_Coupon $coupon
     * @param bool $validate_by_api
     *
     * @return bool
     */
    private function is_gifty_gift_card( WC_Order_Item_Coupon $coupon, bool $validate_by_api = false ): bool {
        if ( false === $coupon instanceof WC_Order_Item_Coupon ) {
            return false;
        }

        if ( $coupon->get_code() === "" ) {
            return false;
        }

        if ( $validate_by_api === true ) {
            try {
                $this->client->giftCards->get( $coupon->get_code() );
            } catch ( ApiException $e ) {
                return false;
            }

            return true;
        }

        $meta = $coupon->get_meta( 'coupon_data' );

        if ( isset( $meta['description'] ) === false || $meta['description'] !== 'gifty_gift_card' ) {
            return false;
        }

        return true;
    }

    /**
     * Write a log message to the order notes
     * and optionally display as notice
     *
     * @param WC_Order $order
     * @param string $message
     * @param bool $display_notice
     * @param bool $is_error
     */
    private function log_transaction(
        WC_Order $order,
        string $message,
        bool $display_notice = false,
        bool $is_error = false
    ): void {
        $order->add_order_note( 'Gifty: ' . $message );

        if ( $display_notice !== false ) {
            wc_add_notice(
                $message,
                $is_error ? 'error' : 'success'
            );
        }
    }

    private function get_api_key(): string {
        return $this->settings['gifty_api_key'];
    }

    private function get_coupon_discount_in_cents( WC_Order_Item_Coupon $coupon ): int {
        return intval( ( floatval( $coupon->get_discount_tax() ) * 100 ) + ( floatval( $coupon->get_discount() ) * 100 ) );
    }

    private function cents_to_float( int $amount ): float {
        return $amount / 100;
    }

    private function cents_to_human( int $amount ): string {
        return wc_price( $this->cents_to_float( $amount ) );
    }
}
