<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

$refund_amount = '<span class="wc-order-refund-amount">' . wc_price( 0, array( 'currency' => $order->get_currency() ) ) . '</span>';

?>
<button type="button" class="button button-primary wc-gifty-refund-button do-api-refund" style="display: none;"><?php echo sprintf( esc_html__( 'Refund %1$s via %2$s', 'woocommerce' ), wp_kses_post( $refund_amount ), 'Gifty' ); ?></button>
<tr class="wc-gifty-refund-applied" data-amount="<?php echo $already_refunded; ?>" style="display: none;">
    <td class="label"><?php esc_html_e( 'Amount already refunded to Gifty gift cards', 'gifty-woocommerce' ); ?>:</td>
    <td class="total"><?php echo wc_price( $already_refunded * -1, [ 'currency' => $order->get_currency() ] ); ?></td>
</tr>
<tr class="wc-gifty-refund-available" data-amount="<?php echo $available_to_refund; ?>" style="display: none;">
    <td class="label"><?php esc_html_e( 'Amount available to refund to Gifty gift cards', 'gifty-woocommerce' ); ?>:</td>
    <td class="total"><?php echo wc_price( $available_to_refund, [ 'currency' => $order->get_currency() ] ); ?></td>
</tr>

