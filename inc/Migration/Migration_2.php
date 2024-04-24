<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Migration;

use Gifty\Client\GiftyClient;
use Gifty\Client\Resources\GiftCard;
use Gifty\Client\Resources\Transaction;
use Gifty\WooCommerce\GiftCardManager;
use Gifty\WooCommerce\SessionGiftCard;

class Migration_2 implements MigrationInterface
{

    private GiftyClient     $client;
    private GiftCardManager $manager;
    public const DB_VERSION = 2;

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

        // Schedule the next batch in 10 seconds
        as_schedule_single_action( time() + 10, 'gifty_wc_execute_plugin_migration', [
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

	/**
	 * In this migration we'll migrate gift card data from order_item_meta to order_meta
	 *
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
    private function migrate_order( \WC_Order $order ): void
    {
		// If the order has no wc_get_order_item_meta, the order doesn't contain gift card data and we return
	    try {
		    $deprecated_data = wc_get_order_item_meta( $order->get_id(), '_gifty_applied_gift_cards' );
	    } catch ( \Exception $e ) {
		    $deprecated_data = null;
	    }

	    if ( ! $deprecated_data ) {
		    return;
	    }

		$deprecated_data_mapped = array_map( function ( array $data ): SessionGiftCard {
			return new SessionGiftCard( ...$data );
		}, $deprecated_data );


        /*
         * Try to update the order with the new gift cards
         * As we might work with very old data, we'll just skip the order if we fail to prevent any issues
         */
        try {
            // Add the gift cards to the order
            $this->manager->upsert_gift_cards_to_order_meta( $order->get_id(), $deprecated_data_mapped );

            // Remove the old order item meta
	        wc_delete_order_item_meta( $order->get_id(), '_gifty_applied_gift_cards' );
        } catch ( \Throwable $e ) {
            return;
        }
    }
}
