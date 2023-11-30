<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Admin;

use Gifty\WooCommerce\SessionGiftCard;
use Gifty\WooCommerce\GiftCardManager;
use WC_Abstract_Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Modified calculations for WooCommerce Analytical data
 *
 * This class enables correct tax and revenue calculation even when orders
 * are (partially) paid using gift cards. We need to apply these corrections
 * as WooCommerce does not support multiple payment methods per order.
 */
class WC_Gifty_Analytics {
    // Keep a list of processed order IDs to prevent double processing
    private array $processed_order_ids = [];

    public function __construct( bool $corrections_enabled ) {
        if ( $corrections_enabled === true ) {
            add_filter( 'woocommerce_analytics_update_order_stats_data', [ $this, 'filter_gift_card_totals' ], 10, 2 );
        }
    }

    /**
     * @param array $totals
     * @param WC_Abstract_Order $order
     *
     * @return array
     * @throws \Exception
     */
    public function filter_gift_card_totals( array $totals, WC_Abstract_Order $order ): array {
        // Prevent double processing
        if ( in_array( $order->get_id(), $this->processed_order_ids ) ) {
            return $totals;
        }

        $this->processed_order_ids[] = $order->get_id();

        $gift_card_manager = new GiftCardManager();
        $gift_cards = $gift_card_manager->get_gift_cards_from_order( $order->get_id() );

        if ( ! $gift_cards ) {
            return $totals;
        }

        if ( $order->get_type() == 'shop_order_refund' ) {
            return $this->order_refund_totals( $totals, $gift_cards );
        }

        return $this->order_totals( $totals, $gift_cards );
    }

    /**
     * @param array $totals
     * @param SessionGiftCard[] $gift_cards
     *
     * @return array
     */
    private function order_totals( array $totals, array $gift_cards ): array {
        // Update the calculated totals to include the gift card payments
        $total_sales = (float) $totals['total_sales'] ?? 0;
        $net_total = (float) $totals['net_total'] ?? 0;

        foreach ( $gift_cards as $gift_card ) {
            $total_sales += $gift_card->get_amount_used();
            $net_total += $gift_card->get_amount_used();
        }

        return array_merge( $totals, [
            'total_sales' => $total_sales,
            'net_total' => $net_total,
        ] );
    }

    /**
     * @param array $totals
     * @param SessionGiftCard[] $gift_cards
     *
     * @return array
     */
    private function order_refund_totals( array $totals, array $gift_cards ): array {
        // Update the calculated totals to include the gift card payments
        $total_sales = (float) $totals['total_sales'] ?? 0;
        $net_total = (float) $totals['net_total'] ?? 0;

        foreach ( $gift_cards as $gift_card ) {
            $total_sales -= $gift_card->get_amount_refunded();
            $net_total -= $gift_card->get_amount_refunded();
        }

        $totals = array_merge( $totals, [
            'total_sales' => $total_sales,
            'net_total' => $net_total,
        ] );

        return $totals;
    }
}
