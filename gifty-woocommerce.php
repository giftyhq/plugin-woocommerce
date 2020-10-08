<?php
/**
 * Plugin Name: Gifty for WooCommerce
 * Plugin URI: https://github.com/giftyhq/plugin-woocommerce
 * Description: WordPress plugin for accepting Gifty gift cards in your WooCommerce shop.
 * Domain Path: /languages
 * Version: 1.0.6
 * Author: Gifty B.V.
 * Author URI: https://gifty.nl
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.2
 * Requires at least: 5.4
 * Tested up to: 5.5
 * WC requires at least: 4.4.0
 * WC tested up to: 4.5.2
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';

if ( ! class_exists( 'Gifty_WooCommerce' ) ) {
    final class Gifty_WooCommerce {

        public function __construct() {
            add_action( 'plugins_loaded', [ $this, 'init' ] );
        }

        public function init() {
            load_plugin_textdomain( 'gifty-woocommerce', false, basename( dirname( __FILE__ ) ) . '/languages/' );

            // Check if WooCommerce is installed
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', [ $this, 'notice_missing_wc_install' ] );

                return;
            }

            // Register the Gifty plugin
            if ( class_exists( 'WC_Integration' ) ) {
                // Register the integration.
                add_filter( 'woocommerce_integrations', [ $this, 'add_integration' ] );
            }

            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );
        }

        public function add_integration( array $integrations ): array {
            $integrations[] = \Gifty\WooCommerce\WC_Integration_Gifty::class;

            return $integrations;
        }

        public function notice_missing_wc_install() {
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
    }

    $giftyWooCommercePlugin = new Gifty_WooCommerce();
}
