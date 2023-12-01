<?php
/**
 * Plugin Name: Gifty for WooCommerce
 * Plugin URI: https://github.com/giftyhq/plugin-woocommerce
 * Description: WordPress plugin for accepting Gifty gift cards in your WooCommerce shop.
 * Domain Path: /languages
 * Version: 2.0.3
 * Author: Gifty B.V.
 * Author URI: https://gifty.nl
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 5.4
 * Tested up to: 6.4
 * WC requires at least: 8.2.0
 * WC tested up to: 8.3.1
 */

declare( strict_types=1 );

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'Gifty_WooCommerce' ) ) {
    final class Gifty_WooCommerce {

        private static self|null $_instance = null;

        public function __construct() {
            add_action( 'plugins_loaded', [ $this, 'init' ] );
        }

        public static function instance(): self {
            if ( is_null( self::$_instance ) ) {
                self::$_instance = new self();
            }

            return self::$_instance;
        }

        public function init(): void {
            load_plugin_textdomain( 'gifty-woocommerce', false, basename( dirname( __FILE__ ) ) . '/languages/' );

            // Check if WooCommerce is installed
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', [ $this, 'notice_missing_wc_install' ] );

                return;
            }

            // Register the Gifty WC plugin
            if ( class_exists( 'WC_Integration' ) ) {
                // Register the integration.
                add_filter( 'woocommerce_integrations', [ $this, 'add_integration' ] );
            }

            // Register assets
            add_action( 'wp_enqueue_scripts', [ $this, 'register_front_styles' ] );

            // Register admin assets
            add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_styles' ] );

            // Declare compatability with HPOS
            add_action( 'before_woocommerce_init', function () {
                if ( class_exists( FeaturesUtil::class ) ) {
                    FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
                }
            } );

            // Add setting links for the plugin page
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );
        }

        public function add_integration( array $integrations ): array {
            $integrations[] = \Gifty\WooCommerce\WC_Integration_Gifty::class;

            return $integrations;
        }

        public function register_front_styles(): void {
            // Front JS and CSS for the cart and checkout page
            if ( is_checkout() || is_cart() ) {
                $asset_file = include( $this->get_plugin_root_path() . 'build/checkout.asset.php' );

                wp_register_script(
                    'gifty-checkout',
                    plugins_url( 'build/checkout.js', __FILE__ ),
                    $asset_file['dependencies'],
                    $asset_file['version']
                );

                wp_register_style(
                    'gifty-checkout',
                    WC_Gifty()->get_plugin_root_url() . 'build/checkout.css',
                    $asset_file['dependencies'],
                    $asset_file['version']
                );

                wp_enqueue_script( 'gifty-checkout' );
                wp_enqueue_style( 'gifty-checkout' );

                // Create nonce and pass it to the checkout script
                wp_localize_script( 'gifty-checkout', 'wc_params', [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'apply_gift_card_nonce' => wp_create_nonce( 'gifty_apply_gift_card' ),
                    'remove_gift_card_nonce' => wp_create_nonce( 'gifty_remove_gift_card' ),
                ] );
            }
        }

        public function register_admin_styles( string $hook ): void {
            if ( 'woocommerce_page_wc-orders' !== $hook ) {
                return;
            }

            $asset_file = include( WC_Gifty()->get_plugin_root_path() . 'build/admin.asset.php' );

            wp_register_script(
                'gifty-admin',
                plugins_url( 'build/admin.js', __FILE__ ),
                $asset_file['dependencies'],
                $asset_file['version']
            );

            wp_enqueue_script( 'gifty-admin' );
        }

        public function notice_missing_wc_install(): void {
            echo '<div class="error"><p><strong>' . sprintf(
                    esc_html__(
                        'Gifty for WooCommerce requires WooCommerce to be installed and active. You can download %s here.',
                        'gifty-woocommerce'
                    ),
                    '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
                ) . '</strong></p></div>';
        }

        public function plugin_action_links( array $links ): array {
            $links[] = sprintf(
                '<a href="admin.php?page=wc-settings&tab=integration&section=gifty-woocommerce">%s</a>',
                esc_html__( 'Settings', 'gifty-woocommerce' )
            );

            return $links;
        }

        public function get_plugin_root_path(): string {
            return plugin_dir_path( __FILE__ );
        }

        public function get_plugin_root_url(): string {
            return plugin_dir_url( __FILE__ );
        }
    }

    function WC_Gifty(): Gifty_WooCommerce {
        return Gifty_WooCommerce::instance();
    }

    WC_Gifty();
}
