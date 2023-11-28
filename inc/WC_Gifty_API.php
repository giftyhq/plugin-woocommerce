<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

use Gifty\Client\Exceptions\ApiException;
use Gifty\Client\GiftyClient;
use Gifty\Client\Resources\GiftCard;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Gifty_API
 * Registers and handles REST API endpoints.
 */
class WC_Gifty_API {

    private GiftyClient $gifty_client;

    public function __construct( GiftyClient $gifty_client ) {
        $this->gifty_client = $gifty_client;

        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        // /gifty/balance/{card}
        register_rest_route(
            'gifty/v1',
            '/balance/(?P<card>[a-zA-Z0-9]+)',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [ $this, 'get_balance' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    private function gift_card_code_masked( string $code ): string {
        return 'XXXX - XXXX - XXXX - ' . substr( $code, - 4 );
    }

    private function get_gift_card( string $code ): \WP_Error|GiftCard {
        try {
            $gift_card = $this->gifty_client
                ->giftCards
                ->get( $code );
        } catch ( ApiException $e ) {
            return new \WP_Error( $e->getCode(), $e->getMessage() );
        }

        return $gift_card;
    }

    public function get_balance( \WP_REST_Request $request ): \WP_REST_Response {
        $gift_card_code = GiftCard::cleanCode( $request->get_param( 'card' ) );
        $gift_Card = $this->get_gift_card( $gift_card_code );

        if ( is_wp_error( $gift_Card ) ) {
            return new \WP_REST_Response(
                [
                    'error' => $gift_Card->get_error_message(),
                ],
                $gift_Card->get_error_code() );
        }

        return new \WP_REST_Response(
            [
                'card' => $this->gift_card_code_masked( $gift_card_code ),
                'balance' => $gift_Card->getBalance(),
                'currency' => $gift_Card->getCurrency(),
                'expires' => $gift_Card->getExpiresAt(),
            ],
            200
        );
    }
}
