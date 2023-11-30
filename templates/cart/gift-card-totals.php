<?php

use Automattic\Jetpack\Constants;
use Gifty\WooCommerce\SessionGiftCard;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

?>

<tr class="cart-subtotal">
    <th>
        <?php esc_html_e( 'Total', 'gifty-woocommerce' ); ?>
        <small>(<?php esc_html_e( 'before gift cards', 'gifty-woocommerce' ); ?>)</small>
    </th>
    <td data-title="<?php
    esc_attr_e( 'Total', 'gifty-woocommerce' ); ?> <?php
    esc_attr_e( 'before gift cards', 'gifty-woocommerce' ); ?>">
        <?php
        /**
         * @var $total_before_gift_cards float
         */
        echo wp_kses_post( wc_price( $total_before_gift_cards ) ); ?>
    </td>
</tr>

    <?php
    /**
     * @var $gift_cards SessionGiftCard[]
     */
    foreach ( $gift_cards as $gift_card ) : ?>
        <tr class="cart-discount gift-card">
            <th>
                <?php
                esc_html_e( 'Gift Card', 'gifty-woocommerce' ); ?> (<?php
                echo wp_kses_post( wc_price( $gift_card->get_balance() ) ); ?>)<br/>
                <small><?php
                    echo esc_html( $gift_card->get_masked_code() ); ?></small>
            </th>
            <td data-title="<?php
            esc_attr_e( 'Gift Card', 'gifty-woocommerce' ); ?>">
                <?php
                echo wp_kses_post( wc_price( $gift_card->get_amount_used() * - 1 ) ); ?>

                <a href="#" class="gifty-gift-card-remove" data-gift-card-id="<?php
                echo esc_attr( $gift_card->get_id() ); ?>"><?php
                    _e( '[Remove]', 'woocommerce' ); ?></a>
            </td>
        </tr>
    <?php
    endforeach; ?>
</form>
