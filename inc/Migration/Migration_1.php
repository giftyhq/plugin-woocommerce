<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Migration;

use Gifty\Client\GiftyClient;
use Gifty\Client\Resources\GiftCard;
use Gifty\Client\Resources\Transaction;
use Gifty\WooCommerce\GiftCardManager;
use Gifty\WooCommerce\SessionGiftCard;

class Migration_1 implements MigrationInterface
{

    private GiftyClient     $client;
    private GiftCardManager $manager;
    public const DB_VERSION = 1;

    public function __construct( GiftyClient $client )
    {
        $this->client = $client;
        $this->manager = new GiftCardManager();
    }

    public static function schedule(): void
    {
        // This migration can be processed in the background, so we'll schedule it to run at a later moment
        if ( false === as_has_scheduled_action( 'gifty_wc_execute_plugin_migration' ) ) {
            as_enqueue_async_action( 'gifty_wc_execute_plugin_migration', [
                'migration' => self::class,
                'parameters' => [
                    'offset' => 0,
                ],
            ] );
        }
    }

    public function migrate( array $options = [] ): void
    {
        // Get all orders in a batch size of 50
        $batchSize = 75;
        $offset = $options['offset'];

        // Get the orders to migrate. We'll only get orders after 2020-09-24 (the release date of the plugin)
        $orders = wc_get_orders(
            [
                'limit' => $batchSize,
                'offset' => $offset,
                'status' => 'any',
                'type' => 'shop_order',
                'date_created' => '>2020-09-24',
            ] );

        // If there are no orders left, we are done
        if ( empty( $orders ) ) {
            // Update the DB version
            update_option( 'gifty_db_version', self::DB_VERSION );

            return;
        }

        // Schedule the next batch in 1 minute
        as_schedule_single_action( time() + 60, 'gifty_wc_execute_plugin_migration', [
            'migration' => self::class,
            'parameters' => [
                'offset' => $offset + $batchSize,
            ],
        ] );

        // Migrate the orders from this batch
        foreach ( $orders as $order ) {
            $this->migrate_order( $order );
        }
    }

    private function migrate_order( \WC_Order $order ): void
    {
        // If the order does not contain any coupons, return
        if ( $order->get_total_discount() <= 0 ) {
            return;
        }

        // Get the coupons from the order
        $coupons = $order->get_coupons();
        $session_gift_cards = [];

        // Loop through the coupons and create a gift card for each coupon
        foreach ( $coupons as $coupon ) {
            // If not 16 characters, we are sure it is not a (Gifty) gift card
            if ( strlen( $coupon->get_code() ) !== 16 ) {
                continue;
            }

            // Validate if this is a (Gifty gift card) coupon by checking if it contains a meta key gifty_reserve_transaction
            $redeem_transaction_id = $coupon->get_meta( 'gifty_reserve_transaction' );

            if ( !$redeem_transaction_id ) {
                continue;
            }

            // Get the gift card and transaction data from remote
            $gift_card = $this->get_gift_card_remote( $coupon->get_code() );
            $transaction = $this->get_transaction_remote( $redeem_transaction_id );

            if ( !$gift_card || !$transaction ) {
                continue;
            }

            $code = GiftCard::cleanCode( strtoupper( $coupon->get_code() ) );

            // Build the new gift card object
            $session_gift_card = new SessionGiftCard(
                $gift_card->getId(),
                $code,
                (float)( $transaction->getAmount() * -1 ) / 100,
                (float)( $transaction->getAmount() * -1 ) / 100,
                'XXXX - XXXX - XXXX - ' . substr( $code, -4 ),
            );
            $session_gift_card->set_transaction_id_redeem( $redeem_transaction_id );

            // Set the capture transaction ID if the gift card has been captured
            if ( $transaction->getStatus() !== 'pending' ) {
                $session_gift_card->set_transaction_id_capture( $transaction->getId() );
            }

            /*
             * Note that we don't set the (possible) release transaction, as the previous plugin just removed
             * the coupon from the order when the gift card was released.
             */

            $session_gift_cards[] = $session_gift_card;
        }

        // If there are no gift cards, return
        if ( empty( $session_gift_cards ) ) {
            return;
        }

        /*
         * Try to update the order with the new gift cards
         * Because we are updating old order data there is a good possibility that this will fail
         * because other plugins from the past are now missing etc. In those cases we will just skip
         * the order.
         */
        try {
            // Add the gift cards to the order
            $this->manager->upsert_gift_cards_to_order_meta( $order->get_id(), $session_gift_cards );

            // Remove the coupons from the order
            foreach ( $coupons as $coupon ) {
                $order->remove_coupon( $coupon->get_code() );
            }

            $order->calculate_totals();
            $order->save();
        } catch ( \Throwable $e ) {
            return;
        }
    }

    private function get_gift_card_remote( string $code ): null|GiftCard
    {
        try {
            return $this->client->giftCards->get( $code );
        } catch ( \Exception $e ) {
            return null;
        }
    }

    private function get_transaction_remote( string $transaction_id ): null|Transaction
    {
        try {
            return $this->client->transactions->get( $transaction_id );
        } catch ( \Exception $e ) {
            return null;
        }
    }
}
