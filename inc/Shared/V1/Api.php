<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Shared\V1;

use Gifty\WooCommerce\GiftCardManager;
use Gifty\WooCommerce\SessionGiftCard;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class Api
 *
 * Provides an interface for third-party plugins and themes to interact with the Gifty WooCommerce plugin.
 * This class exposes methods to retrieve and manipulate gift card data associated with WooCommerce orders.
 *
 * Usage:
 * - To retrieve all gift cards applied to an order:
 *   `$api = new Gifty\WooCommerce\Shared\V1\Api();
 *    $giftCards = $api->get_gift_cards_applied_to_order($orderId);`
 *
 * - To get the total amount paid with gift cards for a specific order:
 *   `$totalPaid = $api->get_total_applied_gift_card_amount($orderId);`
 *
 * - To get the total amount refunded with gift cards for a specific order:
 *   `$totalRefunded = $api->get_total_refunded_gift_card_amount($orderId);`
 *
 *  Checking Availability:
 *  Before using this API, ensure that it is available and this plugin is active. Example:
 *  - Check if the class exists to avoid fatal errors:
 *    `if (class_exists('Gifty\WooCommerce\Shared\V1\Api')) {
 *       $api = new Gifty\WooCommerce\Shared\V1\Api();
 *       // You can now safely use the API
 *    } else {
 *       error_log('The required Gifty API class is not available.');
 *       // Handle the error appropriately
 *    }`
 *
 * Each method ensures that the operations are performed safely and returns data in a structured format
 * that can be easily used by other components. Errors are managed internally and will result in empty arrays or zero values,
 * allowing calling code to easily handle scenarios where data retrieval fails without checking for exceptions.
 *
 * Note that it is important to handle the returned values appropriately as they may be empty or zero in case of errors
 * or if no gift cards are associated with the given order.
 *
 */
final class Api
{

    /**
     * Get the gift cards applied to an order.
     *
     * @param int $order_id
     *
     * @return array
     */
    public function get_gift_cards_applied_to_order( int $order_id ): array
    {
        $cardManager = new GiftCardManager();

        try {
            $gift_cards = $cardManager->get_gift_cards_from_order( $order_id );
        } catch ( \Exception $e ) {
            $gift_cards = [];
        }

        return array_map( [ $this, 'map_gift_card_order_data' ], $gift_cards );
    }

    /**
     * Get the total amount that was paid with gift cards for an order
     *
     * @param int $order_id
     * @return float
     */
    public function get_total_applied_gift_card_amount( int $order_id ): float {
        $cardManager = new GiftCardManager();

        try {
            $gift_cards = $cardManager->get_gift_cards_from_order( $order_id );
        } catch ( \Exception $e ) {
            $gift_cards = [];
        }

        return array_reduce( $gift_cards, function ( float $total, SessionGiftCard $gift_card ) {
            return $total + $gift_card->get_amount_used();
        }, 0 );
    }

    /**
     * Get the total amount that was refunded with gift cards for an order
     *
     * @param int $order_id
     * @return float
     */
    public function get_total_refunded_gift_card_amount( int $order_id ): float {
        $cardManager = new GiftCardManager();

        try {
            $gift_cards = $cardManager->get_gift_cards_from_order( $order_id );
        } catch ( \Exception $e ) {
            $gift_cards = [];
        }

        return array_reduce( $gift_cards, function ( float $total, SessionGiftCard $gift_card ) {
            return $total + $gift_card->get_amount_refunded();
        }, 0 );
    }

    /**
     * Helper function to map gift card data to a format that can
     * be used by third party plugins and themes.
     *
     * @param SessionGiftCard $gift_card
     * @return array
     */
    private function map_gift_card_order_data( SessionGiftCard $gift_card ): array {
        return [
            'id' => $gift_card->get_id(),
            'masked_code' => $gift_card->get_masked_code(),
            'amount_used' => $gift_card->get_amount_used(),
            'amount_refunded' => $gift_card->get_amount_refunded(),
            'transaction_id_redeem' => $gift_card->get_transaction_id_redeem(),
            'transaction_id_capture' => $gift_card->get_transaction_id_capture(),
            'transaction_id_release' => $gift_card->get_transaction_id_release(),
        ];
    }
}
