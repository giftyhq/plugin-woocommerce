<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class GiftCardManager {
    const SESSION_KEY = 'gifty_applied_gift_cards';
    const META_KEY = '_gifty_applied_gift_cards';

    public function __construct() {
        //
    }

    public function upsert_gift_card_to_session( SessionGiftCard $gift_card ): void {
        $session_data = WC()->session->get( self::SESSION_KEY, [] );
        $session_data[ $gift_card->get_id() ] = $gift_card->toArray();
        WC()->session->set( self::SESSION_KEY, $session_data );
    }

    public function remove_gift_card_from_session( string $gift_card_id ): void {
        $session_data = WC()->session->get( self::SESSION_KEY, [] );
        unset( $session_data[ $gift_card_id ] );
        WC()->session->set( self::SESSION_KEY, $session_data );
    }

    public function session_has_gift_cards(): bool {
        $session_data = WC()->session->get( self::SESSION_KEY, [] );

        return count( $session_data ) > 0;
    }

    public function session_has_gift_card( string $gift_card_id ): bool {
        $session_data = WC()->session->get( self::SESSION_KEY, [] );

        return isset( $session_data[ $gift_card_id ] );
    }

    public function destroy_session(): void {
        WC()->session->__unset( self::SESSION_KEY );
    }

    /**
     * @param int $order_id
     * @param $gift_cards SessionGiftCard[]
     *
     * @return void
     * @throws \Exception
     */
    public function upsert_gift_cards_to_order_meta( int $order_id, array $gift_cards ): void {
        $gift_cards_data = array_map( function ( SessionGiftCard $gift_card ): array {
            $data = $gift_card->toArray();

            // Remove the original gift card code from the data for safety
            $data['code'] = $gift_card->get_masked_code();

            return $data;
        }, $gift_cards );

        // Update WooCommerce order meta
        $order = wc_get_order( $order_id );
		$order->update_meta_data( self::META_KEY, $gift_cards_data );
		$order->save();
    }

    // Saves gift card data from the session to the order meta
    public function save_session_gift_cards_to_order_meta( int $order_id ): void {
        $gift_cards = $this->get_gift_cards_from_session();

        $this->upsert_gift_cards_to_order_meta( $order_id, $gift_cards );
    }

    /**
     * @return array<SessionGiftCard>
     */
    public function get_gift_cards_from_session(): array {
        $session_data = WC()->session->get( self::SESSION_KEY, [] );

        return array_map( function ( array $data ): SessionGiftCard {
            return new SessionGiftCard( ...$data );
        }, $session_data );
    }

    /**
     * @param int $order_id
     *
     * @return SessionGiftCard[]
     * @throws \Exception
     */
    public function get_gift_cards_from_order( int $order_id ): array {
		$order = wc_get_order( $order_id );

        if( ! $order ) {
            return [];
        }

		$gift_cards_data = $order->get_meta( self::META_KEY );

        if ( ! $gift_cards_data ) {
            return [];
        }

        return array_map( function ( array $data ): SessionGiftCard {
            return new SessionGiftCard( ...$data );
        }, $gift_cards_data );
    }

    public function update_amount_used_per_gift_card_for_order_total( float $order_total ): void {
        $gift_cards = $this->get_gift_cards_from_session();

        $order_total_to_cover = $order_total;

        foreach ( $gift_cards as $gift_card ) {
            // If total is covered, no more balance from this card will be applied
            if ( $order_total_to_cover <= 0 ) {
                // Reset the amount used to zero
                $gift_card->set_amount_used( 0 );
            }

            // Calculate how much of the gift card balance can be applied
            $balance = $gift_card->get_balance();
            $apply_amount = min( $balance, $order_total_to_cover );
            $order_total_to_cover -= $apply_amount;

            $gift_card->set_amount_used( $apply_amount );

            // Update the gift card to the session
            $this->upsert_gift_card_to_session( $gift_card );
        }
    }
}
