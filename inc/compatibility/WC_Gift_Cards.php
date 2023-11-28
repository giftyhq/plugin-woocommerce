<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce\Compatibility;

use Gifty\Client\Exceptions\ApiException;
use Gifty\Client\GiftyClient;
use Gifty\Client\Resources\GiftCard;
use Gifty\WooCommerce\GiftCardManager;
use Gifty\WooCommerce\SessionGiftCard;
use WP_Error;

/**
 * Compatibility class for WooCommerce Gift Cards
 * If the WooCommerce Gift Cards plugin is used, we'll hook in to their gift card form
 * on the front end. This prevents double gift card forms.
 */
class WC_Gift_Cards implements _CompatibilityInterface {

    private GiftyClient $client;
    private array $notices = [];

    public function __construct( GiftyClient $client ) {
        $this->client = $client;
    }

    public function register_fix(): void {
        // Hook into the existing form
        add_action( 'wc_ajax_apply_gift_card_to_session', [ $this, 'validate_gifty_gc' ], 1 );

        // Hide Gifty forms
        add_filter( 'gifty_wc_gc_form_visible_in_cart', '__return_false' );
        add_filter( 'gifty_wc_gc_form_visible_in_checkout', '__return_false' );
    }

    public function fix_should_be_activated(): bool {
        if ( is_plugin_active( 'woocommerce-gift-cards/woocommerce-gift-cards.php' ) ) {
            return true;
        }

        return false;
    }

    public function validate_gifty_gc(): void {
        // Check if the WC_GC class exists
        if ( ! class_exists( 'WC_Gift_Cards' ) ) {
            return;
        }

        // Prepare data and check nonce
        check_ajax_referer( 'redeem-card', 'security' );
        $args = wc_clean( $_POST );
        $gift_card_code = GiftCard::cleanCode( $args['wc_gc_cart_code'] );

        // Check if the code is known to Gifty
        $gift_card = $this->get_gift_card( $gift_card_code );

        if ( $gift_card instanceof WP_Error ) {
            return;
        }

        $gift_card_manager = new GiftCardManager();

        // Check if this gift card code has already been applied
        if ( $gift_card_manager->session_has_gift_card( $gift_card->getId() ) ) {
            $this->notices[] = [
                'text' => __( 'This gift card has already been applied', 'gifty-woocommerce' ),
                'type' => 'error'
            ];
        }

        // Check if the gift card is valid
        if ( ! $gift_card->isRedeemable() ) {
            $this->notices[] = [
                'text' => __( 'This gift card has no available balance', 'gifty-woocommerce' ),
                'type' => 'error'
            ];
        }

        // Add the new gift card to the session
        if ( empty( $this->notices ) ) {
            $gift_card_manager->upsert_gift_card_to_session(
                new SessionGiftCard(
                    $gift_card->getId(),
                    $gift_card_code,
                    (float) $gift_card->getBalance() / 100,
                    0
                )
            );
        }

        $html = '';
        $is_checkout = isset( $args['wc_gc_is_checkout'] ) && 'yes' === $args['wc_gc_is_checkout'];

        if ( ! $is_checkout ) {
            wc_maybe_define_constant( 'WOOCOMMERCE_CART', true );

            ob_start();
            WC()->cart->calculate_totals();
            woocommerce_cart_totals();
            $html = ob_get_clean();
        }

        // Grap notices.
        ob_start();
        $this->display_notices();
        $notices_html = ob_get_clean();

        $response = [
            'result' => 'success',
            'applied' => empty( $this->notices ) ? 'yes' : 'no',
            'html' => $html,
            'notices_html' => $notices_html
        ];

        wp_send_json( $response );
    }

    private function display_notices() {
        if ( ! empty( $this->notices ) ) {
            foreach ( $this->notices as $notice ) {
                if ( empty( $notice['type'] ) ) {
                    $notice['type'] = 'message';
                }
                echo '<div class="woocommerce-' . esc_attr( $notice['type'] ) . '">' . wp_kses_post( $notice['text'] ) . '</div>';
            }
        }
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
