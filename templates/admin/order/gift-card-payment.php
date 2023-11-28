<?php

use Gifty\WooCommerce\SessionGiftCard;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>

<tr>
    <td class="label"><?php
		esc_html_e( 'Order Total', 'woocommerce' ); ?>:
    </td>
    <td width="1%"></td>
    <td class="total">
		<?php
		echo wc_price( $order_total, array( 'currency' => $order->get_currency() ) ); ?>
    </td>
</tr>

<tr>
    <td colspan="3">
        <table class="wc-order-totals"
               style="border-top: 1px solid #999; margin-top:12px; padding-top:12px; width: 100%;">
            <tr>
                <td class="<?php
				echo $order->get_total_refunded() ? 'label' : 'label label-highlight'; ?>"><?php
					esc_html_e( 'Paid', 'woocommerce' ); ?>: <br/></td>
                <td width="1%"></td>
                <td class="total">
					<?php
					echo wc_price( $gift_cards_total, array( 'currency' => $order->get_currency() ) ); ?>
                </td>
            </tr>
        </table>
    </td>
</tr>

<tr>
    <td>
        <span class="description">
        <?php
        echo esc_html( sprintf( __( '%1$s via %2$s', 'woocommerce' ),
                                $gift_card_payment_date,
                                'Gifty' ) ); ?>
        </span>
    </td>
    <td colspan="2"></td>
</tr>

<?php
/**
 * @var $gift_cards SessionGiftCard[]
 * @var $order WC_Order
 */
foreach ( $gift_cards as $gift_card ) : ?>
    <tr class="gift-card">
        <td class="label">
			<?php echo esc_html( __( 'Gift Card', 'gifty-woocommerce' ) ); ?>
            <small>(<?php echo esc_html( $gift_card->get_masked_code() ); ?>)</small>:
        </td>
        <td width="1%"></td>
        <td class="total">
			<?php echo wp_kses_post( wc_price( $gift_card->get_amount_used(),
			                             [ 'currency' => $order->get_currency() ] ) ); ?>
        </td>
    </tr>
<?php
endforeach; ?>

<?php if($gift_cards_refund_total): ?>
    <tr>
        <td class="label refunded-total"><?php esc_html_e( 'Refunded', 'woocommerce' ); ?>:</td>
        <td width="1%"></td>
        <td class="total refunded-total">-<?php echo wc_price( $gift_cards_refund_total, array( 'currency' => $order->get_currency() ) ); ?></td>
    </tr>
<?php endif; ?>

<tr class="gifty-order-totals"></tr>
