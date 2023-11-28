<?php

declare( strict_types=1 );

namespace Gifty\WooCommerce;

use Gifty\Client\GiftyClient;
use Gifty\WooCommerce\Admin\WC_Gifty_Analytics;
use Gifty\WooCommerce\Admin\WC_Gifty_Refunds;
use Gifty\WooCommerce\Compatibility\CompatibilityRegister;
use WC_Admin_Settings;
use WC_Integration;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

final class WC_Integration_Gifty extends WC_Integration {
    public GiftyClient $client;
    private WC_Gifty_Cart $cart;
    private WC_Gifty_Order $order;
    private WC_Gifty_API $rest_api;
    private WC_Gifty_Analytics $admin_analytics;
    private WC_Gifty_Refunds $admin_refunds;

    public function __construct() {
        $this->id = 'gifty-woocommerce';
        $this->method_title = __( 'Gifty', 'gifty-woocommerce' );
        $this->method_description = __( 'Accept Gifty gift cards in your WooCommerce shop.', 'gifty-woocommerce' );

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Initialize the Gifty API in the WordPress locale
        $this->client = new GiftyClient( $this->get_option( 'gifty_api_key' ), [
            'api_headers' => [
                'Accept-Language' => get_locale(),
            ]
        ] );

        // Register compatibility classes
        if ( $this->get_option( 'gifty_wc_compatibility_fixes' ) === 'yes' ) {
            new CompatibilityRegister( $this->client );
        }

        // Initialize modules
        $this->rest_api = new WC_Gifty_API( $this->client );
        $this->cart = new WC_Gifty_Cart(
            $this->client,
            $this->get_option( 'gifty_gc_field_in_cart' ) === 'yes',
            $this->get_option( 'gifty_gc_field_in_checkout' ) === 'yes',
        );
        $this->order = new WC_Gifty_Order( $this->client );
        $this->admin_refunds = new WC_Gifty_Refunds( $this->client );
        $this->admin_analytics = new WC_Gifty_Analytics( $this->get_option( 'gifty_wc_analytics_integration' ) === 'yes' );

        // Actions
        add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    /**
     * Register form fields for the WooCommerce settings page
     */
    public function init_form_fields() {
        $this->form_fields = [
            'gifty_api_key' => [
                'title' => __( 'API Key', 'gifty-woocommerce' ),
                'type' => 'password',
                'description' => __(
                    'Enter your API Key. You can manage API-keys in your Gifty dashboard under the Developer options.',
                    'gifty-woocommerce'
                ),
                'default' => ''
            ],
            'gifty_gc_field_in_cart' => [
                'title' => __( 'Gift Card field in cart', 'gifty-woocommerce' ),
                'label' => __( 'Show gift card field on the cart page', 'gifty-woocommerce' ),
                'type' => 'checkbox',
                'description' => __(
                    'Should the gift card field be visible on the cart page.',
                    'gifty-woocommerce'
                ),
                'default' => 'yes'
            ],
            'gifty_gc_field_in_checkout' => [
                'title' => __( 'Gift Card field in checkout', 'gifty-woocommerce' ),
                'label' => __( 'Show gift card field on the checkout page', 'gifty-woocommerce' ),
                'type' => 'checkbox',
                'description' => __(
                    'Should the gift card field be visible on the checkout page.',
                    'gifty-woocommerce'
                ),
                'default' => 'yes'
            ],
            'gifty_wc_analytics_integration' => [
                'title' => __( 'WooCommerce Analytics', 'gifty-woocommerce' ),
                'label' => __( 'Enable calculation corrections', 'gifty-woocommerce' ),
                'type' => 'checkbox',
                'description' => __(
                    'Apply corrections on WooCommerce Analytics data for correct tax and revenue reporting.',
                    'gifty-woocommerce'
                ),
                'default' => 'yes'
            ],
            'gifty_wc_compatibility_fixes' => [
                'title' => __( 'Compatibility fixes', 'gifty-woocommerce' ),
                'label' => __( 'Enable compatibility fixes with other plugins', 'gifty-woocommerce' ),
                'type' => 'checkbox',
                'description' => __(
                    'Enable fixes and improvements to work better with other WordPress plugins.',
                    'gifty-woocommerce'
                ),
                'default' => 'yes'
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
}
