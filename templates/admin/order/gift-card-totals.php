<?php

use Gifty\WooCommerce\SessionGiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<tbody class="wc-order-totals gifty-order-totals" style="border-top: 1px solid #999; margin-top:12px; padding-top:12px">

<?php
/**
 * @var $gift_cards SessionGiftCard[]
 * @var $order WC_Order
 */
foreach ( $gift_cards as $gift_card ) : ?>
    <tr class="cart-discount gift-card">
        <td class="label">
			<?php
			echo esc_html( __( 'Gift Card', 'gifty-woocommerce' ) ); ?>
            <small>(<?php
	            echo esc_html( $gift_card->get_masked_code() ); ?>)</small>:
        </td>
        <td width="1%"></td>


		<?php
		if ( $gift_card->get_amount_refunded() ): ?>
            <td class="total refunded-total">
                <span style="text-decoration: line-through;"><?php
	                echo wp_kses_post( wc_price( $gift_card->get_amount_used() * - 1,
	                                             [ 'currency' => $order->get_currency() ] ) ); ?></span>
				<?php
				echo wp_kses_post( wc_price( $gift_card->get_amount_used() + $gift_card->get_amount_refunded() * - 1,
				                             [ 'currency' => $order->get_currency() ] ) ); ?>
            </td>
		<?php
		else: ?>
            <td class="total">
				<?php
				echo wp_kses_post( wc_price( $gift_card->get_amount_used() * - 1,
				                             [ 'currency' => $order->get_currency() ] ) ); ?>

            </td>
		<?php
		endif; ?>
    </tr>
<?php
endforeach; ?>
</tbody>
